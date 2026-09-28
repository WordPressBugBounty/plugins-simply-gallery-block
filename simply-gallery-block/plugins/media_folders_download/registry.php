<?php
/**
 * Private archive operation registry. Records do not create or delete ZIP files.
 *
 * @package SimpLy Gallery Block
 */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_archives_register_post_type()
{
	register_post_type('pgc_sgb_archive', array(
		'label' => __('Archive operations', 'simply-gallery-block'),
		'public' => false,
		'publicly_queryable' => false,
		'exclude_from_search' => true,
		'show_ui' => false,
		'show_in_menu' => false,
		'show_in_nav_menus' => false,
		'show_in_admin_bar' => false,
		'show_in_rest' => false,
		'has_archive' => false,
		'rewrite' => false,
		'query_var' => false,
		'can_export' => false,
		'delete_with_user' => false,
		'supports' => false,
		'map_meta_cap' => false,
		'capabilities' => array_fill_keys(array(
			'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
			'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
			'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
			'edit_published_posts', 'create_posts',
		), 'do_not_allow'),
	));
	// One bounded snapshot allows compare-and-swap lifecycle changes. No event array.
	register_post_meta('pgc_sgb_archive', '_pgc_sgb_archive_record', array(
		'type' => 'object',
		'single' => true,
		'show_in_rest' => false,
		'auth_callback' => '__return_false',
	));
}
add_action('init', 'pgc_sgb_archives_register_post_type');

function pgc_sgb_archives_error($code, $message, $status = 400)
{
	return new WP_Error($code, $message, array('status' => $status));
}

/** Owner-only service boundary. Background cleanup will need its own internal API. */
function pgc_sgb_archives_get($id)
{
	$post = get_post((int) $id);
	if (!is_user_logged_in() || !current_user_can('upload_files') || !$post
		|| $post->post_type !== 'pgc_sgb_archive'
		|| (int) $post->post_author !== get_current_user_id()) {
		return pgc_sgb_archives_error('archive_unavailable', __('Archive operation is unavailable.', 'simply-gallery-block'), 404);
	}
	$record = get_post_meta($post->ID, '_pgc_sgb_archive_record', true);
	if (!is_array($record) || !isset($record['schema_version'], $record['state']) || $record['schema_version'] !== 1) {
		return pgc_sgb_archives_error('archive_record_invalid', __('Archive operation could not be read.', 'simply-gallery-block'), 500);
	}
	$source_current = null;
	if ($record['source_revision'] !== '' && function_exists('pgc_sgb_archives_folder_revision')) {
		$folder = get_term($record['folder_id'], PGC_SGB_MEDIA_FOLDER_TAXONOMY);
		$source_current = $folder && !is_wp_error($folder)
			&& $record['source_revision'] === pgc_sgb_archives_folder_revision($record['folder_id']);
	}
	return array('id' => (int) $post->ID, 'owner_id' => (int) $post->post_author, 'record' => $record, 'source_current' => $source_current);
}

/** Browser-facing filename; the private on-disk artifact keeps its stable name. */
function pgc_sgb_archives_download_name($folder_label)
{
	$name = sanitize_file_name(wp_strip_all_tags($folder_label));
	$name = preg_replace('~[\x00-\x1f\x7f<>:"/|?*]~u', '-', str_replace('\\', '-', $name));
	$name = trim($name, ". ");
	return ($name !== '' ? $name : 'Media folder') . '.zip';
}

