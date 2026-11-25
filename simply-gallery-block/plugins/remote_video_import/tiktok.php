<?php

if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_remote_video_get_tiktok_post($url)
{
	$parts = wp_parse_url(trim($url));
	if (!is_array($parts)
		|| !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)
		|| !in_array(strtolower($parts['host'] ?? ''), array('tiktok.com', 'www.tiktok.com'), true)
		|| isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
		|| !preg_match('~^/(@[a-zA-Z0-9._]+/video/([0-9]{15,24}))/?$~D', $parts['path'] ?? '', $match)) {
		return null;
	}

	return array('id' => $match[2], 'link' => 'https://www.tiktok.com/' . $match[1]);
}

function pgc_sgb_remote_video_lookup_tiktok($url)
{
	$post = pgc_sgb_remote_video_get_tiktok_post($url);
	if (!$post) {
		return pgc_sgb_remote_video_import_error('unsupported_provider', __('Use a full link to a single TikTok video.', 'simply-gallery-block'), 400);
	}

	$video = array_merge($post, array(
		/* translators: %s: TikTok post ID. */
		'title' => sprintf(__('TikTok video %s', 'simply-gallery-block'), $post['id']),
		'description' => '',
		'width' => 0,
		'height' => 0,
		'posters' => array(),
	));

	$limit = 2 * MB_IN_BYTES;
	$response = wp_safe_remote_get($post['link'], array(
		'timeout' => 8,
		'redirection' => 0,
		'limit_response_size' => $limit + 1,
	));
	$body = wp_remote_retrieve_body($response);

	// Read TikTok's embedded page state without running any scripts.
	if (wp_remote_retrieve_response_code($response) !== 200 || strlen($body) > $limit
		|| !preg_match('~<script\b[^>]*\sid\s*=\s*(["\x27])__UNIVERSAL_DATA_FOR_REHYDRATION__\1[^>]*>(.*?)</script\s*>~is', $body, $matches)) {
		return $video;
	}

	$state = json_decode($matches[2], true, 512, JSON_BIGINT_AS_STRING);
	$item = $state['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;
	if (!is_array($item) || (string) ($item['id'] ?? '') !== $post['id'] || !is_array($item['video'] ?? null)) {
		return $video;
	}

	$source = $item['video'];
	$caption = is_string($item['desc'] ?? null) ? trim(wp_strip_all_tags($item['desc'], true)) : '';
	if ($caption !== '') {
		$video['title'] = $caption;
	}
	$video['width'] = filter_var($source['width'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 8192))) ?: 0;
	$video['height'] = filter_var($source['height'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 8192))) ?: 0;
	$video['posters'] = array_unique(array_filter(array($source['originCover'] ?? null, $source['cover'] ?? null), 'is_string'));

	return $video;
}

function pgc_sgb_remote_video_tiktok_cover_has_public_host($url, $host)
{
	$parts = wp_parse_url($url);
	if (!is_string($host) || strpos($host, '.') === false || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
		|| filter_var($host, FILTER_VALIDATE_IP) || ($parts['scheme'] ?? '') !== 'https'
		|| isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
		return false;
	}

	// Check both address families even though the WordPress HTTP validator checks IPv4.
	if (!function_exists('gethostbynamel') || !function_exists('dns_get_record')) {
		return false;
	}
	$ipv4 = gethostbynamel($host);
	$ipv6 = dns_get_record($host, DNS_AAAA);
	if ($ipv6 === false) {
		return false;
	}
	$addresses = array_merge($ipv4 ?: array(), array_column($ipv6, 'ipv6'));
	if (!$addresses) {
		return false;
	}

	$ip_flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
	foreach ($addresses as $address) {
		if (!filter_var($address, FILTER_VALIDATE_IP, $ip_flags)) {
			return false;
		}

		// Also exclude multicast and special-use ranges across supported PHP versions.
		if (strpos($address, ':') !== false) {
			if (!preg_match('~^[23][a-f0-9]{3}:~i', $address) || preg_match('~^(?:2002:|2001:(?:0{0,4}:|0?db8:)|3fff:)~i', $address)) {
				return false;
			}
		} elseif (preg_match('~^(?:0\.|100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|192\.(?:0\.(?:0|2)|88\.99)\.|198\.(?:18|19)\.|198\.51\.100\.|203\.0\.113\.|(?:22[4-9]|2[3-5][0-9])\.)~', $address)) {
			return false;
		}
	}

	return true;
}

function pgc_sgb_remote_video_select_tiktok_poster($video)
{
	if ($video['width'] <= 0 || $video['height'] <= 0) {
		return false;
	}

	$selected = false;
	$area = 0;
	foreach ($video['posters'] as $url) {
		$host = wp_parse_url($url, PHP_URL_HOST);
		// These candidates come from the matching post on TikTok's canonical HTTPS page.
		if (!pgc_sgb_remote_video_tiktok_cover_has_public_host($url, $host)) {
			continue;
		}
		$poster = pgc_sgb_remote_video_fetch_poster($url, $host);
		if (!$poster) {
			continue;
		}

		$size = $poster[1];
		$poster_area = $size[0] * $size[1];
		$expected_width = $size[1] * $video['width'] / $video['height'];
		if ($poster_area <= $area || abs($size[0] - $expected_width) > max(1, $expected_width * 0.005)) {
			wp_delete_file($poster[0]);
			continue;
		}

		if ($selected) {
			wp_delete_file($selected[0]);
		}
		$selected = $poster;
		$area = $poster_area;
	}

	return $selected;
}
