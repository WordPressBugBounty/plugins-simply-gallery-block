<?php
/** Read-only, paginated folder download checks. No archive is created. */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_media_downloads_preflight($request)
{
	$folder_id = (int) $request['id'];
	$folder = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$folder || is_wp_error($folder)) {
		return new WP_Error('folder_missing', __('This folder no longer exists.', 'simply-gallery-block'), array('status' => 404));
	}
	$capabilities = pgc_sgb_media_downloads_get_ui_capabilities();
	if (!$capabilities['canPrepare']) {
		return new WP_Error('storage_unavailable', __('Archive preparation is unavailable. Ask an administrator to check ZIP Downloads in the plugin settings.', 'simply-gallery-block'), array('status' => 409));
	}
	$page = max(1, (int) $request['page']);
	$query = new WP_Query(array(
		'post_type' => 'attachment',
		'post_status' => 'inherit',
		'posts_per_page' => 100,
		'paged' => $page,
		'orderby' => 'ID',
		'order' => 'ASC',
		'suppress_filters' => true,
		'update_post_term_cache' => false,
		'tax_query' => array(array('taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY, 'field' => 'term_id', 'terms' => array($folder_id), 'include_children' => false)),
	));
	$limits = pgc_sgb_archives_get_limits();
	$uploads = wp_upload_dir(null, false);
	$root = realpath($uploads['basedir']);
	$result = array('limits' => $limits, 'totalFiles' => (int) $query->found_posts, 'checked' => 0, 'fileCount' => 0, 'bytes' => 0, 'issueCount' => 0, 'issues' => array(), 'hasMore' => $page < (int) $query->max_num_pages);
	foreach ($query->posts as $post) {
		$result['checked']++;
		$reason = '';
		$name = '';
		if (!current_user_can('edit_post', $post->ID)) {
			$reason = __('You do not have permission to download a file in this folder.', 'simply-gallery-block');
		} else {
			$name = get_the_title($post->ID);
			$file = wp_attachment_is_image($post->ID) ? wp_get_original_image_path($post->ID) : get_attached_file($post->ID);
			// Never fetch remote URLs or follow filesystem paths outside uploads.
			$path = is_string($file) && strpos($file, '://') === false ? realpath($file) : false;
			if (!$path || !is_file($path)) {
				$reason = __('The original file is missing or has no local copy.', 'simply-gallery-block');
			} elseif (!$root || strpos(wp_normalize_path($path), trailingslashit(wp_normalize_path($root))) !== 0) {
				$reason = __('The original file is outside the supported media storage location.', 'simply-gallery-block');
			} elseif (!is_readable($path)) {
				$reason = __('The original file cannot be read.', 'simply-gallery-block');
			} else {
				$size = @filesize($path);
				if ($size === false || $size < 0) {
					$reason = __('The original file size could not be determined.', 'simply-gallery-block');
				} else {
					$result['fileCount']++;
					$result['bytes'] += $size;
				}
			}
		}
		if ($reason !== '') {
			$result['issueCount']++;
			if (count($result['issues']) < 5) {
				$result['issues'][] = array('id' => (int) $post->ID, 'name' => $name, 'message' => $reason);
			}
		}
	}
	return rest_ensure_response($result);
}

function pgc_sgb_media_downloads_register_preflight()
{
	register_rest_route('pgc-sgb/v1', '/media-folders/folders/(?P<id>\d+)/download-check', array(
		'methods' => 'POST',
		'callback' => 'pgc_sgb_media_downloads_preflight',
		'permission_callback' => 'pgc_sgb_media_folders_can_use',
		'args' => array(
			'id' => array('type' => 'integer', 'minimum' => 1, 'required' => true),
			'page' => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
		),
	));
}
add_action('rest_api_init', 'pgc_sgb_media_downloads_register_preflight');
