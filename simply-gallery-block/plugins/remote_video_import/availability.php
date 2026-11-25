<?php

if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_remote_video_get_providers()
{
	return array(
		'youtube'    => 'YouTube',
		'vimeo'      => 'Vimeo',
		'videopress' => 'VideoPress',
		'tiktok'     => 'TikTok',
	);
}

function pgc_sgb_remote_video_get_available_providers()
{
	global $wpdb;

	$registry = pgc_sgb_remote_video_get_providers();
	$excluded_statuses = array_values(get_post_stati(array('exclude_from_search' => true)));
	$revision = get_option('pgc_sgb_embed_provider_availability_revision', '');
	$cache = get_option('pgc_sgb_embed_provider_availability', array());
	if (($cache['revision'] ?? null) === $revision && ($cache['excluded_statuses'] ?? null) === $excluded_statuses
		&& ($cache['providers'] ?? null) === array_keys($registry)) {
		return $cache['filters'];
	}

	// Match the Assistant library's attachment visibility independently of active filters.
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT DISTINCT m.meta_value, p.post_status FROM {$wpdb->postmeta} m
		INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		WHERE m.meta_key = '_pgc_sgb_embed_provider'
		AND p.post_type = 'attachment' AND p.post_mime_type LIKE %s",
		'image/%'
	));
	if ($wpdb->last_error) {
		return $cache['filters'] ?? array();
	}

	$present = array();
	foreach ($rows as $row) {
		if (!in_array($row->post_status, $excluded_statuses, true)) {
			$present[$row->meta_value] = true;
		}
	}

	$filters = array();
	foreach ($registry as $id => $label) {
		if (isset($present[$id])) {
			$filters[] = array('id' => $id, 'label' => $label);
		}
	}
	update_option('pgc_sgb_embed_provider_availability', array(
		'revision'          => $revision,
		'excluded_statuses' => $excluded_statuses,
		'providers'         => array_keys($registry),
		'filters'           => $filters,
	), false);

	return $filters;
}

function pgc_sgb_remote_video_invalidate_availability()
{
	update_option('pgc_sgb_embed_provider_availability_revision', wp_generate_uuid4(), false);
}

function pgc_sgb_remote_video_on_provider_meta_change($meta_id, $attachment_id, $meta_key)
{
	if ($meta_key === '_pgc_sgb_embed_provider') {
		pgc_sgb_remote_video_invalidate_availability();
	}
}

function pgc_sgb_remote_video_on_attachment_status_change($new_status, $old_status, $post)
{
	if ($new_status !== $old_status && $post->post_type === 'attachment'
		&& metadata_exists('post', $post->ID, '_pgc_sgb_embed_provider')) {
		pgc_sgb_remote_video_invalidate_availability();
	}
}

add_action('added_post_meta', 'pgc_sgb_remote_video_on_provider_meta_change', 10, 3);
add_action('updated_post_meta', 'pgc_sgb_remote_video_on_provider_meta_change', 10, 3);
add_action('deleted_post_meta', 'pgc_sgb_remote_video_on_provider_meta_change', 10, 3);
add_action('transition_post_status', 'pgc_sgb_remote_video_on_attachment_status_change', 10, 3);
