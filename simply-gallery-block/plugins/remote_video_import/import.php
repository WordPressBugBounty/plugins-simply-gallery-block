<?php

if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_remote_video_import_error($code, $message, $status = 422)
{
	return new WP_Error($code, $message, array('status' => $status));
}

function pgc_sgb_remote_video_lookup($url, $provider)
{
	$preferred_posters = array();
	$is_youtube_short = false;
	$link = $url;

	switch ($provider) {
		case 'youtube':
			$path = wp_parse_url($url, PHP_URL_PATH);
			$id = pgc_sgb_remote_video_get_youtube_id($url, $path);
			$is_youtube_short = strpos($path, '/shorts/') === 0;
			$link = $is_youtube_short ? 'https://www.youtube.com/shorts/' . $id : 'https://www.youtube.com/watch?v=' . $id;
			$endpoint = 'https://www.youtube.com/oembed?format=json&maxwidth=1280&url=' . rawurlencode($link);
			$poster_host = 'i.ytimg.com';
			$poster_base = 'https://' . $poster_host . '/vi/' . $id . '/';
			if ($is_youtube_short) {
				$preferred_posters[] = $poster_base . 'oardefault.jpg';
			}
			$preferred_posters[] = $poster_base . 'maxresdefault.jpg';
			break;
		case 'vimeo':
			preg_match('~^/([0-9]+)~', wp_parse_url($url, PHP_URL_PATH), $match);
			$id = $match[1];
			// Accept the legacy ID:hash link while using Vimeo's share format for oEmbed.
			$lookup_url = preg_replace('~^(https?://(?:www\.)?vimeo\.com/[0-9]+):~i', '$1/', $url);
			$endpoint = 'https://vimeo.com/api/oembed.json?width=1280&url=' . rawurlencode($lookup_url);
			$poster_host = 'i.vimeocdn.com';
			break;
		case 'tiktok':
			return pgc_sgb_remote_video_lookup_tiktok($url);
		case 'videopress':
			$id = explode('/', wp_parse_url($url, PHP_URL_PATH))[2];
			$endpoint = 'https://public-api.wordpress.com/rest/v1.1/videos/' . $id;
			$poster_host = 'videos.files.wordpress.com';
			break;
		default:
			return pgc_sgb_remote_video_import_error('unsupported_provider', __('Use a link to a single YouTube, Vimeo, VideoPress, or TikTok video.', 'simply-gallery-block'), 400);
	}

	$response = wp_safe_remote_get($endpoint, array(
		'timeout' => 8,
		'redirection' => 0,
		'limit_response_size' => 256 * KB_IN_BYTES,
	));
	$data = json_decode(wp_remote_retrieve_body($response), true);
	if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($data)
		|| !empty($data['is_private'])) {
		return pgc_sgb_remote_video_import_error('video_unavailable', __('Could not load this video. Check that the link is correct and the video can be embedded.', 'simply-gallery-block'));
	}

	$width = (int) ($data['width'] ?? 0);
	$height = (int) ($data['height'] ?? 0);
	// Restore the exact ratio when oEmbed rounds a 9:16 viewport to whole pixels.
	if ($is_youtube_short && $height > 0 && abs($width - $height * (9 / 16)) <= 0.5) {
		$width = 9;
		$height = 16;
	}

	return array(
		'link' => $link,
		'id' => $id,
		'title' => is_string($data['title'] ?? null) ? trim(wp_strip_all_tags($data['title'], true)) : '',
		'description' => is_string($data['description'] ?? null) ? trim(wp_strip_all_tags($data['description'])) : '',
		'width' => $width,
		'height' => $height,
		'posters' => array_values(array_filter(array_merge($preferred_posters, array($data['poster'] ?? $data['thumbnail_url'] ?? '')))),
		'poster_host' => $poster_host,
	);
}

