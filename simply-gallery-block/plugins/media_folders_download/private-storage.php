<?php
/** Protected archive storage and process-held writer exclusion. */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_archives_storage_config()
{
	$uploads = wp_upload_dir(null, false);
	return array(
		'path' => trailingslashit($uploads['basedir']) . 'simply-media-downloads/private',
		'url' => trailingslashit($uploads['baseurl']) . 'simply-media-downloads/private',
		'error' => !empty($uploads['error']) || is_link(trailingslashit($uploads['basedir']) . 'simply-media-downloads'),
	);
}

function pgc_sgb_archives_protection_status()
{
	$config = pgc_sgb_archives_storage_config();
	$saved = get_option('pgc_sgb_archive_protection', array());
	$fingerprint = hash('sha256', wp_json_encode(array(1, $config)));
	if (isset($saved['fingerprint'], $saved['checkedAt']) && $saved['fingerprint'] === $fingerprint
		&& $saved['checkedAt'] > time() - DAY_IN_SECONDS
		&& ($saved['status'] !== 'passed' || (isset($saved['policyHash']) && !is_link($config['path']) && !is_link($config['path'] . '/.htaccess')
			&& is_file($config['path'] . '/.htaccess') && @hash_file('sha256', $config['path'] . '/.htaccess') === $saved['policyHash']))) {
		return $saved;
	}
	return array('status' => 'pending', 'reason' => '', 'checkedAt' => null);
}

/**
 * Never unlink the lock file: another process may still have the inode open.
 * Keep the returned resource alive for the entire critical section.
 */
function pgc_sgb_archives_lock($path)
{
	if (is_link($path)) {
		return false;
	}
	$handle = @fopen($path, 'c');
	if (!$handle) {
		return false;
	}
	if (!flock($handle, LOCK_EX | LOCK_NB)) {
		fclose($handle);
		return false;
	}
	return $handle;
}

function pgc_sgb_archives_unlock($handle)
{
	if (is_resource($handle)) {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
}

function pgc_sgb_archives_check_protection($force = false)
{
	$status = pgc_sgb_archives_protection_status();
	if (!$force && $status['status'] !== 'pending') {
		return $status;
	}
	$config = pgc_sgb_archives_storage_config();
	$reason = 'directory_unavailable';
	$lock = false;
	$files = array();
	$probe_dir = null;
	try {
		if (!$config['error'] && !is_link($config['path']) && wp_mkdir_p($config['path'])) {
			$lock = pgc_sgb_archives_lock($config['path'] . '/protection.lock');
			if (!$lock) {
				return array('status' => 'checking', 'reason' => '', 'checkedAt' => null);
			}
			// Manage only our own policy file. A different file requires manual review.
			$rules = "# SimpLy archive storage\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			$policy = $config['path'] . '/.htaccess';
			$existing = is_file($policy) ? @file_get_contents($policy) : false;
			$reason = 'policy_write_failed';
			if (!is_link($policy) && (($existing === false && @file_put_contents($policy, $rules) === strlen($rules)) || $existing === $rules)) {
				$token = bin2hex(random_bytes(16));
				$control = dirname($config['path']) . '/control-' . $token . '.txt';
				$probe_dir = $config['path'] . '/probe-' . $token;
				$files[] = $control;
				$reason = 'probe_write_failed';
				if (@mkdir($probe_dir, 0700) && @file_put_contents($control, $token) === strlen($token)) {
					$reason = '';
					foreach (array('sample.zip', 'manifest.json') as $name) {
						$file = $probe_dir . '/' . $name;
						$files[] = $file;
						if (@file_put_contents($file, $token) !== strlen($token)) {
							$reason = 'probe_write_failed';
							break;
						}
					}
					if ($reason === '') {
						$args = array('timeout' => 3, 'redirection' => 0, 'limit_response_size' => 1024, 'headers' => array('Cache-Control' => 'no-cache'));
						$control_url = dirname($config['url']) . '/control-' . $token . '.txt';
						$response = wp_remote_get($control_url, $args);
						if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || wp_remote_retrieve_body($response) !== $token) {
							$reason = 'http_check_unavailable';
						} else {
							foreach (array('sample.zip', 'manifest.json') as $name) {
								$response = wp_remote_get($config['url'] . '/probe-' . $token . '/' . $name, $args);
								if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 403) {
									$reason = 'direct_access_not_denied';
									break;
								}
							}
						}
					}
				}
			}
		}
	} catch (Throwable $error) {
		$reason = 'protection_check_failed';
	} finally {
		foreach ($files as $file) {
			if (file_exists($file) && !@unlink($file)) {
				$reason = 'probe_cleanup_failed';
			}
		}
		if ($probe_dir && is_dir($probe_dir) && !@rmdir($probe_dir)) {
			$reason = 'probe_cleanup_failed';
		}
		pgc_sgb_archives_unlock($lock);
	}
	$status = array('status' => $reason === '' ? 'passed' : 'failed', 'reason' => $reason, 'checkedAt' => time(), 'fingerprint' => hash('sha256', wp_json_encode(array(1, $config))));
	$status['policyHash'] = is_file($config['path'] . '/.htaccess') ? @hash_file('sha256', $config['path'] . '/.htaccess') : '';
	update_option('pgc_sgb_archive_protection', $status, false);
	return $status;
}

