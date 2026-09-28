<?php
/** Administrator-controlled workload policy, not measured hosting capacity. */
if (!defined('ABSPATH')) {
	exit;
}

function pgc_sgb_archives_limit_bounds()
{
	$integer_max = min(PHP_INT_MAX, 9007199254740991);
	return array('files' => $integer_max, 'sizeMiB' => (int) floor($integer_max / 1048576));
}

function pgc_sgb_archives_parse_limit($value, $maximum)
{
	if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]+\z/', (string) $value)
		|| (float) $value < 1 || (float) $value > $maximum) {
		return false;
	}
	return (int) $value;
}

function pgc_sgb_archives_limit_settings()
{
	$saved = get_option('pgc_sgb_archive_limits', array());
	$bounds = pgc_sgb_archives_limit_bounds();
	return array(
		'files' => pgc_sgb_archives_parse_limit($saved['files'] ?? 500, $bounds['files']) ?: 500,
		'sizeMiB' => pgc_sgb_archives_parse_limit($saved['sizeMiB'] ?? 256, $bounds['sizeMiB']) ?: 256,
	);
}

function pgc_sgb_archives_get_limits()
{
	$settings = pgc_sgb_archives_limit_settings();
	$defaults = array('files' => $settings['files'], 'bytes' => $settings['sizeMiB'] * 1048576);
	$filtered = apply_filters('pgc_sgb_archive_limits', $defaults);
	$maximum = min(PHP_INT_MAX, 9007199254740991);
	return array(
		'files' => pgc_sgb_archives_parse_limit($filtered['files'] ?? null, $maximum) ?: $defaults['files'],
		'bytes' => pgc_sgb_archives_parse_limit($filtered['bytes'] ?? null, $maximum) ?: $defaults['bytes'],
	);
}

function pgc_sgb_archives_limits_payload()
{
	$settings = pgc_sgb_archives_limit_settings();
	$effective = pgc_sgb_archives_get_limits();
	return array('settings' => $settings, 'bounds' => pgc_sgb_archives_limit_bounds(), 'effective' => $effective,
		'overridden' => $effective['files'] !== $settings['files'] || $effective['bytes'] !== $settings['sizeMiB'] * 1048576);
}

function pgc_sgb_archives_save_limits()
{
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('You cannot change archive settings.', 'simply-gallery-block')), 403);
	}
	check_ajax_referer('pgc-sgb-nonce', 'nonce');
	$bounds = pgc_sgb_archives_limit_bounds();
	$settings = array();
	foreach ($bounds as $key => $maximum) {
		$value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : null;
		$value = pgc_sgb_archives_parse_limit($value, $maximum);
		if ($value === false) {
			wp_send_json_error(array('message' => __('Enter positive whole numbers within the supported range.', 'simply-gallery-block')), 400);
		}
		$settings[$key] = $value;
	}
	if (get_option('pgc_sgb_archive_limits', null) !== $settings && !update_option('pgc_sgb_archive_limits', $settings, false)) {
		wp_send_json_error(array('message' => __('The settings could not be saved. Please retry.', 'simply-gallery-block')), 500);
	}
	wp_send_json_success(pgc_sgb_archives_limits_payload());
}
add_action('wp_ajax_pgc_sgb_archive_limits', 'pgc_sgb_archives_save_limits');
