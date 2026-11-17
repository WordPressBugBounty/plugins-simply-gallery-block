<?php
/** Folder deletion revokes all snapshots without waiting for a long-running builder. */
if (!defined('ABSPATH')) { exit; }

/** Internal lifecycle operation; the deleting entry point owns authorization. */
function pgc_sgb_archives_finish_folder($folder_id)
{
	$folder_id = (int) $folder_id;
	if ($folder_id < 1) {
		return pgc_sgb_archives_error('folder_missing', __('This folder no longer exists.', 'simply-gallery-block'), 404);
	}
	$key = 'pgc_sgb_archive_finished_folder_' . $folder_id;
	// Non-autoloaded tombstone remains authoritative even if deletion/hooks are interrupted.
	$created = add_option($key, time(), '', false);
	if (!$created && !pgc_sgb_archives_folder_finished($folder_id)) {
		return pgc_sgb_archives_error('archive_finish_failed', __('Folder downloads could not be stopped. Please retry deleting the folder.', 'simply-gallery-block'), 500);
	}
	$s = pgc_sgb_archives_maintenance_state(true);
	$s['needed'] = true;
	$s['keep'] = true;
	$s['next_at'] = 0;
	update_option('pgc_sgb_archive_maintenance', $s, false);
	// Future publication handlers must be idempotent and also check the tombstone on reads.
	// No premium function reference: Free keeps the same revocation and cleanup behavior.
	do_action('pgc_sgb_archives_folder_finished', $folder_id);
	return true;
}

/** WordPress calls this before removing relationships or term metadata. */
function pgc_sgb_archives_before_delete_folder($folder_id, $taxonomy)
{
	if ($taxonomy !== PGC_SGB_MEDIA_FOLDER_TAXONOMY) { return; }
	$result = pgc_sgb_archives_finish_folder($folder_id);
	if (is_wp_error($result)) {
		// An action cannot return WP_Error to wp_delete_term(); fail closed before deletion.
		wp_die(esc_html($result->get_error_message()), '', array('response' => 500));
	}
}
add_action('pre_delete_term', 'pgc_sgb_archives_before_delete_folder', 1, 2);

function pgc_sgb_archives_after_delete_folder($folder_id, $tt_id, $taxonomy)
{
	if ($taxonomy !== PGC_SGB_MEDIA_FOLDER_TAXONOMY) { return; }
	// Existing nonblocking writer/transfer locks defer work while builds/downloads run.
	pgc_sgb_archives_cleanup_run('folder');
}
add_action('delete_term', 'pgc_sgb_archives_after_delete_folder', 10, 3);

/** Read-only deletion preview; cancellation still runs unconditionally on confirmation. */
function pgc_sgb_archives_folder_delete_impact($request)
{
	global $wpdb;
	$folder_id = (int) $request['id'];
	$folder = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$folder || is_wp_error($folder)) {
		return pgc_sgb_archives_error('folder_missing', __('This folder no longer exists.', 'simply-gallery-block'), 404);
	}
	if (!current_user_can('upload_files') || !current_user_can('delete_term', $folder_id)) {
		return pgc_sgb_archives_error('archive_forbidden', __('You do not have permission to delete this folder.', 'simply-gallery-block'), 403);
	}
	$impact = array('hasArchive' => false, 'hasBuild' => false);
	$page = 1;
	do {
		$query = new WP_Query(array(
			'post_type' => 'pgc_sgb_archive', 'post_status' => 'private',
			'posts_per_page' => 100, 'paged' => $page++, 'fields' => 'ids',
			'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
			'update_post_term_cache' => false,
			'meta_query' => array(
				array('key' => '_pgc_sgb_archive_folder', 'value' => $folder_id),
				array('key' => '_pgc_sgb_archive_cleanup_complete', 'compare' => 'NOT EXISTS'),
			),
		));
		if ($wpdb->last_error) {
			return pgc_sgb_archives_error('archive_status_unavailable', __('Could not check folder downloads. Please try again.', 'simply-gallery-block'), 500);
		}
		foreach ($query->posts as $id) {
			$r = get_post_meta($id, '_pgc_sgb_archive_record', true);
			if (!is_array($r) || !empty($r['deleted_at'])) { continue; }
			if (!pgc_sgb_archives_folder_finished($folder_id)
				&& !get_post_meta($id, '_pgc_sgb_archive_delete_requested', true)
				&& in_array($r['state'], array('queued', 'building'), true)) {
				$impact['hasBuild'] = true;
			}
			// Include old files awaiting a stream lease, but never cleaned journal entries.
			if (!$impact['hasArchive'] && !empty($r['artifact_key']) && !empty($r['artifact_bytes'])
				&& !is_wp_error(pgc_sgb_archives_validate_artifact($id, $r))) {
				$impact['hasArchive'] = true;
			}
		}
	} while (count($query->posts) === 100 && !($impact['hasArchive'] && $impact['hasBuild']));
	return $impact;
}

function pgc_sgb_archives_register_delete_impact_route()
{
	register_rest_route('pgc-sgb/v1', '/media-folders/folders/(?P<id>\d+)/delete-impact', array(
		'methods' => 'GET', 'callback' => 'pgc_sgb_archives_folder_delete_impact',
		'permission_callback' => 'pgc_sgb_media_folders_can_use',
		'args' => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
	));
}
add_action('rest_api_init', 'pgc_sgb_archives_register_delete_impact_route');
