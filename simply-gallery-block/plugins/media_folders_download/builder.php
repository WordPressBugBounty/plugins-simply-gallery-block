<?php
/** Bounded Free ZIP creation and owner-only status API. No file delivery. */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_archives_public_status($id)
{
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	$r = $job['record'];
	$state = $r['state'];
	if (!empty($r['deleted_at'])) {
		$state = 'deleted';
	} elseif (pgc_sgb_archives_maintenance_state()['purge'] || get_post_meta($id, '_pgc_sgb_archive_delete_requested', true)) {
		$state = 'deleting';
	}
	if ($state === 'ready') {
		if ($job['source_current'] !== true) {
			$state = 'invalidated';
		} elseif ($r['expires_at'] <= time()) {
			$state = 'expired';
		} else {
			$manifest = pgc_sgb_archives_validate_manifest($id);
			$config = pgc_sgb_archives_storage_config();
			$artifact = $config['path'] . '/' . $r['artifact_key'] . '/archive.zip';
			if (is_wp_error($manifest) || !preg_match('/\Ajob-' . (int) $id . '-[a-f0-9]{32}\z/', $r['artifact_key'])
				|| is_link($artifact) || !is_file($artifact) || @filesize($artifact) !== $r['artifact_bytes']) {
				$state = 'invalidated';
			}
		}
	}
	return array('id' => (int) $id, 'state' => $state, 'filename' => $r['download_name'] ?? pgc_sgb_archives_download_name($r['folder_label']), 'fileCount' => $r['source_count'], 'bytes' => $r['artifact_bytes'], 'expiresAt' => $r['expires_at'], 'remainingSeconds' => max(0, $r['expires_at'] - time()), 'downloadUrl' => $state === 'ready' ? add_query_arg(array('action' => 'pgc_sgb_archive_download', 'archive_id' => $id, '_wpnonce' => wp_create_nonce('pgc_sgb_archive_download_' . $id)), admin_url('admin-post.php')) : '', 'errorCode' => $r['error_code']);
}

function pgc_sgb_archives_latest($folder_id, $token = '')
{
	$meta = array(array('key' => '_pgc_sgb_archive_folder', 'value' => (int) $folder_id));
	if ($token !== '') {
		$meta[] = array('key' => '_pgc_sgb_archive_request', 'value' => $token);
	}
	$query = new WP_Query(array('post_type' => 'pgc_sgb_archive', 'post_status' => 'private', 'author' => get_current_user_id(), 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta));
	return $query->posts ? (int) $query->posts[0] : 0;
}

function pgc_sgb_archives_fail($id, $code)
{
	$job = pgc_sgb_archives_get($id);
	if (!is_wp_error($job) && in_array($job['record']['state'], array('queued', 'building'), true)) {
		return pgc_sgb_archives_transition($id, $job['record'], 'failed', array('error_code' => sanitize_key($code)));
	}
	return $job;
}

function pgc_sgb_archives_build($id, $writer)
{
	$zip = null;
	$opened = false;
	$ready = false;
	try {
		$result = pgc_sgb_archives_prepare_manifest($id, $writer);
		if (is_wp_error($result)) {
			pgc_sgb_archives_fail($id, $result->get_error_code());
			return $result;
		}
		$manifest = pgc_sgb_archives_validate_manifest($id);
		if (is_wp_error($manifest)) {
			pgc_sgb_archives_fail($id, $manifest->get_error_code());
			return $manifest;
		}
		$config = pgc_sgb_archives_storage_config();
		$key = $result['record']['manifest_reference'];
		$directory = $config['path'] . '/' . $key;
		$partial = $directory . '/archive.partial';
		$target = $directory . '/archive.zip';
		if (file_exists($partial) || is_link($partial) || file_exists($target) || is_link($target)) {
			throw new RuntimeException('archive_path_conflict');
		}
		$zip = new ZipArchive();
		$opened = @$zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) === true;
		if (!$opened) {
			throw new RuntimeException('archive_create_failed');
		}
		foreach ($manifest['entries'] as $entry) {
			if (!@$zip->addFile($entry['path'], $entry['name']) || !@$zip->setCompressionName($entry['name'], ZipArchive::CM_STORE)) {
				throw new RuntimeException('archive_add_failed');
			}
		}
		$closed = @$zip->close();
		$opened = false;
		if (!$closed) {
			throw new RuntimeException('archive_close_failed');
		}
		$zip = new ZipArchive();
		$opened = @$zip->open($partial, ZipArchive::CHECKCONS) === true;
		if (!$opened || $zip->numFiles !== count($manifest['entries'])) {
			throw new RuntimeException('archive_verify_failed');
		}
		foreach ($manifest['entries'] as $index => $entry) {
			$stat = $zip->statIndex($index);
			if (!$stat || $stat['name'] !== $entry['name'] || $stat['size'] !== $entry['size']) {
				throw new RuntimeException('archive_verify_failed');
			}
		}
		$zip->close();
		$opened = false;
		$again = pgc_sgb_archives_validate_manifest($id);
		if (is_wp_error($again)) {
			pgc_sgb_archives_fail($id, $again->get_error_code());
			return $again;
		}
		$size = @filesize($partial);
		if (!$size || !@rename($partial, $target)) {
			throw new RuntimeException('archive_publish_failed');
		}
		$job = pgc_sgb_archives_get($id);
		if (is_wp_error($job)) {
			return $job;
		}
		$done = pgc_sgb_archives_transition($id, $job['record'], 'ready', array('artifact_key' => $key, 'artifact_bytes' => $size, 'verified_at' => time()));
		$ready = !is_wp_error($done);
		if (!$ready) {
			pgc_sgb_archives_fail($id, $done->get_error_code());
		}
		return $ready ? pgc_sgb_archives_public_status($id) : $done;
	} catch (Throwable $error) {
		$code = $error instanceof RuntimeException ? $error->getMessage() : 'archive_build_failed';
		pgc_sgb_archives_fail($id, $code);
		return pgc_sgb_archives_error('archive_build_failed', __('The ZIP could not be created. Check available storage and try again.', 'simply-gallery-block'), 500);
	} finally {
		if ($opened && $zip) {
			try { @$zip->close(); } catch (Throwable $ignored) { /* Cleanup below. */ }
		}
		$zip = null;
		if (!$ready) {
			pgc_sgb_archives_remove_workspace($id, $writer);
		}
	}
}

