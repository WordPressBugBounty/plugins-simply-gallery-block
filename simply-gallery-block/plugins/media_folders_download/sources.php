<?php
/** Flat-folder revisions and protected source manifests. */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_archives_folder_revision($folder_id)
{
	$value = get_term_meta($folder_id, '_pgc_sgb_archive_revision', true);
	return is_string($value) && $value !== '' ? $value : 'initial';
}

function pgc_sgb_archives_touch_folder($folder_id)
{
	update_term_meta((int) $folder_id, '_pgc_sgb_archive_revision', wp_generate_uuid4());
}

function pgc_sgb_archives_touch_attachment($id)
{
	if (get_post_type($id) !== 'attachment') {
		return;
	}
	$ids = wp_get_object_terms($id, PGC_SGB_MEDIA_FOLDER_TAXONOMY, array('fields' => 'ids'));
	if (!is_wp_error($ids)) {
		foreach ($ids as $folder_id) {
			pgc_sgb_archives_touch_folder($folder_id);
		}
	}
}

function pgc_sgb_archives_relationship_changed($object_id, $tt_ids, $taxonomy)
{
	if ($taxonomy !== PGC_SGB_MEDIA_FOLDER_TAXONOMY) {
		return;
	}
	foreach ((array) $tt_ids as $tt_id) {
		$term = get_term_by('term_taxonomy_id', $tt_id, $taxonomy);
		if ($term) {
			pgc_sgb_archives_touch_folder($term->term_id);
		}
	}
}
add_action('added_term_relationship', 'pgc_sgb_archives_relationship_changed', 10, 3);
add_action('deleted_term_relationships', 'pgc_sgb_archives_relationship_changed', 10, 3);
add_action('delete_attachment', 'pgc_sgb_archives_touch_attachment');
add_action('trashed_post', 'pgc_sgb_archives_touch_attachment');
add_action('untrashed_post', 'pgc_sgb_archives_touch_attachment');

function pgc_sgb_archives_source_meta_changed($meta_id, $object_id, $meta_key)
{
	if (in_array($meta_key, array('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes'), true)) {
		pgc_sgb_archives_touch_attachment($object_id);
	}
}
foreach (array('added_post_meta', 'updated_post_meta', 'deleted_post_meta') as $hook) {
	add_action($hook, 'pgc_sgb_archives_source_meta_changed', 10, 3);
}

function pgc_sgb_archives_folder_edited($term_id, $tt_id, $taxonomy)
{
	if ($taxonomy === PGC_SGB_MEDIA_FOLDER_TAXONOMY) {
		pgc_sgb_archives_touch_folder($term_id);
	}
}
add_action('edited_term', 'pgc_sgb_archives_folder_edited', 10, 3);

/** Resolve the same original used by preflight, without remote downloads. */
function pgc_sgb_archives_resolve_source($id)
{
	if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
		return pgc_sgb_archives_error('source_forbidden', __('A file is unavailable or you do not have permission to use it.', 'simply-gallery-block'), 403);
	}
	$file = wp_attachment_is_image($id) ? wp_get_original_image_path($id) : get_attached_file($id);
	$path = is_string($file) && strpos($file, '://') === false ? realpath($file) : false;
	$uploads = wp_upload_dir(null, false);
	$root = realpath($uploads['basedir']);
	if (!$path || !$root || !is_file($path) || !is_readable($path)
		|| strpos(wp_normalize_path($path), trailingslashit(wp_normalize_path($root))) !== 0) {
		return pgc_sgb_archives_error('source_unavailable', __('An original file is missing, unreadable, or outside supported media storage.', 'simply-gallery-block'));
	}
	clearstatcache(true, $path);
	$size = @filesize($path);
	$mtime = @filemtime($path);
	if ($size === false || $size < 0 || $mtime === false) {
		return pgc_sgb_archives_error('source_stat_failed', __('An original file could not be checked.', 'simply-gallery-block'));
	}
	return array('id' => (int) $id, 'path' => $path, 'size' => $size, 'mtime' => $mtime);
}

/** Use a case-insensitive reservation set, including generated collision names. */
function pgc_sgb_archives_entry_name($path, $id, &$used)
{
	$name = sanitize_file_name(wp_basename($path));
	$name = str_replace(array('/', '\\'), '-', $name);
	if ($name === '' || $name === '.' || $name === '..') {
		$name = 'file-' . $id;
	}
	$extension = pathinfo($name, PATHINFO_EXTENSION);
	$stem = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
	$candidate = $name;
	$number = 0;
	while (isset($used[strtolower($candidate)])) {
		$number++;
		$candidate = $stem . '-' . $id . '-' . $number . ($extension !== '' ? '.' . $extension : '');
	}
	$used[strtolower($candidate)] = true;
	return $candidate;
}

