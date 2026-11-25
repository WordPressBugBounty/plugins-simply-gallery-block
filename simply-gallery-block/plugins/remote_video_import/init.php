<?php

if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_remote_video_get_youtube_id($url, $path)
{
	if ($path !== '/watch') {
		return basename(rtrim($path, '/'));
	}

	$id = '';
	foreach (explode('&', wp_parse_url($url, PHP_URL_QUERY) ?? '') as $param) {
		$pair = explode('=', $param, 2);
		if (urldecode($pair[0]) !== 'v') {
			continue;
		}
		$value = urldecode($pair[1] ?? '');
		if ($id !== '' || !preg_match('~^[A-Za-z0-9_-]{11}$~D', $value)) {
			return '';
		}
		$id = $value;
	}
	return $id;
}

function pgc_sgb_remote_video_get_provider($url)
{
	$parts = wp_parse_url(trim($url));
	if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
		|| isset($parts['user']) || isset($parts['pass'])) {
		return '';
	}

	$host = strtolower($parts['host'] ?? '');
	$path = $parts['path'] ?? '';

	if ($host === 'youtu.be') {
		return preg_match('~^/[A-Za-z0-9_-]{11}/?$~D', $path) ? 'youtube' : '';
	}

	if (in_array($host, array('youtube.com', 'www.youtube.com', 'm.youtube.com'), true)) {
		if ($path === '/watch') {
			return pgc_sgb_remote_video_get_youtube_id($url, $path) !== '' ? 'youtube' : '';
		}

		return preg_match('~^/(?:embed|shorts|v)/[A-Za-z0-9_-]{11}/?$~D', $path) ? 'youtube' : '';
	}

	if (in_array($host, array('vimeo.com', 'www.vimeo.com'), true)) {
		return preg_match('~^/[0-9]+(?:(?:/|:)[A-Za-z0-9_]+)?/?$~D', $path) ? 'vimeo' : '';
	}

	if (in_array($host, array('videopress.com', 'www.videopress.com'), true)) {
		return preg_match('~^/v/[A-Za-z0-9_-]{6,12}(?:/|$)~D', $path) ? 'videopress' : '';
	}

	if (in_array($host, array('tiktok.com', 'www.tiktok.com'), true)) {
		return pgc_sgb_remote_video_get_tiktok_post($url) ? 'tiktok' : '';
	}

	return '';
}

function pgc_sgb_remote_video_is_valid_attachment_url($url)
{
	if (preg_match('~[\s\p{Cc}<>]|%(?![a-f0-9]{2})~iu', $url) || strpos($url, '\\') !== false) {
		return false;
	}

	// Resolve relative and bare URLs only for validation.
	$check = $url;
	if (strpos($url, '//') === 0) {
		$check = 'https:' . $url;
	} elseif (strpos($url, '/') === 0) {
		$check = 'https://example.invalid' . $url;
	} elseif (preg_match('~^(?:[\p{L}\p{N}-]+\.)+[\p{L}\p{N}-]+(?::[0-9]+)?(?:[/?#]|$)~u', $check)) {
		$check = 'https://' . $check;
	}
	$parts = wp_parse_url($check);
	return preg_match('~^https?://[^/?#]~i', $check)
		&& is_array($parts)
		&& in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
		&& !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
		&& (bool) preg_match('~^(?:localhost|\[[0-9a-f:.]+\]|(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.)+[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?\.?)$~iuD', $parts['host'])
		&& strcasecmp(wp_kses_bad_protocol($check, array('http', 'https')), $check) === 0;
}

function pgc_sgb_sanitize_attachment_link_meta($value)
{
	if (!is_string($value)) {
		return '';
	}

	// Keep legacy fields while validating the URL and preparing its label for the gallery renderer.
	$data = json_decode($value);
	if (!is_object($data)) {
		return '';
	}
	if (property_exists($data, 'link')) {
		if (!is_string($data->link)) {
			return '';
		}
		$url = pgc_sgb_remote_video_read_link($data->link);
		if ($url !== '' && !pgc_sgb_remote_video_is_valid_attachment_url($url)) {
			return '';
		}
	}
	foreach ($data as $key => $field) {
		if ($key === 'linkText') {
			if (!is_string($field)) {
				return '';
			}
			$data->$key = esc_html($field);
		} elseif ($key !== 'link') {
			$data->$key = map_deep($field, static function ($entry) {
				return is_string($entry) ? wp_strip_all_tags($entry) : $entry;
			});
		}
	}
	return wp_json_encode($data) ?: '';
}

function pgc_sgb_remote_video_read_link($link)
{
	$link = is_string($link) ? trim($link) : '';
	return preg_match('~^https?%3a~i', $link) ? rawurldecode($link) : $link;
}

function pgc_sgb_remote_video_get_url_from_link_meta($value)
{
	$data = is_string($value) ? json_decode($value, true) : null;
	return pgc_sgb_remote_video_read_link($data['link'] ?? '');
}

function pgc_sgb_remote_video_get_preview_url($url, $provider)
{
	// The provider must come from the validated URL classifier.
	$path = wp_parse_url($url, PHP_URL_PATH);
	switch ($provider) {
		case 'youtube':
			$id = pgc_sgb_remote_video_get_youtube_id($url, $path);
			return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?autoplay=1';
		case 'vimeo':
			preg_match('~^/([0-9]+)(?:[/:]([A-Za-z0-9_]+))?~', $path, $match);
			$player = 'https://player.vimeo.com/video/' . $match[1] . '?autoplay=1';
			return isset($match[2]) ? add_query_arg('h', $match[2], $player) : $player;
		case 'tiktok':
			$post = pgc_sgb_remote_video_get_tiktok_post($url);
			return $post ? 'https://www.tiktok.com/player/v1/' . $post['id'] . '?autoplay=1&loop=1&force_muted=0' : '';
		case 'videopress':
			return 'https://video.wordpress.com/embed/' . rawurlencode(explode('/', $path)[2]) . '?autoPlay=1';
	}

	return '';
}

function pgc_sgb_remote_video_sync_provider($meta_id, $attachment_id, $meta_key)
{
	if ($meta_key !== 'pgc_sgb_link' || !wp_attachment_is_image($attachment_id)) {
		return;
	}

	$url = pgc_sgb_remote_video_get_url_from_link_meta(get_post_meta($attachment_id, 'pgc_sgb_link', true));
	$provider = pgc_sgb_remote_video_get_provider($url);
	if ($provider === '') {
		delete_post_meta($attachment_id, '_pgc_sgb_embed_provider');
		return;
	}

	update_post_meta($attachment_id, '_pgc_sgb_embed_provider', $provider);
}

add_action('added_post_meta', 'pgc_sgb_remote_video_sync_provider', 10, 3);
add_action('updated_post_meta', 'pgc_sgb_remote_video_sync_provider', 10, 3);
add_action('deleted_post_meta', 'pgc_sgb_remote_video_sync_provider', 10, 3);

require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/migration.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/tiktok.php';
