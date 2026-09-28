<?php

/**
 * Small, cached filesystem probe. Real archive preparation must recheck storage.
 *
 * @package SimpLy Gallery Block
 */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_media_downloads_storage_context()
{
	$uploads = wp_upload_dir(null, false);
	$directory = trailingslashit($uploads['basedir']) . 'simply-media-downloads';
	return array(
		'directory' => $directory,
		'error' => !empty($uploads['error']),
		'fingerprint' => hash('sha256', wp_json_encode(array(1, pgc_sgb_media_downloads_get_capabilities(), $directory, !empty($uploads['error'])))),
	);
}

function pgc_sgb_media_downloads_get_storage_status()
{
	$context = pgc_sgb_media_downloads_storage_context();
	$saved = get_option('pgc_sgb_media_downloads_storage', array());
	if (isset($saved['fingerprint']) && $saved['fingerprint'] === $context['fingerprint']) {
		return $saved;
	}
	return array('status' => 'pending', 'reason' => '', 'checkedAt' => null);
}

function pgc_sgb_media_downloads_get_dashboard_capabilities()
{
	return array_merge(pgc_sgb_media_downloads_get_capabilities(), array('storage' => pgc_sgb_media_downloads_get_storage_status(), 'protection' => pgc_sgb_archives_protection_status(), 'limits' => pgc_sgb_archives_limits_payload()));
}

/**
 * No media files are used. Unique probe files contain only a fixed test string.
 */
function pgc_sgb_media_downloads_probe_storage($context)
{
	if (!pgc_sgb_media_downloads_has_zip_support()) {
		return 'zip_unavailable';
	}
	if ($context['error'] || !wp_mkdir_p($context['directory'])) {
		return 'directory_unavailable';
	}
	$path = trailingslashit($context['directory']) . 'probe-' . wp_generate_uuid4();
	if (!@mkdir($path, 0700)) {
		return 'directory_unwritable';
	}
	$source = $path . '/sample.txt';
	$archive = $path . '/sample.zip';
	$content = 'SimpLy ZIP storage check';
	$zip = null;
	$opened = false;
	$reason = '';
	try {
		if (@file_put_contents($source, $content) !== strlen($content)) {
			$reason = 'write_failed';
		} else {
			$zip = new ZipArchive();
			$opened = @$zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) === true;
			if (!$opened) {
				$reason = 'archive_create_failed';
			} elseif (!@$zip->addFile($source, 'sample.txt') || !@$zip->setCompressionName('sample.txt', ZipArchive::CM_STORE)) {
				$reason = 'archive_create_failed';
			} else {
				$closed = @$zip->close();
				$opened = false;
				if (!$closed) {
					$reason = 'archive_create_failed';
				} else {
					$zip = new ZipArchive();
					$opened = @$zip->open($archive, ZipArchive::CHECKCONS) === true;
					if (!$opened || $zip->numFiles !== 1 || @$zip->getFromName('sample.txt') !== $content) {
						$reason = 'archive_verify_failed';
					}
				}
			}
		}
	} catch (Throwable $error) {
		$reason = 'archive_create_failed';
	} finally {
		if ($opened) {
			try {
				@$zip->close();
			} catch (Throwable $error) {
				$reason = $reason ?: 'archive_verify_failed';
			}
		}
		$clean = true;
		foreach (array($archive, $source) as $file) {
			if (file_exists($file) && !@unlink($file)) {
				$clean = false;
			}
		}
		if (!@rmdir($path)) {
			$clean = false;
		}
		if (!$clean) {
			$reason = 'cleanup_failed';
		}
	}
	return $reason;
}

function pgc_sgb_media_downloads_check_storage($force = false)
{
	global $wpdb;
	$context = pgc_sgb_media_downloads_storage_context();
	$status = pgc_sgb_media_downloads_get_storage_status();
	if (!$force && $status['status'] !== 'pending') {
		return $status;
	}
	$lock_name = 'pgc_sgb_media_downloads_probe_lock';
	$previous = get_option($lock_name, null);
	if ($previous !== null && (int) $previous < time()) {
		// Compare-and-delete prevents removing another request's replacement lock.
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock_name, (string) $previous));
		wp_cache_delete($lock_name, 'options');
	}
	$lock = (string) (time() + 60) . ':' . wp_generate_uuid4();
	if (!add_option($lock_name, $lock, '', false)) {
		return array('status' => 'checking', 'reason' => '', 'checkedAt' => null);
	}
	try {
		$reason = pgc_sgb_media_downloads_probe_storage($context);
		$status = array(
			'fingerprint' => $context['fingerprint'],
			'status' => $reason === '' ? 'passed' : 'failed',
			'reason' => $reason,
			'checkedAt' => time(),
		);
		update_option('pgc_sgb_media_downloads_storage', $status, false);
	} finally {
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock_name, $lock));
		wp_cache_delete($lock_name, 'options');
	}
	return $status;
}

function pgc_sgb_media_downloads_maybe_check_storage()
{
	if (!wp_doing_ajax() && (current_user_can('upload_files') || current_user_can('manage_options'))) {
		pgc_sgb_media_downloads_check_storage();
		pgc_sgb_archives_check_protection();
	}
}
add_action('admin_init', 'pgc_sgb_media_downloads_maybe_check_storage', 20);

function pgc_sgb_media_downloads_recheck_storage()
{
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array(), 403);
	}
	check_ajax_referer('pgc-sgb-nonce', 'nonce');
	$status = pgc_sgb_media_downloads_check_storage(true);
	pgc_sgb_archives_check_protection(true);
	$result = pgc_sgb_media_downloads_get_dashboard_capabilities();
	$result['storage'] = $status;
	wp_send_json_success($result);
}
add_action('wp_ajax_pgc_sgb_media_downloads_recheck', 'pgc_sgb_media_downloads_recheck_storage');
