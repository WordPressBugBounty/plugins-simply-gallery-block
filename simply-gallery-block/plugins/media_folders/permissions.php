<?php
/** Object-level folder capabilities shared by custom and core REST operations. */
if (!defined('ABSPATH')) { exit; }

function pgc_sgb_media_folders_can_manage($folder_id, $operation = 'edit', $user_id = 0)
{
	$user_id = $user_id ?: get_current_user_id();
	if (!$user_id || !user_can($user_id, 'upload_files')) { return false; }
	$folder = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$folder || is_wp_error($folder)) { return false; }
	$creator = (int) get_term_meta($folder_id, '_pgc_sgb_folder_created_by', true);
	$own = $creator > 0 && $creator === (int) $user_id;
	if ($operation !== 'delete') {
		return $own || user_can($user_id, 'edit_others_posts');
	}
	if (!user_can($user_id, 'delete_posts') || (!$own && !user_can($user_id, 'delete_others_posts'))) { return false; }
	// Deleting a term removes every membership, including other users' attachments.
	$ids = get_objects_in_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (is_wp_error($ids)) { return false; }
	foreach ($ids as $id) {
		if (get_post_type($id) === 'attachment' && !user_can($user_id, 'edit_post', (int) $id)) { return false; }
	}
	// Inspect all linked operations, not just the current ZIP. Completed cleanup is harmless.
	$page = 1;
	do {
		$query = new WP_Query(array(
			'post_type' => 'pgc_sgb_archive', 'post_status' => 'any',
			'posts_per_page' => 100, 'paged' => $page++, 'orderby' => 'ID', 'order' => 'ASC',
			'no_found_rows' => true, 'suppress_filters' => true, 'update_post_term_cache' => false,
			'meta_key' => '_pgc_sgb_archive_folder', 'meta_value' => (int) $folder_id,
		));
		foreach ($query->posts as $post) {
			$record = get_post_meta($post->ID, '_pgc_sgb_archive_record', true);
			if (!is_array($record)) { return false; }
			if (!empty($record['deleted_at'])) { continue; }
			if ((int) $post->post_author !== (int) $user_id
				&& (!user_can($user_id, 'edit_others_posts') || !user_can($user_id, 'delete_others_posts'))) { return false; }
		}
	} while (count($query->posts) === 100);
	return true;
}

/** Duplication creates a new folder but changes membership on every referenced file. */
function pgc_sgb_media_folders_can_duplicate($folder_id)
{
	if (!current_user_can('upload_files')) { return false; }
	$folder = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$folder || is_wp_error($folder)) { return false; }
	$ids = get_objects_in_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (is_wp_error($ids)) { return false; }
	foreach ($ids as $id) {
		if (get_post_type($id) === 'attachment' && !current_user_can('edit_post', (int) $id)) { return false; }
	}
	return true;
}

function pgc_sgb_media_folders_map_term_caps($caps, $cap, $user_id, $args)
{
	if (!in_array($cap, array('edit_term', 'delete_term'), true) || empty($args[0])) { return $caps; }
	$term = get_term((int) $args[0]);
	if (!$term || is_wp_error($term)) { return $caps; }
	if ($cap === 'delete_term' && defined('PGC_SGB_TAGS') && $term->taxonomy === PGC_SGB_TAGS) {
		return array_merge($caps, array('upload_files', 'edit_others_posts'));
	}
	if ($term->taxonomy !== PGC_SGB_MEDIA_FOLDER_TAXONOMY) { return $caps; }
	if (!pgc_sgb_media_folders_can_manage($term->term_id, $cap === 'delete_term' ? 'delete' : 'edit', $user_id)) {
		return array('do_not_allow');
	}
	return $caps;
}
add_filter('map_meta_cap', 'pgc_sgb_media_folders_map_term_caps', 10, 4);

function pgc_sgb_media_folders_can_edit_meta($allowed, $meta_key, $term_id, $user_id)
{
	return pgc_sgb_media_folders_can_manage($term_id, 'edit', $user_id);
}

/** Core media REST can replace taxonomy memberships independently of our endpoints. */
function pgc_sgb_media_folders_check_rest_memberships($prepared, $request)
{
	if (is_wp_error($prepared)) { return $prepared; }
	$taxonomy = get_taxonomy(PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	$key = !empty($taxonomy->rest_base) ? $taxonomy->rest_base : PGC_SGB_MEDIA_FOLDER_TAXONOMY;
	$requested = $request->get_param($key);
	if (is_null($requested)) { return $prepared; }
	$id = (int) $request->get_param('id');
	$before = $id ? wp_get_object_terms($id, PGC_SGB_MEDIA_FOLDER_TAXONOMY, array('fields' => 'ids')) : array();
	if (is_wp_error($before)) { return $before; }
	$after = array_map('intval', (array) $requested);
	$changed = array_unique(array_merge(array_diff($before, $after), array_diff($after, $before)));
	foreach ($changed as $folder_id) {
		if (!current_user_can('edit_term', (int) $folder_id)) {
			return new WP_Error('rest_forbidden', __('You do not have permission to change the contents of one or more selected folders.', 'simply-gallery-block'), array('status' => 403));
		}
	}
	return $prepared;
}
add_filter('rest_pre_insert_attachment', 'pgc_sgb_media_folders_check_rest_memberships', 10, 2);