/** Track the exact writer handle issued in this PHP request. */
function pgc_sgb_archives_writer_handle($set = null)
{
	static $handle = null;
	if (is_resource($set)) {
		$handle = $set;
	}
	return $handle;
}

/** Return a lock resource or error; caller must release in finally. */
function pgc_sgb_archives_acquire_writer($require_protection = true)
{
	$status = pgc_sgb_archives_protection_status();
	if ($require_protection && $status['status'] !== 'passed') {
		return pgc_sgb_archives_error('archive_storage_unprotected', __('Protected archive storage is not ready.', 'simply-gallery-block'), 409);
	}
	$config = pgc_sgb_archives_storage_config();
	if ($config['error'] || is_link($config['path'])) {
		return pgc_sgb_archives_error('archive_storage_invalid', __('Archive storage is unavailable.', 'simply-gallery-block'));
	}
	$handle = pgc_sgb_archives_lock($config['path'] . '/writer.lock');
	if ($handle && $require_protection && pgc_sgb_archives_maintenance_state(true)['purge']) {
		pgc_sgb_archives_unlock($handle);
		return pgc_sgb_archives_error('archive_maintenance', __('Archive cleanup is in progress. Please try again later.', 'simply-gallery-block'), 409);
	}
	if ($handle) {
		pgc_sgb_archives_writer_handle($handle);
	}
	return $handle ?: pgc_sgb_archives_error('archive_writer_unavailable', __('Another archive operation is running, or storage cannot be locked.', 'simply-gallery-block'), 409);
}

/** Caller holds writer lock; operation key is persisted before directory creation. */
function pgc_sgb_archives_create_workspace($id, $writer)
{
	if (!is_resource($writer) || $writer !== pgc_sgb_archives_writer_handle()) {
		return pgc_sgb_archives_error('archive_lock_required', __('Archive storage lock is required.', 'simply-gallery-block'));
	}
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	if ($job['record']['state'] !== 'queued') {
		return pgc_sgb_archives_error('archive_workspace_state', __('Archive operation has already started.', 'simply-gallery-block'), 409);
	}
	$key = get_post_meta($id, '_pgc_sgb_archive_workspace', true);
	if (!$key) {
		$key = 'job-' . $id . '-' . bin2hex(random_bytes(16));
		if (!add_post_meta($id, '_pgc_sgb_archive_workspace', $key, true)) {
			return pgc_sgb_archives_error('archive_workspace_write_failed', __('Archive workspace could not be recorded.', 'simply-gallery-block'), 500);
		}
	}
	if (!preg_match('/\Ajob-' . (int) $id . '-[a-f0-9]{32}\z/', $key)) {
		return pgc_sgb_archives_error('archive_workspace_invalid', __('Archive workspace is invalid.', 'simply-gallery-block'));
	}
	$config = pgc_sgb_archives_storage_config();
	$path = $config['path'] . '/' . $key;
	if ($config['error'] || is_link($config['path'])) {
		return false;
	}
	if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700))) {
		return pgc_sgb_archives_error('archive_workspace_unwritable', __('Archive workspace could not be created.', 'simply-gallery-block'), 500);
	}
	return array('key' => $key, 'path' => $path);
}

/** No recursive deletion: unknown files or symlinks require explicit recovery. */
function pgc_sgb_archives_remove_workspace($id, $writer)
{
	$lease = pgc_sgb_archives_transfer_lock(true);
	if (!$lease) {
		return false;
	}
	try {
		return pgc_sgb_archives_remove_workspace_files($id, $writer);
	} finally {
		pgc_sgb_archives_unlock($lease);
	}
}

function pgc_sgb_archives_remove_workspace_files($id, $writer)
{
	$job = pgc_sgb_archives_get($id);
	if (is_wp_error($job)) {
		return $job;
	}
	if (!is_resource($writer) || $writer !== pgc_sgb_archives_writer_handle() || !in_array($job['record']['state'], array('failed', 'invalidated', 'expired'), true)) {
		return false;
	}
	$key = get_post_meta($id, '_pgc_sgb_archive_workspace', true);
	if (!is_string($key) || !preg_match('/\Ajob-' . (int) $id . '-[a-f0-9]{32}\z/', $key)) {
		return false;
	}
	$config = pgc_sgb_archives_storage_config();
	$path = $config['path'] . '/' . $key;
	if ($config['error'] || is_link($config['path'])) {
		return false;
	}
	if (is_link($path)) {
		return false;
	}
	if (!file_exists($path)) {
		return true;
	}
	$entries = @scandir($path);
	if ($entries === false) {
		return false;
	}
	foreach (array_diff($entries, array('.', '..')) as $name) {
		if (!in_array($name, array('manifest.json', 'archive.partial', 'archive.zip'), true) || is_link($path . '/' . $name) || !is_file($path . '/' . $name)) {
			return false;
		}
	}
	foreach (array_diff($entries, array('.', '..')) as $name) {
		if (!@unlink($path . '/' . $name)) {
			return false;
		}
	}
	return @rmdir($path);
}