function pgc_sgb_remote_video_fetch_poster($url, $host)
{
	$parts = is_string($url) ? wp_parse_url($url) : false;
	if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $host
		|| isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
		return false;
	}

	$file = wp_tempnam('sgb-video-poster');
	if (!$file) {
		return false;
	}

	$limit = min(10 * MB_IN_BYTES, wp_max_upload_size());
	$response = wp_safe_remote_get($url, array(
		'timeout' => 8,
		'redirection' => 0,
		'stream' => true,
		'filename' => $file,
		'limit_response_size' => $limit + 1,
	));
	$size = filesize($file);
	$image = wp_remote_retrieve_response_code($response) === 200 && $size > 0 && $size <= $limit
		? wp_getimagesize($file) : false;
	$extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
	if (!$image || !isset($extensions[$image['mime']]) || $image[0] * $image[1] > 20000000
		|| $image[0] < 200 || $image[1] < 100) {
		wp_delete_file($file);
		return false;
	}

	return array($file, $image, $extensions[$image['mime']]);
}

function pgc_sgb_remote_video_crop_poster($file, $size, $width, $height)
{
	if ($width <= 0 || $height <= 0) {
		return $file;
	}

	$ratio = $width / $height;
	$crop_width = min($size[0], (int) round($size[1] * $ratio));
	$crop_height = min($size[1], (int) round($size[0] / $ratio));
	if ($size[0] - $crop_width <= 2 && $size[1] - $crop_height <= 2) {
		return $file;
	}
	if ($crop_width < 100 || $crop_height < 100) {
		return false;
	}

	$editor = wp_get_image_editor($file, array('mime_type' => $size['mime']));
	if (is_wp_error($editor)) {
		return false;
	}
	$result = $editor->crop(
		(int) floor(($size[0] - $crop_width) / 2),
		(int) floor(($size[1] - $crop_height) / 2),
		$crop_width,
		$crop_height
	);
	if (is_wp_error($result)) {
		return false;
	}
	$saved = $editor->save($file, $size['mime']);
	return is_wp_error($saved) ? false : $saved['path'];
}

function pgc_sgb_remote_video_finish_import($id, $url, $provider)
{
	if (is_wp_error($id)) {
		return pgc_sgb_remote_video_import_error('poster_save_failed', $id->get_error_message());
	}

	$link = wp_json_encode(array('id' => $id, 'link' => $url, 'linkText' => '', 'linkTarget' => '_self'));
	if (!$link || !update_post_meta($id, 'pgc_sgb_link', wp_slash($link))
		|| get_post_meta($id, '_pgc_sgb_embed_provider', true) !== $provider) {
		wp_delete_attachment($id, true);
		return pgc_sgb_remote_video_import_error('video_link_save_failed', __('Could not save the video link.', 'simply-gallery-block'), 500);
	}

	return new WP_REST_Response(array('id' => $id), 201);
}

function pgc_sgb_remote_video_upload_tiktok_poster($request, $url, $file)
{
	$post = pgc_sgb_remote_video_get_tiktok_post($url);
	if (!$post) {
		return pgc_sgb_remote_video_import_error('unsupported_provider', __('Use a full link to a single TikTok video.', 'simply-gallery-block'), 400);
	}

	$mimes = array('jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif');
	$mimes = array_intersect($mimes, get_allowed_mime_types());
	$file_error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
	$tmp = $file['tmp_name'] ?? '';
	$size = is_string($tmp) && $file_error === UPLOAD_ERR_OK && is_uploaded_file($tmp)
		? filesize($tmp) : false;
	if ($file_error === UPLOAD_ERR_INI_SIZE || $file_error === UPLOAD_ERR_FORM_SIZE || $size > wp_max_upload_size()) {
		return pgc_sgb_remote_video_import_error('poster_too_large', __('The poster exceeds the upload limit for this site.', 'simply-gallery-block'));
	}
	$image = $size > 0 ? wp_getimagesize($tmp) : false;
	if (!$image || !in_array($image['mime'], $mimes, true) || $image[0] * $image[1] > 20000000) {
		return pgc_sgb_remote_video_import_error('invalid_poster_image', __('Choose a valid JPEG, PNG, WebP, or GIF image up to 20 megapixels.', 'simply-gallery-block'));
	}

	$title = trim(wp_strip_all_tags($request['title'] ?? '', true));
	if ($title === '') {
		/* translators: %s: TikTok post ID. */
		$title = sprintf(__('TikTok video %s', 'simply-gallery-block'), $post['id']);
	}

	$id = media_handle_upload('poster', 0, wp_slash(array('post_title' => $title, 'post_content' => '', 'post_excerpt' => '')), array(
		'test_form' => false,
		'mimes'     => $mimes,
	));
	return pgc_sgb_remote_video_finish_import($id, $post['link'], 'tiktok');
}