function pgc_sgb_archives_prepare_request($request)
{
	pgc_sgb_archives_cleanup_run('build');
	$caps = pgc_sgb_media_downloads_get_ui_capabilities();
	if (!$caps['canPrepare']) {
		return pgc_sgb_archives_error('archive_unavailable', __('Check ZIP Downloads in the plugin settings before preparing an archive.', 'simply-gallery-block'), 409);
	}
	$writer = pgc_sgb_archives_acquire_writer();
	if (is_wp_error($writer)) {
		return $writer;
	}
	try {
		$folder_id = (int) $request['id'];
		$token = $request['request_token'];
		$existing = pgc_sgb_archives_latest($folder_id, $token);
		if ($existing) {
			pgc_sgb_archives_fail($existing, 'archive_interrupted');
			return pgc_sgb_archives_public_status($existing);
		}
		$latest = pgc_sgb_archives_latest($folder_id);
		if ($latest) {
			$status = pgc_sgb_archives_public_status($latest);
			if (!is_wp_error($status) && $status['state'] === 'ready') {
				return $status;
			}
			if (!is_wp_error($status) && in_array($status['state'], array('expired', 'invalidated'), true)) {
				$previous = pgc_sgb_archives_get($latest);
				if (!is_wp_error($previous) && $previous['record']['state'] === 'ready') {
					pgc_sgb_archives_transition($latest, $previous['record'], $status['state']);
				}
			}
			pgc_sgb_archives_fail($latest, 'archive_interrupted');
			pgc_sgb_archives_remove_workspace($latest, $writer);
		}
		$job = pgc_sgb_archives_create($folder_id);
		if (is_wp_error($job)) {
			return $job;
		}
		$id = $job['id'];
		if (!add_post_meta($id, '_pgc_sgb_archive_folder', $folder_id, true) || !add_post_meta($id, '_pgc_sgb_archive_request', $token, true)) {
			pgc_sgb_archives_fail($id, 'archive_record_write_failed');
			return pgc_sgb_archives_error('archive_record_write_failed', __('Archive operation could not be saved.', 'simply-gallery-block'), 500);
		}
		// Fatal shutdown can leave partial files; retain a diagnosable record for cleanup.
		register_shutdown_function(function () use ($id) {
			$last = error_get_last();
			if ($last && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
				pgc_sgb_archives_fail($id, 'archive_interrupted');
			}
		});
		return pgc_sgb_archives_build($id, $writer);
	} finally {
		pgc_sgb_archives_unlock($writer);
	}
}

function pgc_sgb_archives_status_request($request)
{
	$id = pgc_sgb_archives_latest((int) $request['id']);
	if (!$id) {
		return array('state' => 'none');
	}
	$status = pgc_sgb_archives_public_status($id);
	if (!is_wp_error($status) && $status['state'] === 'deleting') {
		$writer = pgc_sgb_archives_acquire_writer(false);
		if (!is_wp_error($writer)) {
			try {
				pgc_sgb_archives_finish_removal($id, $writer);
				$status = pgc_sgb_archives_public_status($id);
			} finally {
				pgc_sgb_archives_unlock($writer);
			}
		}
	}
	if (!is_wp_error($status) && in_array($status['state'], array('queued', 'building'), true)) {
		$writer = pgc_sgb_archives_acquire_writer();
		if (!is_wp_error($writer)) {
			try {
				pgc_sgb_archives_fail($id, 'archive_interrupted');
				pgc_sgb_archives_remove_workspace($id, $writer);
				$status = pgc_sgb_archives_public_status($id);
			} finally {
				pgc_sgb_archives_unlock($writer);
			}
		}
	}
	return $status;
}

function pgc_sgb_archives_register_build_routes()
{
	register_rest_route('pgc-sgb/v1', '/media-folders/folders/(?P<id>\d+)/archive', array(
		array('methods' => 'POST', 'callback' => 'pgc_sgb_archives_prepare_request', 'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args' => array('id' => array('type' => 'integer', 'minimum' => 1), 'request_token' => array('type' => 'string', 'required' => true, 'pattern' => '^[a-zA-Z0-9_-]{16,80}$'))),
		array('methods' => 'GET', 'callback' => 'pgc_sgb_archives_status_request', 'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args' => array('id' => array('type' => 'integer', 'minimum' => 1))),
	));
}
add_action('rest_api_init', 'pgc_sgb_archives_register_build_routes');