/** Called by future explicit preparation only, never by preflight or activation. */
function pgc_sgb_archives_create($folder_id)
{
	if (!is_user_logged_in() || !current_user_can('upload_files')) {
		return pgc_sgb_archives_error('archive_forbidden', __('You cannot prepare archives.', 'simply-gallery-block'), 403);
	}
	$folder = get_term((int) $folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if ((int) $folder_id < 1 || !$folder || is_wp_error($folder)) {
		return pgc_sgb_archives_error('folder_missing', __('This folder no longer exists.', 'simply-gallery-block'), 404);
	}
	$record = array(
		'schema_version' => 1,
		'folder_id' => (int) $folder_id,
		'folder_label' => $folder->name,
		'download_name' => pgc_sgb_archives_download_name($folder->name),
		'mode' => 'free',
		'state' => 'queued',
		'source_revision' => '',
		'manifest_reference' => '',
		'manifest_fingerprint' => '',
		'source_count' => 0,
		'source_bytes' => 0,
		'created_at' => time(),
		'started_at' => 0,
		'ready_at' => 0,
		'expires_at' => 0,
		'heartbeat_at' => 0,
		'artifact_key' => '',
		'artifact_bytes' => 0,
		'verified_at' => 0,
		'error_code' => '',
		'deleted_at' => 0,
		'cleanup_error' => '',
	);
	pgc_sgb_archives_mark_maintenance();
	$id = wp_insert_post(array(
		'post_type' => 'pgc_sgb_archive',
		'post_status' => 'private',
		'post_title' => 'Archive operation',
		'post_author' => get_current_user_id(),
	), true);
	if (is_wp_error($id)) {
		return $id;
	}
	if (!$id || !add_post_meta($id, '_pgc_sgb_archive_record', $record, true)) {
		if ($id) {
			wp_delete_post($id, true);
		}
		return pgc_sgb_archives_error('archive_record_write_failed', __('Archive operation could not be saved.', 'simply-gallery-block'), 500);
	}
	return pgc_sgb_archives_get($id);
}

/**
 * Guarded lifecycle update. The caller must provide the snapshot it last read.
 * This protects record updates; it is NOT the future archive writer lock.
 */
function pgc_sgb_archives_transition($id, $expected, $state, $changes = array())
{
	$current = pgc_sgb_archives_get($id);
	if (is_wp_error($current)) {
		return $current;
	}
	$before = $current['record'];
	if ($before !== $expected) {
		return pgc_sgb_archives_error('archive_conflict', __('Archive operation changed. Refresh its status.', 'simply-gallery-block'), 409);
	}
	$transitions = array(
		'queued' => array('building', 'failed', 'invalidated'),
		'building' => array('ready', 'failed', 'invalidated'),
		'ready' => array('expired', 'invalidated'),
		'failed' => array(),
		'invalidated' => array(),
		'expired' => array(),
	);
	if (!isset($transitions[$before['state']]) || !in_array($state, $transitions[$before['state']], true)) {
		return pgc_sgb_archives_error('archive_transition_invalid', __('Archive state change is not allowed.', 'simply-gallery-block'));
	}
	$allowed = array('source_revision', 'manifest_reference', 'manifest_fingerprint', 'source_count', 'source_bytes', 'artifact_key', 'artifact_bytes', 'verified_at', 'error_code');
	foreach ($changes as $key => $value) {
		if (!in_array($key, $allowed, true)) {
			return pgc_sgb_archives_error('archive_field_invalid', __('Archive metadata is invalid.', 'simply-gallery-block'));
		}
		if (in_array($key, array('source_count', 'source_bytes', 'artifact_bytes', 'verified_at'), true)) {
			if (!is_int($value) || $value < 0) {
				return pgc_sgb_archives_error('archive_field_invalid', __('Archive metadata is invalid.', 'simply-gallery-block'));
			}
		} elseif (!is_string($value) || strlen($value) > 255 || !preg_match('/\A[a-zA-Z0-9_-]*\z/', $value)) {
			// Opaque storage keys only; neither paths nor raw exception text.
			return pgc_sgb_archives_error('archive_field_invalid', __('Archive metadata is invalid.', 'simply-gallery-block'));
		}
	}
	$next = array_merge($before, $changes);
	$next['state'] = $state;
	$now = time();
	if ($state === 'building') {
		$next['started_at'] = $now;
		$next['heartbeat_at'] = $now;
	}
	if ($state === 'ready') {
		if (isset($current['source_current']) && $current['source_current'] === false) {
			return pgc_sgb_archives_error('source_changed', __('The folder changed during preparation.', 'simply-gallery-block'), 409);
		}
		foreach (array('source_revision', 'manifest_reference', 'manifest_fingerprint', 'source_count', 'artifact_key', 'artifact_bytes', 'verified_at') as $required) {
			if (empty($next[$required])) {
				return pgc_sgb_archives_error('archive_not_verified', __('Archive verification is incomplete.', 'simply-gallery-block'));
			}
		}
		if ($next['verified_at'] < $next['started_at'] || $next['verified_at'] > $now) {
			return pgc_sgb_archives_error('archive_not_verified', __('Archive verification is incomplete.', 'simply-gallery-block'));
		}
		$next['ready_at'] = $now;
		$next['expires_at'] = $now + HOUR_IN_SECONDS;
	}
	if ($state === 'expired' && $before['expires_at'] > $now) {
		return pgc_sgb_archives_error('archive_not_expired', __('Archive has not expired yet.', 'simply-gallery-block'));
	}
	if ($state === 'failed' && $next['error_code'] === '') {
		return pgc_sgb_archives_error('archive_error_required', __('An archive failure reason is required.', 'simply-gallery-block'));
	}
	if (!update_post_meta($id, '_pgc_sgb_archive_record', $next, $before)) {
		return pgc_sgb_archives_error('archive_update_failed', __('Archive update failed or its status changed. Refresh and try again.', 'simply-gallery-block'), 409);
	}
	return pgc_sgb_archives_get($id);
}
