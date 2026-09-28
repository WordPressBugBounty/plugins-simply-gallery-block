<?php

/**
 * Shared ZIP capability detection for Media Folders downloads.
 *
 * Includes a small filesystem probe, separate from actual media downloads.
 *
 * @package SimpLy Gallery Block
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read the current PHP runtime, never a persisted capability snapshot.
 *
 * Results are memoized only for the lifetime of this request.
 */
function pgc_sgb_media_downloads_get_capabilities()
{
	static $capabilities = null;

	if ($capabilities !== null) {
		return $capabilities;
	}

	$extension_loaded = extension_loaded('zip');
	$class_available = class_exists('ZipArchive', false);
	$missing_methods = array();
	$missing_constants = array();

	if ($class_available) {
		$required_methods = array('open', 'addFile', 'addFromString', 'setCompressionName', 'close', 'statIndex', 'getFromName');
		foreach ($required_methods as $method) {
			if (!method_exists('ZipArchive', $method)) {
				$missing_methods[] = $method;
			}
		}

		foreach (array('CREATE', 'EXCL', 'CHECKCONS', 'CM_STORE') as $constant) {
			if (!defined('ZipArchive::' . $constant)) {
				$missing_constants[] = $constant;
			}
		}
	}

	$reason = '';
	if (!$extension_loaded) {
		$reason = 'zip_extension_missing';
	} elseif (!$class_available) {
		$reason = 'zip_class_unavailable';
	} elseif (!empty($missing_methods) || !empty($missing_constants)) {
		$reason = 'zip_api_incomplete';
	}

	$capabilities = array(
		'schemaVersion'    => 1,
		'available'        => $reason === '',
		'reason'           => $reason,
		'engine'           => 'ZipArchive',
		'extensionLoaded'  => $extension_loaded,
		'extensionVersion' => $extension_loaded ? (phpversion('zip') ?: null) : null,
		'libzipVersion'    => $class_available && defined('ZipArchive::LIBZIP_VERSION')
			? (string) constant('ZipArchive::LIBZIP_VERSION')
			: null,
		'phpVersion'       => PHP_VERSION,
		'integerBits'      => PHP_INT_SIZE * 8,
		'missingMethods'   => $missing_methods,
		'missingConstants' => $missing_constants,
	);

	return $capabilities;
}

/**
 * Prerequisite for future preparation handlers, not proof of writable storage.
 */
function pgc_sgb_media_downloads_has_zip_support()
{
	$capabilities = pgc_sgb_media_downloads_get_capabilities();
	return $capabilities['available'];
}

/**
 * Minimal capability contract for the Assistant context menu.
 */
function pgc_sgb_media_downloads_get_ui_capabilities()
{
	$capabilities = pgc_sgb_media_downloads_get_capabilities();
	$storage = pgc_sgb_media_downloads_get_storage_status();
	$protection = pgc_sgb_archives_protection_status();
	return array(
		'zipAvailable' => $capabilities['available'],
		'reason'       => $capabilities['reason'],
		'storageStatus' => $storage['status'],
		'storageReason' => $storage['reason'],
		'protectionStatus' => $protection['status'],
		'protectionReason' => $protection['reason'],
		'canPrepare' => $capabilities['available'] && $storage['status'] === 'passed' && $protection['status'] === 'passed',
	);
}

/**
 * Retain diagnostics without writing an unchanged snapshot on every request.
 */
function pgc_sgb_media_downloads_refresh_capabilities()
{
	$capabilities = pgc_sgb_media_downloads_get_capabilities();
	$option = 'pgc_sgb_media_downloads_capabilities';
	if (get_option($option, null) !== $capabilities) {
		update_option($option, $capabilities, false);
	}

	return $capabilities;
}
add_action('admin_init', 'pgc_sgb_media_downloads_refresh_capabilities');

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/preflight.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/private-storage.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/builder.php';
require_once __DIR__ . '/delivery.php';
require_once __DIR__ . '/limits.php';
require_once __DIR__ . '/cleanup.php';
require_once __DIR__ . '/uninstall.php';
