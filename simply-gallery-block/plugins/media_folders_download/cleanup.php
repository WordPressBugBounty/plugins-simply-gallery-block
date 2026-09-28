<?php
/** Current-site bounded maintenance. Internal APIs never impersonate archive owners. */
if (!defined('ABSPATH')) { exit; }

function pgc_sgb_archives_maintenance_state($fresh = false)
{
	if ($fresh) {
		wp_cache_delete('pgc_sgb_archive_maintenance', 'options');
		wp_cache_delete('notoptions', 'options');
	}
	return array_merge(array('needed' => true, 'next_at' => 0, 'phase' => 'records', 'cursor' => 0,
		'offset' => 0, 'keep' => false, 'errors' => 0, 'removed' => 0, 'purge' => false,
		'orphan_at' => 0, 'status' => 'idle'), (array) get_option('pgc_sgb_archive_maintenance', array()));
}

function pgc_sgb_archives_mark_maintenance()
{
	$s = pgc_sgb_archives_maintenance_state();
	$s['needed'] = true;
	$s['keep'] = true;
	update_option('pgc_sgb_archive_maintenance', $s, false);
}

/** Searchable completion marker; the full journal snapshot remains unchanged. */
function pgc_sgb_archives_mark_cleanup_complete($id)
{
	return update_post_meta($id, '_pgc_sgb_archive_cleanup_complete', 1)
		|| (int) get_post_meta($id, '_pgc_sgb_archive_cleanup_complete', true) === 1;
}

/** Called only under writer + exclusive transfer locks. Never traverse recursively. */
function pgc_sgb_archives_cleanup_directory($key)
{
	if (!preg_match('/\Ajob-[1-9][0-9]*-[a-f0-9]{32}\z/', $key)) { return false; }
	$c = pgc_sgb_archives_storage_config();
	$path = $c['path'] . '/' . $key;
	if ($c['error'] || is_link($c['path']) || is_link($path)) { return false; }
	if (!file_exists($path)) { return true; }
	if (!is_dir($path)) { return false; }
	$directory = @opendir($path);
	if (!$directory) { return false; }
	$names = array();
	try {
		while (false !== ($name = readdir($directory))) {
			if ($name === '.' || $name === '..') { continue; }
			if (!in_array($name, array('archive.zip', 'archive.partial', 'manifest.json'), true)
				|| is_link($path . '/' . $name) || !is_file($path . '/' . $name)) { return false; }
			$names[] = $name;
		}
	} finally { closedir($directory); }
	foreach ($names as $name) { if (!@unlink($path . '/' . $name)) { return false; } }
	return @rmdir($path);
}

/** Returns keep/removed/error. Lock ownership ensures queued/building jobs are abandoned. */
function pgc_sgb_archives_cleanup_record($id, $purge, $can_remove)
{
	$r = get_post_meta($id, '_pgc_sgb_archive_record', true);
	if (!is_array($r) || ($r['schema_version'] ?? null) !== 1) { return 'error'; }
	$key = get_post_meta($id, '_pgc_sgb_archive_workspace', true);
	$next = $r;
	$terminal = in_array($r['state'], array('failed', 'invalidated', 'expired'), true);
	$delete_requested = get_post_meta($id, '_pgc_sgb_archive_delete_requested', true);
	if ($purge || $delete_requested) {
		$next['state'] = 'invalidated';
	} elseif (in_array($r['state'], array('queued', 'building'), true)) {
		$next['state'] = 'failed';
		$next['error_code'] = 'archive_interrupted';
	} elseif ($r['state'] === 'ready') {
		$folder = get_term($r['folder_id'], PGC_SGB_MEDIA_FOLDER_TAXONOMY);
		if (!empty($r['expires_at']) && $r['expires_at'] <= time()) {
			$next['state'] = 'expired';
		} elseif (!$folder || is_wp_error($folder) || $r['source_revision'] !== pgc_sgb_archives_folder_revision($r['folder_id'])) {
			$next['state'] = 'invalidated';
		} elseif (empty($r['deleted_at'])) {
			return 'keep';
		}
	} elseif (!$terminal && empty($r['deleted_at'])) { return 'error'; }
	// Persist revocation even if readers prevent physical removal this pass.
	if ($next !== $r && !update_post_meta($id, '_pgc_sgb_archive_record', wp_slash($next), $r)) { return 'error'; }
	$r = $next;
	if (!$can_remove) { return 'keep'; }
	$c = pgc_sgb_archives_storage_config();
	if ($key === '' && empty($r['manifest_reference']) && empty($r['artifact_key'])) {
		$removed = true; // Creation failed before allocating a workspace.
	} elseif (is_string($key) && preg_match('/\Ajob-' . (int) $id . '-[a-f0-9]{32}\z/', $key)) {
		$removed = pgc_sgb_archives_cleanup_directory($key);
	} else { $removed = false; }
	$next['cleanup_error'] = $removed ? '' : 'cleanup_failed';
	if ($removed && empty($next['deleted_at'])) { $next['deleted_at'] = time(); }
	if ($next !== $r && !update_post_meta($id, '_pgc_sgb_archive_record', wp_slash($next), $r)) { return 'error'; }
	if (!$removed) { return 'error'; }
	if (!pgc_sgb_archives_mark_cleanup_complete($id)) { return 'error'; }
	return empty($r['deleted_at']) ? 'removed' : 'gone';
}