function pgc_sgb_remote_video_import(WP_REST_Request $request)
{
	$url = trim($request['url']);
	$provider = pgc_sgb_remote_video_get_provider($url);
	$folder_id = (int) $request['folder_id'];
	if ($folder_id > 0) {
		$folder = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
		if (!$folder || is_wp_error($folder)) {
			return pgc_sgb_remote_video_import_error('folder_not_found', __('Media folder not found.', 'simply-gallery-block'), 404);
		}
		if (!current_user_can('edit_term', $folder_id)) {
			return pgc_sgb_remote_video_import_error('folder_forbidden', __('You do not have permission to add items to this folder.', 'simply-gallery-block'), 403);
		}
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$uploads = $request->get_file_params();
	if (isset($uploads['poster'])) {
		return pgc_sgb_remote_video_upload_tiktok_poster($request, $url, $uploads['poster']);
	}

	$video = pgc_sgb_remote_video_lookup($url, $provider);
	if (is_wp_error($video)) {
		return $video;
	}

	$poster = false;
	if ($provider === 'tiktok') {
		$poster = pgc_sgb_remote_video_select_tiktok_poster($video);
		if (!$poster) {
			return new WP_Error(
				'tiktok_poster_required',
				__('Could not retrieve a poster for this video.', 'simply-gallery-block'),
				array('status' => 422, 'title' => $video['title'], 'width' => $video['width'], 'height' => $video['height'])
			);
		}
	} else {
		foreach ($video['posters'] as $poster_url) {
			$poster = pgc_sgb_remote_video_fetch_poster($poster_url, $video['poster_host']);
			if ($poster) {
				break;
			}
		}
	}
	if (empty($poster)) {
		return pgc_sgb_remote_video_import_error('poster_unavailable', __('Could not download a usable poster for this video.', 'simply-gallery-block'));
	}

	list($file, $size, $extension) = $poster;
	$prepared_file = false;
	try {
		$prepared_file = pgc_sgb_remote_video_crop_poster($file, $size, $video['width'], $video['height']);
		if (!$prepared_file) {
			return pgc_sgb_remote_video_import_error('poster_geometry_failed', __('Could not prepare the poster at the video aspect ratio.', 'simply-gallery-block'));
		}

		$title = $video['title'] !== '' ? $video['title'] : $video['id'];
		$name = $provider . '-' . $video['id'];
		if ($prepared_file !== $file) {
			$extension = pathinfo($prepared_file, PATHINFO_EXTENSION);
		}
		$id = media_handle_sideload(
			array('name' => $name . '.' . $extension, 'tmp_name' => $prepared_file),
			0,
			null,
			wp_slash(array('post_title' => $title, 'post_content' => $video['description'], 'post_excerpt' => ''))
		);
		return pgc_sgb_remote_video_finish_import($id, $video['link'], $provider);
	} finally {
		if ($prepared_file && $prepared_file !== $file && is_file($prepared_file)) {
			wp_delete_file($prepared_file);
		}
		if (is_file($file)) {
			wp_delete_file($file);
		}
	}
}

add_action('rest_api_init', function () {
	register_rest_route('pgc-sgb/v1', '/media-folders/remote-video', array(
		'methods' => WP_REST_Server::CREATABLE,
		'callback' => 'pgc_sgb_remote_video_import',
		'permission_callback' => 'pgc_sgb_media_folders_can_use',
		'args' => array(
			'url' => array('type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 2048),
			'folder_id' => array('type' => 'integer', 'default' => -1, 'minimum' => -1),
			'title' => array('type' => 'string', 'maxLength' => 4096),
		),
	));
});
