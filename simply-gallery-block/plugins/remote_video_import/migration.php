<?php

if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_remote_video_can_run_migration()
{
	return current_user_can('manage_options') && pgc_sgb_media_folders_can_use();
}

function pgc_sgb_remote_video_run_migration_batch()
{
	global $wpdb;

	$option = 'pgc_sgb_embed_provider_migration_v1';
	$lock = $option . '_lock';
	$defaults = array('cursor' => 0, 'processed' => 0, 'classified' => 0, 'complete' => false);
	$state = get_option($option, $defaults);
	if ($state['complete']) {
		return $state;
	}

	$lease = (time() + 120) . ':' . wp_generate_uuid4();
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
		$lock,
		time()
	));
	$acquired = $wpdb->query($wpdb->prepare(
		"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
		$lock,
		$lease
	));
	if ($acquired === false) {
		return pgc_sgb_remote_video_migration_error();
	}
	if (!$acquired) {
		return array_merge($state, array('busy' => true));
	}

	try {
		// Read again under the lock, including when another tab completed a batch.
		wp_cache_delete($option, 'options');
		wp_cache_delete('notoptions', 'options');
		$state = get_option($option, $defaults);
		if ($state['complete']) {
			return $state;
		}

		$limit = 50;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			WHERE p.ID > %d AND p.post_type = 'attachment' AND p.post_mime_type LIKE %s
			AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m
				WHERE m.post_id = p.ID AND m.meta_key = 'pgc_sgb_link' AND m.meta_value <> '')
			ORDER BY p.ID ASC LIMIT %d",
			$state['cursor'],
			'image/%',
			$limit
		));
		if ($wpdb->last_error) {
			return pgc_sgb_remote_video_migration_error();
		}

		$deadline = microtime(true) + 2;
		$processed = 0;
		if ($ids) {
			update_meta_cache('post', $ids);
		}
		foreach ($ids as $id) {
			$link = get_post_meta($id, 'pgc_sgb_link', true);
			$provider = pgc_sgb_remote_video_get_provider(pgc_sgb_remote_video_get_url_from_link_meta($link));
			if ($provider !== '') {
				if (!metadata_exists('post', $id, '_pgc_sgb_embed_provider')
					&& !add_post_meta($id, '_pgc_sgb_embed_provider', $provider, true)
					&& !metadata_exists('post', $id, '_pgc_sgb_embed_provider')) {
					return pgc_sgb_remote_video_migration_error();
				}
				++$state['classified'];
			}

			$state['cursor'] = (int) $id;
			++$state['processed'];
			++$processed;
			if (microtime(true) >= $deadline) {
				break;
			}
		}

		$state['complete'] = $processed === count($ids) && $processed < $limit;
		if (!update_option($option, $state, false) && get_option($option) !== $state) {
			return pgc_sgb_remote_video_migration_error();
		}
		return $state;
	} finally {
		pgc_sgb_remote_video_release_migration_lock($lock, $lease);
	}
}

function pgc_sgb_remote_video_release_migration_lock($lock, $lease)
{
	global $wpdb;

	$wpdb->delete($wpdb->options, array('option_name' => $lock, 'option_value' => $lease));
}

function pgc_sgb_remote_video_migration_error()
{
	return new WP_Error(
		'pgc_sgb_provider_migration_failed',
		__('Could not update the video provider labels. Please try again.', 'simply-gallery-block'),
		array('status' => 500)
	);
}

add_action('rest_api_init', function () {
	register_rest_route('pgc-sgb/v1', '/media-folders/provider-migration', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'pgc_sgb_remote_video_run_migration_batch',
		'permission_callback' => 'pgc_sgb_remote_video_can_run_migration',
	));
});