/** Called by future preparation under the writer lock, not by panel preflight. */
function pgc_sgb_archives_prepare_manifest($id, $writer)
{
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	$record = $job['record'];
	$folder_id = $record['folder_id'];
	$term = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$term || is_wp_error($term)) {
		return pgc_sgb_archives_error('folder_missing', __('This folder no longer exists.', 'simply-gallery-block'), 404);
	}
	$workspace = pgc_sgb_archives_create_workspace($id, $writer);
	if (is_wp_error($workspace) || !$workspace) {
		return is_wp_error($workspace) ? $workspace : pgc_sgb_archives_error('workspace_unavailable', __('Archive workspace is unavailable.', 'simply-gallery-block'));
	}
	$revision = pgc_sgb_archives_folder_revision($folder_id);
	$limits = pgc_sgb_archives_get_limits();
	$max_files = max(1, (int) $limits['files']);
	$max_bytes = max(1, (int) $limits['bytes']);
	$entries = array();
	$used = array();
	$bytes = 0;
	$page = 1;
	do {
		$query = new WP_Query(array(
			'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 100,
			'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true,
			'update_post_term_cache' => false,
			'tax_query' => array(array('taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY, 'field' => 'term_id', 'terms' => array($folder_id), 'include_children' => false)),
		));
		foreach ($query->posts as $post) {
			if (count($entries) >= $max_files) {
				return pgc_sgb_archives_error('archive_limit', sprintf(__('This folder exceeds the current limit of %d files per archive. An administrator can adjust it under ZIP Downloads in the plugin settings.', 'simply-gallery-block'), $max_files));
			}
			$entry = pgc_sgb_archives_resolve_source($post->ID);
			if (is_wp_error($entry)) {
				return $entry;
			}
			$bytes += $entry['size'];
			if ($bytes > $max_bytes) {
				return pgc_sgb_archives_error('archive_limit', sprintf(__('This folder exceeds the current source size limit of %s MiB. An administrator can adjust it under ZIP Downloads in the plugin settings.', 'simply-gallery-block'), number_format_i18n($max_bytes / 1048576, 2)));
			}
			$entry['name'] = pgc_sgb_archives_entry_name($entry['path'], $entry['id'], $used);
			$entries[] = $entry;
		}
		$page++;
	} while ($page <= $query->max_num_pages);
	if (!$entries) {
		return pgc_sgb_archives_error('archive_empty', __('This folder has no files to download.', 'simply-gallery-block'));
	}
	if ($revision !== pgc_sgb_archives_folder_revision($folder_id)) {
		return pgc_sgb_archives_error('source_changed', __('The folder changed during preparation. Please try again.', 'simply-gallery-block'), 409);
	}
	$manifest = array('version' => 1, 'job_id' => (int) $id, 'folder_id' => $folder_id, 'revision' => $revision, 'entries' => $entries);
	$json = wp_json_encode($manifest);
	$path = $workspace['path'] . '/manifest.json';
	if (!$json || file_exists($path) || is_link($path)) {
		return pgc_sgb_archives_error('manifest_exists', __('Archive manifest could not be created.', 'simply-gallery-block'), 409);
	}
	$handle = @fopen($path, 'x');
	if (!$handle) {
		return pgc_sgb_archives_error('manifest_write_failed', __('Archive manifest could not be saved.', 'simply-gallery-block'), 500);
	}
	$written = fwrite($handle, $json);
	$flushed = fflush($handle);
	fclose($handle);
	if ($written !== strlen($json) || !$flushed) {
		@unlink($path);
		return pgc_sgb_archives_error('manifest_write_failed', __('Archive manifest could not be saved.', 'simply-gallery-block'), 500);
	}
	$result = pgc_sgb_archives_transition($id, $record, 'building', array(
		'source_revision' => $revision, 'manifest_reference' => $workspace['key'],
		'manifest_fingerprint' => hash('sha256', $json), 'source_count' => count($entries), 'source_bytes' => $bytes,
	));
	if (is_wp_error($result)) {
		@unlink($path);
	}
	return $result;
}

/** Revalidate before building/reusing; never pass this manifest to a public API. */
function pgc_sgb_archives_validate_manifest($id)
{
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	$record = $job['record'];
	$key = $record['manifest_reference'];
	if ($job['source_current'] !== true || !preg_match('/\Ajob-' . (int) $id . '-[a-f0-9]{32}\z/', $key)) {
		return pgc_sgb_archives_error('source_changed', __('The archive contents need to be prepared again.', 'simply-gallery-block'), 409);
	}
	$config = pgc_sgb_archives_storage_config();
	$directory = $config['path'] . '/' . $key;
	$path = $directory . '/manifest.json';
	if ($config['error'] || is_link($config['path']) || is_link($directory) || is_link($path) || !is_file($path) || filesize($path) > 16 * 1024 * 1024) {
		return pgc_sgb_archives_error('manifest_unavailable', __('Archive manifest is unavailable.', 'simply-gallery-block'));
	}
	$json = @file_get_contents($path);
	if (!is_string($json) || !hash_equals($record['manifest_fingerprint'], hash('sha256', $json))) {
		return pgc_sgb_archives_error('manifest_invalid', __('Archive manifest could not be verified.', 'simply-gallery-block'));
	}
	$manifest = json_decode($json, true);
	if (!is_array($manifest) || !isset($manifest['entries'], $manifest['job_id']) || $manifest['job_id'] !== (int) $id || count($manifest['entries']) !== $record['source_count']) {
		return pgc_sgb_archives_error('manifest_invalid', __('Archive manifest could not be verified.', 'simply-gallery-block'));
	}
	foreach ($manifest['entries'] as $entry) {
		$current = pgc_sgb_archives_resolve_source($entry['id']);
		if (is_wp_error($current)) {
			return $current;
		}
		// get_post_status() resolves attachment inheritance to the parent/public status.
		if (get_post_field('post_status', $entry['id'], 'raw') !== 'inherit' || !has_term($record['folder_id'], PGC_SGB_MEDIA_FOLDER_TAXONOMY, $entry['id'])
			|| $current['path'] !== $entry['path'] || $current['size'] !== $entry['size'] || $current['mtime'] !== $entry['mtime']) {
			return pgc_sgb_archives_error('source_changed', __('An original file changed. Prepare the archive again.', 'simply-gallery-block'), 409);
		}
	}
	if ($record['source_revision'] !== pgc_sgb_archives_folder_revision($record['folder_id'])) {
		return pgc_sgb_archives_error('source_changed', __('The folder changed during validation.', 'simply-gallery-block'), 409);
	}
	return $manifest;
}