/** One pass: <=25 records / 100 directory entries, ~2s of cooperative work. */
function pgc_sgb_archives_cleanup_run($trigger = 'screen')
{
	global $wpdb;
	$s = pgc_sgb_archives_maintenance_state();
	if ($trigger === 'screen' && (!$s['needed'] || $s['next_at'] > time())) { return $s; }
	if ($trigger === 'build' && !$s['needed']) { return $s; }
	$c = pgc_sgb_archives_storage_config();
	if ($c['error'] || is_link($c['path'])) {
		$s['status'] = 'error'; $s['needed'] = true; $s['next_at'] = time() + HOUR_IN_SECONDS;
		update_option('pgc_sgb_archive_maintenance', $s, false); return $s;
	}
	// No artifacts can exist here if the directory does not exist; no directory creation for idle sites.
	if (!file_exists($c['path'])) {
		$s['needed'] = false; $s['purge'] = false; $s['status'] = 'complete';
		update_option('pgc_sgb_archive_maintenance', $s, false); return $s;
	}
	$writer = pgc_sgb_archives_acquire_writer(false);
	if (is_wp_error($writer)) { $s['status'] = 'waiting'; return $s; }
	$lease = false;
	try {
		// Reload after locking: another request may have started a purge or created a job.
		$s = pgc_sgb_archives_maintenance_state(true);
		if ($trigger === 'screen' && (!$s['needed'] || $s['next_at'] > time())) { return $s; }
		if ($trigger === 'start' && !$s['purge']) {
			$s = array_merge($s, array('needed' => true, 'purge' => true, 'phase' => 'records', 'cursor' => 0, 'offset' => 0, 'keep' => false, 'errors' => 0, 'removed' => 0));
			// Durable maintenance flag revokes access and blocks builders across requests.
			update_option('pgc_sgb_archive_maintenance', $s, false);
		}
		$s['next_at'] = time() + HOUR_IN_SECONDS;
		$s['status'] = 'running';
		$deadline = microtime(true) + 2;
		$lease = pgc_sgb_archives_transfer_lock(true);
		if ($s['phase'] === 'records') {
			// Completed history must not consume the hourly work budget. Legacy records
			// receive the marker on their first verified cleanup after this upgrade.
			$ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.ID > %d AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID
					AND m.meta_key = '_pgc_sgb_archive_cleanup_complete' AND m.meta_value = '1'
				) ORDER BY p.ID ASC LIMIT 25", 'pgc_sgb_archive', $s['cursor']));
			if ($wpdb->last_error) { $s['errors']++; $s['keep'] = true; }
			foreach ($ids as $id) {
				if (microtime(true) >= $deadline) { break; }
				$result = pgc_sgb_archives_cleanup_record((int) $id, $s['purge'], (bool) $lease);
				$s['cursor'] = (int) $id;
				if ($result === 'removed') { $s['removed']++; }
				if ($result === 'error') { $s['errors']++; }
				if ($result === 'error' || $result === 'keep') { $s['keep'] = true; }
			}
			if (!$ids && !$wpdb->last_error) {
				$s['phase'] = ($s['purge'] || $s['orphan_at'] <= time() || !$s['keep']) ? 'directories' : 'finish';
			}
		}
		if ($s['phase'] === 'directories' && $lease && microtime(true) < $deadline) {
			// Directory offsets avoid loading all names. After deletions restart a verification sweep.
			$iterator = new DirectoryIterator($c['path']);
			$iterator->seek((int) $s['offset']);
			$count = 0;
			$deleted_directory = false;
			while ($iterator->valid() && $count < 100 && microtime(true) < $deadline) {
				$name = $iterator->getFilename();
				$s['offset']++; $count++;
				if (preg_match('/\Ajob-([1-9][0-9]*)-[a-f0-9]{32}\z/', $name, $match)) {
					$id = (int) $match[1];
					$post = get_post($id);
					$linked = $post && $post->post_type === 'pgc_sgb_archive' && get_post_meta($id, '_pgc_sgb_archive_workspace', true) === $name;
					if (!$linked) {
						if (pgc_sgb_archives_cleanup_directory($name)) { $s['removed']++; $deleted_directory = true; }
						else { $s['errors']++; $s['keep'] = true; }
					} else {
						$result = pgc_sgb_archives_cleanup_record($id, $s['purge'], true);
						if ($result === 'removed') { $s['removed']++; $deleted_directory = true; }
						elseif ($result === 'gone') { $deleted_directory = true; }
						else { $s['keep'] = true; }
						if ($result === 'error') { $s['errors']++; }
					}
				} elseif (!in_array($name, array('.', '..', '.htaccess', 'writer.lock', 'transfers.lock', 'protection.lock'), true)) {
					$s['errors']++; $s['keep'] = true;
				}
				$iterator->next();
			}
			if ($deleted_directory) { $s['offset'] = 0; }
			elseif (!$iterator->valid()) { $s['phase'] = 'finish'; $s['orphan_at'] = time() + DAY_IN_SECONDS; }
		} elseif ($s['phase'] === 'directories' && !$lease) { $s['keep'] = true; $s['status'] = 'waiting'; }
		if ($s['phase'] === 'finish') {
			$was_purge = $s['purge'];
			$s['status'] = $s['errors'] ? 'error' : ($was_purge && $s['keep'] ? ($lease ? 'running' : 'waiting') : 'complete');
			// A waiting purge resumes on later requests. Failed items are revoked and retryable.
			$s['purge'] = $was_purge && $s['keep'] && !$s['errors'];
			$s['needed'] = $s['keep'] || $s['errors'] > 0;
			$s['phase'] = 'records'; $s['cursor'] = 0; $s['offset'] = 0; $s['keep'] = false;
			$s['errors'] = 0;
		}
		update_option('pgc_sgb_archive_maintenance', $s, false);
		return $s;
	} catch (Throwable $error) {
		$s['status'] = 'error'; $s['needed'] = true; $s['next_at'] = time() + HOUR_IN_SECONDS;
		update_option('pgc_sgb_archive_maintenance', $s, false); return $s;
	} finally {
		pgc_sgb_archives_unlock($lease);
		pgc_sgb_archives_unlock($writer);
	}
}

function pgc_sgb_archives_cleanup_ajax()
{
	if (!current_user_can('manage_options')) { wp_send_json_error(array(), 403); }
	check_ajax_referer('pgc-sgb-nonce', 'nonce');
	$action = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'status';
	if ($action === 'start' || $action === 'continue') {
		$s = pgc_sgb_archives_cleanup_run($action);
	} else { $s = pgc_sgb_archives_maintenance_state(); }
	wp_send_json_success(array('status' => $s['status'], 'active' => $s['purge'], 'removed' => $s['removed']));
}
add_action('wp_ajax_pgc_sgb_archive_cleanup', 'pgc_sgb_archives_cleanup_ajax');
