<?php
/** Authenticated disk streaming and explicit artifact removal. */
if (!defined('ABSPATH')) {
	exit;
}

/** Shared transfers / exclusive cleanup. Stable inode; never unlink this file. */
function pgc_sgb_archives_transfer_lock($exclusive = false)
{
	$config = pgc_sgb_archives_storage_config();
	$path = $config['path'] . '/transfers.lock';
	if ($config['error'] || is_link($config['path']) || is_link($path)) {
		return false;
	}
	$handle = @fopen($path, 'c');
	if (!$handle) {
		return false;
	}
	if (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
		fclose($handle);
		return false;
	}
	return $handle;
}

/** Caller owns writer lock; cleanup itself also excludes active transfers. */
function pgc_sgb_archives_finish_removal($id, $writer)
{
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job) || !get_post_meta($id, '_pgc_sgb_archive_delete_requested', true)) {
		return $job;
	}
	$r = $job['record'];
	if ($r['deleted_at']) {
		return true;
	}
	if ($r['state'] === 'ready') {
		$changed = pgc_sgb_archives_transition($id, $r, 'invalidated');
		if (is_wp_error($changed)) {
			return $changed;
		}
		$r = $changed['record'];
	}
	$removed = pgc_sgb_archives_remove_workspace($id, $writer);
	if (is_wp_error($removed)) {
		return $removed;
	}
	$next = $r;
	if ($removed === true) {
		$next['deleted_at'] = time();
		$next['cleanup_error'] = '';
	} else {
		$next['cleanup_error'] = 'cleanup_pending';
	}
	if ($next !== $r && !update_post_meta($id, '_pgc_sgb_archive_record', $next, $r)) {
		return pgc_sgb_archives_error('archive_update_failed', __('Archive removal could not be recorded. Please retry.', 'simply-gallery-block'), 409);
	}
	if ($removed === true) {
		pgc_sgb_archives_mark_cleanup_complete($id);
	}
	return $removed;
}

function pgc_sgb_archives_delete_request($request)
{
	$id = (int) $request['id'];
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	if (in_array($job['record']['state'], array('queued', 'building'), true)) {
		return pgc_sgb_archives_error('archive_busy', __('Wait for archive creation to finish.', 'simply-gallery-block'), 409);
	}
	$writer = pgc_sgb_archives_acquire_writer(false);
	if (is_wp_error($writer)) {
		return $writer;
	}
	try {
		if (!get_post_meta($id, '_pgc_sgb_archive_delete_requested', true)
			&& !add_post_meta($id, '_pgc_sgb_archive_delete_requested', time(), true)) {
			return pgc_sgb_archives_error('archive_update_failed', __('Archive removal could not be requested.', 'simply-gallery-block'), 500);
		}
		$result = pgc_sgb_archives_finish_removal($id, $writer);
		if (is_wp_error($result)) {
			return $result;
		}
		return pgc_sgb_archives_public_status($id);
	} finally {
		pgc_sgb_archives_unlock($writer);
	}
}

function pgc_sgb_archives_register_delete_route()
{
	register_rest_route('pgc-sgb/v1', '/media-folders/archives/(?P<id>\d+)/remove', array(
		'methods' => 'POST', 'callback' => 'pgc_sgb_archives_delete_request',
		'permission_callback' => 'pgc_sgb_media_folders_can_use',
		'args' => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
	));
}
add_action('rest_api_init', 'pgc_sgb_archives_register_delete_route');

function pgc_sgb_archives_download()
{
	$id = isset($_GET['archive_id']) ? absint($_GET['archive_id']) : 0;
	check_admin_referer('pgc_sgb_archive_download_' . $id);
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		wp_die(esc_html__('Archive is unavailable.', 'simply-gallery-block'), '', array('response' => 403));
	}
	$lease = pgc_sgb_archives_transfer_lock();
	if (!$lease) {
		wp_die(esc_html__('Archive storage is busy. Please try again.', 'simply-gallery-block'), '', array('response' => 409));
	}
	$stream = false;
	try {
		// Validate after taking the lease so cleanup cannot remove the files underneath us.
		$status = pgc_sgb_archives_public_status($id);
		if (is_wp_error($status) || $status['state'] !== 'ready') {
			pgc_sgb_archives_unlock($lease);
			wp_die(esc_html__('This archive is no longer available. Open the folder to prepare it again.', 'simply-gallery-block'), '', array('response' => 410));
		}
		$config = pgc_sgb_archives_storage_config();
		$path = $config['path'] . '/' . $job['record']['artifact_key'] . '/archive.zip';
		$stream = @fopen($path, 'rb');
		if (!$stream) {
			pgc_sgb_archives_unlock($lease);
			wp_die(esc_html__('The archive could not be opened.', 'simply-gallery-block'), '', array('response' => 404));
		}
		$stat = fstat($stream);
		if (!$stat || $stat['size'] !== $status['bytes']) {
			fclose($stream);
			$stream = false;
			pgc_sgb_archives_unlock($lease);
			wp_die(esc_html__('The archive changed. Please prepare it again.', 'simply-gallery-block'), '', array('response' => 409));
		}
		// A blocked/expired new request must not affect a transfer already admitted.
		ignore_user_abort(true);
		@ini_set('zlib.output_compression', '0');
		while (ob_get_level() > 0) {
			if (!@ob_end_clean()) {
				break;
			}
		}
		if (ob_get_level() > 0 || headers_sent()) {
			return;
		}
		nocache_headers();
		header('Cache-Control: private, no-store, max-age=0');
		header('Content-Type: application/zip');
		header('X-Content-Type-Options: nosniff');
		header('Content-Length: ' . $status['bytes']);
		header('Accept-Ranges: none');
		header('Content-Disposition: attachment; filename="archive-' . $id . '.zip"; filename*=UTF-8\'\'' . rawurlencode($status['filename']));
		while (!feof($stream)) {
			$chunk = fread($stream, 1024 * 1024);
			if ($chunk === false) {
				break;
			}
			echo $chunk; // Binary response; never HTML-escape ZIP bytes.
			flush();
			if (connection_aborted()) {
				break;
			}
		}
	} finally {
		if (is_resource($stream)) {
			fclose($stream);
		}
		pgc_sgb_archives_unlock($lease);
		// Another request can revoke while this transfer runs; refresh its metadata.
		wp_cache_delete($id, 'post_meta');
		if (get_post_meta($id, '_pgc_sgb_archive_delete_requested', true)) {
			$writer = pgc_sgb_archives_acquire_writer(false);
			if (!is_wp_error($writer)) {
				try { pgc_sgb_archives_finish_removal($id, $writer); }
				finally { pgc_sgb_archives_unlock($writer); }
			}
		}
	}
	exit;
}
add_action('admin_post_pgc_sgb_archive_download', 'pgc_sgb_archives_download');
