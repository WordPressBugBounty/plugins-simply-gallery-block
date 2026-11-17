<?php
/** Static shortcode fallback when ZIP Sharing is unavailable. */
if (!defined('ABSPATH')) { exit; }

function pgc_sgb_zip_shortcode_unavailable()
{
	return '<div class="pgc-sgb-zip-unavailable">'
		. '<p><strong>' . esc_html__('ZIP download unavailable', 'simply-gallery-block') . '</strong></p>'
		. '<p>' . esc_html__('This download is currently unavailable. Please contact the site owner.', 'simply-gallery-block') . '</p></div>';
}

// The installed premium module replaces this handler when it loads.
add_shortcode('simply_zip', 'pgc_sgb_zip_shortcode_unavailable');
