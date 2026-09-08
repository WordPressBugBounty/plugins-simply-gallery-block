<?php

/**
 * Media Folders module.
 *
 * Adds free, virtual, one-level folders for WordPress media attachments.
 *
 * @package SimpLy Gallery Block
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('PGC_SGB_MEDIA_FOLDER_TAXONOMY')) {
	define('PGC_SGB_MEDIA_FOLDER_TAXONOMY', 'pgc_sgb_media_folder');
}

if (!defined('PGC_SGB_MEDIA_FOLDER_COLOR_META')) {
	define('PGC_SGB_MEDIA_FOLDER_COLOR_META', 'pgc_sgb_folder_color');
}

if (!defined('PGC_SGB_MEDIA_FOLDER_ORDER_META')) {
	define('PGC_SGB_MEDIA_FOLDER_ORDER_META', 'pgc_sgb_folder_order');
}

if (!defined('PGC_SGB_MEDIA_FOLDER_ACTIVE_META')) {
	define('PGC_SGB_MEDIA_FOLDER_ACTIVE_META', 'pgc_sgb_active_media_folder');
}

if (!defined('PGC_SGB_MEDIA_FOLDERS_COLLAPSED_META')) {
	define('PGC_SGB_MEDIA_FOLDERS_COLLAPSED_META', 'pgc_sgb_media_folders_collapsed');
}

if (!defined('PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META')) {
	define('PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META', 'pgc_sgb_media_folders_show_library');
}

if (!defined('PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META')) {
	define('PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META', 'pgc_sgb_media_folders_show_modal');
}

if (!defined('PGC_SGB_MEDIA_ASSISTANT_PER_PAGE_META')) {
	define('PGC_SGB_MEDIA_ASSISTANT_PER_PAGE_META', 'pgc_sgb_media_assistant_per_page');
}

if (!defined('PGC_SGB_MEDIA_ASSISTANT_SORT_BY_META')) {
	define('PGC_SGB_MEDIA_ASSISTANT_SORT_BY_META', 'pgc_sgb_media_assistant_sort_by');
}

if (!defined('PGC_SGB_MEDIA_ASSISTANT_ORDER_META')) {
	define('PGC_SGB_MEDIA_ASSISTANT_ORDER_META', 'pgc_sgb_media_assistant_order');
}

if (!defined('PGC_SGB_MEDIA_ASSISTANT_REMEMBER_FOLDER_META')) {
	define('PGC_SGB_MEDIA_ASSISTANT_REMEMBER_FOLDER_META', 'pgc_sgb_media_assistant_remember_folder');
}

if (!defined('PGC_SGB_MEDIA_ASSISTANT_PICKER_MODE_META')) {
	define('PGC_SGB_MEDIA_ASSISTANT_PICKER_MODE_META', 'pgc_sgb_media_assistant_picker_mode');
}

if (!defined('PGC_SGB_MEDIA_FOLDERS_ASSISTANT_PAGE')) {
	define('PGC_SGB_MEDIA_FOLDERS_ASSISTANT_PAGE', 'pgc-sgb-media-assistant');
}

function pgc_sgb_media_folders_register_taxonomy()
{
	register_taxonomy(
		PGC_SGB_MEDIA_FOLDER_TAXONOMY,
		array('attachment'),
		array(
			'label'                 => __('SGB Media Folders', 'simply-gallery-block'),
			'labels'                => array(
				'name'          => __('SGB Media Folders', 'simply-gallery-block'),
				'singular_name' => __('SGB Media Folder', 'simply-gallery-block'),
			),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => false,
			'show_admin_column'     => false,
			'show_in_nav_menus'     => false,
			'show_tagcloud'         => false,
			'show_in_rest'          => true,
			'hierarchical'          => false,
			'query_var'             => false,
			'rewrite'               => false,
			'update_count_callback' => '_update_generic_term_count',
			'capabilities'          => array(
				'manage_terms' => 'upload_files',
				'edit_terms'   => 'upload_files',
				'delete_terms' => 'upload_files',
				'assign_terms' => 'upload_files',
			),
		)
	);

	register_term_meta(
		PGC_SGB_MEDIA_FOLDER_TAXONOMY,
		PGC_SGB_MEDIA_FOLDER_COLOR_META,
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'pgc_sgb_media_folders_sanitize_color',
			'auth_callback'     => 'pgc_sgb_media_folders_can_use',
			'show_in_rest'      => true,
		)
	);

	register_term_meta(
		PGC_SGB_MEDIA_FOLDER_TAXONOMY,
		PGC_SGB_MEDIA_FOLDER_ORDER_META,
		array(
			'type'              => 'integer',
			'single'            => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => 'pgc_sgb_media_folders_can_use',
			'show_in_rest'      => true,
		)
	);
}
add_action('init', 'pgc_sgb_media_folders_register_taxonomy');

function pgc_sgb_media_folders_can_use($request = null, $meta_key = null, $object_id = null, $user_id = null, $cap = null, $caps = null)
{
	return current_user_can('upload_files');
}

function pgc_sgb_media_folders_user_setting_enabled($meta_key)
{
	$value = get_user_meta(get_current_user_id(), $meta_key, true);

	return $value === '' || $value === '1';
}

function pgc_sgb_media_folders_native_ui_enabled()
{
	return pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META)
		|| pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META);
}

function pgc_sgb_media_folders_get_assistant_per_page()
{
	$per_page = absint(get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PER_PAGE_META, true));
	$allowed = array(20, 50, 100);

	return in_array($per_page, $allowed, true) ? $per_page : 20;
}

function pgc_sgb_media_folders_get_assistant_sort_by()
{
	$sort_by = sanitize_key((string) get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_SORT_BY_META, true));
	$allowed = array('date', 'title', 'modified', 'author');

	return in_array($sort_by, $allowed, true) ? $sort_by : 'date';
}

function pgc_sgb_media_folders_get_assistant_order()
{
	$order = strtoupper(sanitize_key((string) get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_ORDER_META, true)));
	$allowed = array('ASC', 'DESC');

	return in_array($order, $allowed, true) ? $order : 'DESC';
}

function pgc_sgb_media_folders_get_assistant_remember_folder()
{
	return get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_REMEMBER_FOLDER_META, true) === '1';
}

function pgc_sgb_media_folders_get_assistant_picker_mode()
{
	$picker_mode = sanitize_key((string) get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PICKER_MODE_META, true));
	$allowed = array('native', 'select');

	return in_array($picker_mode, $allowed, true) ? $picker_mode : 'select';
}

function pgc_sgb_media_folders_get_assistant_settings()
{
	return array(
		'showLibrary' => pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META),
		'showModal' => pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META),
		'pickerMode' => pgc_sgb_media_folders_get_assistant_picker_mode(),
		'rememberFolder' => pgc_sgb_media_folders_get_assistant_remember_folder(),
		'perPage' => pgc_sgb_media_folders_get_assistant_per_page(),
		'sortBy' => pgc_sgb_media_folders_get_assistant_sort_by(),
		'order' => pgc_sgb_media_folders_get_assistant_order(),
	);
}

function pgc_sgb_media_folders_update_assistant_settings($settings)
{
	if (!is_array($settings)) {
		return pgc_sgb_media_folders_get_assistant_settings();
	}

	update_user_meta(
		get_current_user_id(),
		PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META,
		!empty($settings['showLibrary']) ? '1' : '0'
	);
	update_user_meta(
		get_current_user_id(),
		PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META,
		!empty($settings['showModal']) ? '1' : '0'
	);

	$assistant_per_page = isset($settings['perPage']) ? absint($settings['perPage']) : 20;
	if (!in_array($assistant_per_page, array(20, 50, 100), true)) {
		$assistant_per_page = 20;
	}

	$assistant_sort_by = isset($settings['sortBy']) ? sanitize_key($settings['sortBy']) : 'date';
	if (!in_array($assistant_sort_by, array('date', 'title', 'modified', 'author'), true)) {
		$assistant_sort_by = 'date';
	}

	$assistant_order = isset($settings['order']) ? strtoupper(sanitize_key($settings['order'])) : 'DESC';
	if (!in_array($assistant_order, array('ASC', 'DESC'), true)) {
		$assistant_order = 'DESC';
	}

	$assistant_picker_mode = isset($settings['pickerMode']) ? sanitize_key($settings['pickerMode']) : 'select';
	if (!in_array($assistant_picker_mode, array('native', 'select'), true)) {
		$assistant_picker_mode = 'select';
	}

	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PER_PAGE_META, $assistant_per_page);
	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_SORT_BY_META, $assistant_sort_by);
	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_ORDER_META, $assistant_order);
	update_user_meta(
		get_current_user_id(),
		PGC_SGB_MEDIA_ASSISTANT_REMEMBER_FOLDER_META,
		!empty($settings['rememberFolder']) ? '1' : '0'
	);
	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PICKER_MODE_META, $assistant_picker_mode);

	return pgc_sgb_media_folders_get_assistant_settings();
}

function pgc_sgb_media_folders_ajax_assistant_settings()
{
	check_ajax_referer('pgc-sgb-nonce', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error(array(
			'message' => __('Sorry, you are not allowed to update these settings.', 'simply-gallery-block'),
		), 403);
	}

	$settings_raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : '';
	$settings = json_decode($settings_raw, true);
	if (!is_array($settings)) {
		wp_send_json_error(array(
			'message' => __('Invalid settings payload.', 'simply-gallery-block'),
		), 400);
	}

	wp_send_json_success(array(
		'settings' => pgc_sgb_media_folders_update_assistant_settings($settings),
	));
}
add_action('wp_ajax_pgc_sgb_media_folders_assistant_settings', 'pgc_sgb_media_folders_ajax_assistant_settings');

function pgc_sgb_media_folders_sanitize_color($color)
{
	$color = sanitize_hex_color($color);

	return $color ? $color : '';
}

function pgc_sgb_media_folders_normalize_term($term, $count = null)
{
	if (!$term || is_wp_error($term)) {
		return null;
	}

	$color = get_term_meta($term->term_id, PGC_SGB_MEDIA_FOLDER_COLOR_META, true);
	$order = get_term_meta($term->term_id, PGC_SGB_MEDIA_FOLDER_ORDER_META, true);

	return array(
		'id'    => (int) $term->term_id,
		'name'  => $term->name,
		'slug'  => $term->slug,
		'color' => $color ? $color : '',
		'order' => $order === '' ? 0 : (int) $order,
		'count' => is_null($count) ? (int) $term->count : (int) $count,
	);
}

function pgc_sgb_media_folders_get_terms()
{
	$terms = get_terms(
		array(
			'taxonomy'   => PGC_SGB_MEDIA_FOLDER_TAXONOMY,
			'hide_empty' => false,
		)
	);

	if (is_wp_error($terms) || empty($terms)) {
		return array();
	}

	usort(
		$terms,
		function ($a, $b) {
			$a_order = get_term_meta($a->term_id, PGC_SGB_MEDIA_FOLDER_ORDER_META, true);
			$b_order = get_term_meta($b->term_id, PGC_SGB_MEDIA_FOLDER_ORDER_META, true);
			$a_order = $a_order === '' ? 0 : (int) $a_order;
			$b_order = $b_order === '' ? 0 : (int) $b_order;

			if ($a_order === $b_order) {
				return strcasecmp($a->name, $b->name);
			}

			return $a_order < $b_order ? -1 : 1;
		}
	);

	return $terms;
}

function pgc_sgb_media_folders_count_query($folder_id)
{
	$args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'posts_per_page'         => 1,
		'paged'                  => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'suppress_filters'       => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	if ((int) $folder_id === 0) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY,
				'operator' => 'NOT EXISTS',
			),
		);
	} elseif ((int) $folder_id > 0) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY,
				'field'    => 'term_id',
				'terms'    => array((int) $folder_id),
			),
		);
	}

	$query = new WP_Query($args);
	$count = (int) $query->found_posts;
	wp_reset_postdata();

	return $count;
}

function pgc_sgb_media_folders_get_counts()
{
	$counts = array(
		'-1' => pgc_sgb_media_folders_count_query(-1),
		'0'  => pgc_sgb_media_folders_count_query(0),
	);

	$terms = pgc_sgb_media_folders_get_terms();
	foreach ($terms as $term) {
		$counts[(string) $term->term_id] = pgc_sgb_media_folders_count_query($term->term_id);
	}

	return $counts;
}

function pgc_sgb_media_folders_get_document_mime_types()
{
	return array(
		'application/csv',
		'application/msword',
		'application/pdf',
		'application/rtf',
		'application/vnd.ms-excel',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/zip',
		'text/csv',
		'text/plain',
		'text/richtext',
	);
}

function pgc_sgb_media_folders_get_type_mime_query($type)
{
	switch ($type) {
		case 'image':
			return 'image';
		case 'audio':
			return 'audio';
		case 'video':
			return 'video';
		case 'document':
			return pgc_sgb_media_folders_get_document_mime_types();
		default:
			return '';
	}
}

function pgc_sgb_media_folders_normalize_tag_name($tag)
{
	return sanitize_text_field(trim((string) $tag));
}

function pgc_sgb_media_folders_get_request_tags(WP_REST_Request $request)
{
	$tags = $request->get_param('tags');

	if (is_null($tags)) {
		$tags = $request->get_param('tag');
	}

	if (is_null($tags)) {
		$tags = $request->get_param('name');
	}

	if (!is_array($tags)) {
		$tags = array($tags);
	}

	$tags = array_map('pgc_sgb_media_folders_normalize_tag_name', $tags);
	$tags = array_filter($tags);

	return array_values(array_unique($tags));
}

function pgc_sgb_media_folders_get_storage_tags()
{
	if (function_exists('pgc_sgb_get_tags_list')) {
		$tags_string = pgc_sgb_get_tags_list();
	} else {
		$tags_string = '';
	}

	if ($tags_string === '') {
		return array();
	}

	$tags = array_map('pgc_sgb_media_folders_normalize_tag_name', explode(',', $tags_string));
	$tags = array_filter($tags);
	natcasesort($tags);

	return array_values($tags);
}

function pgc_sgb_media_folders_get_tag_counts()
{
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS tag, COUNT(DISTINCT pm.post_id) AS count
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND p.post_type = %s
				AND pm.meta_value <> ''
			GROUP BY pm.meta_value",
			'pgc_sgb_tag',
			'attachment'
		),
		ARRAY_A
	);

	$counts = array();
	foreach ($rows as $row) {
		$tag = pgc_sgb_media_folders_normalize_tag_name($row['tag']);
		if ($tag !== '') {
			$counts[$tag] = (int) $row['count'];
		}
	}

	return $counts;
}

function pgc_sgb_media_folders_get_normalized_tags()
{
	$counts = pgc_sgb_media_folders_get_tag_counts();
	$tags = array();

	foreach (pgc_sgb_media_folders_get_storage_tags() as $tag) {
		$tags[] = array(
			'name'  => $tag,
			'count' => isset($counts[$tag]) ? $counts[$tag] : 0,
		);
	}

	return $tags;
}

function pgc_sgb_media_folders_normalize_image_src($image)
{
	if (!$image || empty($image[0])) {
		return null;
	}

	return array(
		'url'    => $image[0],
		'src'    => $image[0],
		'width'  => isset($image[1]) ? (int) $image[1] : 0,
		'height' => isset($image[2]) ? (int) $image[2] : 0,
	);
}

function pgc_sgb_media_folders_is_poster_debug_enabled($request = null)
{
	if ($request instanceof WP_REST_Request) {
		return rest_sanitize_boolean($request->get_param('poster_debug')) && current_user_can('upload_files');
	}

	return defined('PGC_SGB_MEDIA_FOLDERS_DEBUG_POSTERS') && PGC_SGB_MEDIA_FOLDERS_DEBUG_POSTERS;
}

function pgc_sgb_media_folders_poster_debug_log($message, $context = array(), $enabled = false)
{
	if (!$enabled || !current_user_can('upload_files')) {
		return;
	}

	error_log('[SGB MediaAssistant poster] ' . $message . ' ' . wp_json_encode($context));
}

function pgc_sgb_media_folders_get_attachment_poster($attachment_id, $type, $debug_posters = false)
{
	if (!in_array($type, array('audio', 'video'), true)) {
		return null;
	}

	$poster_id = (int) get_post_thumbnail_id($attachment_id);
	$raw_poster_id = get_post_meta($attachment_id, '_thumbnail_id', true);

	pgc_sgb_media_folders_poster_debug_log(
		'read attachment poster meta',
		array(
			'attachmentId' => (int) $attachment_id,
			'type'         => $type,
			'rawMeta'      => $raw_poster_id,
			'posterId'     => $poster_id,
		),
		$debug_posters
	);

	if ($poster_id <= 0) {
		return null;
	}

	$poster_post = get_post($poster_id);
	$poster = array(
		'id' => $poster_id,
	);

	foreach (array('thumbnail', 'medium', 'full') as $size) {
		$image = pgc_sgb_media_folders_normalize_image_src(wp_get_attachment_image_src($poster_id, $size));

		if ($image) {
			$poster[$size] = $image;
		}
	}

	pgc_sgb_media_folders_poster_debug_log(
		'resolved attachment poster image sizes',
		array(
			'attachmentId' => (int) $attachment_id,
			'posterId'     => $poster_id,
			'posterType'   => $poster_post ? $poster_post->post_type : '',
			'posterStatus' => $poster_post ? $poster_post->post_status : '',
			'posterMime'   => get_post_mime_type($poster_id),
			'hasThumbnail' => !empty($poster['thumbnail']['url']),
			'hasMedium'    => !empty($poster['medium']['url']),
			'hasFull'      => !empty($poster['full']['url']),
			'poster'       => $poster,
		),
		$debug_posters
	);

	return count($poster) > 1 ? $poster : null;
}

function pgc_sgb_media_folders_normalize_media_item($post, $debug_posters = false)
{
	$attachment_id = (int) $post->ID;
	$mime_type = get_post_mime_type($attachment_id);
	$type = $mime_type ? strtok($mime_type, '/') : '';
	$url = wp_get_attachment_url($attachment_id);
	$thumbnail = wp_get_attachment_image_src($attachment_id, 'medium');
	$poster = pgc_sgb_media_folders_get_attachment_poster($attachment_id, $type, $debug_posters);

	if (!$thumbnail) {
		$thumbnail = wp_get_attachment_image_src($attachment_id, 'thumbnail');
	}

	if (!$thumbnail && $poster) {
		if (!empty($poster['medium']['url'])) {
			$thumbnail = array($poster['medium']['url']);
		} elseif (!empty($poster['thumbnail']['url'])) {
			$thumbnail = array($poster['thumbnail']['url']);
		} elseif (!empty($poster['full']['url'])) {
			$thumbnail = array($poster['full']['url']);
		}
	}

	$file = get_attached_file($attachment_id);
	$filename = $file ? wp_basename($file) : '';
	$folder_terms = wp_get_object_terms($attachment_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY, array('fields' => 'ids'));
	$tags = get_post_meta($attachment_id, 'pgc_sgb_tag');

	$item = array(
		'id'          => $attachment_id,
		'title'       => get_the_title($attachment_id),
		'filename'    => $filename,
		'mime'        => $mime_type ? $mime_type : '',
		'type'        => $type ? $type : '',
		'url'         => $url ? $url : '',
		'thumbnail'   => $thumbnail ? $thumbnail[0] : '',
		'editUrl'     => get_edit_post_link($attachment_id, 'raw'),
		'date'        => get_the_date('', $attachment_id),
		'status'      => get_post_status($attachment_id),
		'folderIds'   => is_wp_error($folder_terms) ? array() : array_map('intval', $folder_terms),
		'tags'        => array_values(array_map('sanitize_text_field', $tags)),
		'parentId'    => (int) $post->post_parent,
		'isAttached'  => (int) $post->post_parent > 0,
	);

	if ($poster) {
		$item['poster'] = $poster;

		if (!empty($poster['full'])) {
			$item['image'] = $poster['full'];
		}

		if (!empty($poster['thumbnail'])) {
			$item['thumb'] = $poster['thumbnail'];
		}
	}

	if (in_array($type, array('audio', 'video'), true)) {
		pgc_sgb_media_folders_poster_debug_log(
			'normalized media item',
			array(
				'id'              => $attachment_id,
				'title'           => get_the_title($attachment_id),
				'mime'            => $mime_type ? $mime_type : '',
				'type'            => $type ? $type : '',
				'thumbnail'       => $item['thumbnail'],
				'hasPoster'       => !empty($item['poster']),
				'hasImage'        => !empty($item['image']['url']),
				'hasThumb'        => !empty($item['thumb']['url']),
				'rawThumbnailId'  => get_post_meta($attachment_id, '_thumbnail_id', true),
				'postThumbnailId' => (int) get_post_thumbnail_id($attachment_id),
				'item'            => $item,
			),
			$debug_posters
		);
	}

	return $item;
}

function pgc_sgb_media_folders_rest_get_folders()
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to view media folders.', 'simply-gallery-block'), array('status' => 403));
	}

	$counts = pgc_sgb_media_folders_get_counts();
	$folders = array();

	foreach (pgc_sgb_media_folders_get_terms() as $term) {
		$folders[] = pgc_sgb_media_folders_normalize_term($term, isset($counts[(string) $term->term_id]) ? $counts[(string) $term->term_id] : null);
	}

	return rest_ensure_response($folders);
}

function pgc_sgb_media_folders_rest_get_media(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to view media.', 'simply-gallery-block'), array('status' => 403));
	}

	$folder_param = $request->get_param('folder_id');
	$folder_id = is_null($folder_param) ? -1 : (int) $folder_param;
	$type = sanitize_key((string) $request->get_param('type'));
	$tag = pgc_sgb_media_folders_normalize_tag_name($request->get_param('tag'));
	$search = sanitize_text_field((string) $request->get_param('search'));
	$page = max(1, absint($request->get_param('page')));
	$per_page = absint($request->get_param('per_page'));
	$sort_by = sanitize_key((string) $request->get_param('sort_by'));
	$order = strtoupper(sanitize_key((string) $request->get_param('order')));
	$exclude_ids = array_values(array_filter(wp_parse_id_list($request->get_param('exclude_ids'))));
	$debug_posters = pgc_sgb_media_folders_is_poster_debug_enabled($request);

	if (!in_array($per_page, array(20, 50, 100), true)) {
		$per_page = 20;
	}

	if (!in_array($sort_by, array('date', 'title', 'modified', 'author'), true)) {
		$sort_by = 'date';
	}

	if (!in_array($order, array('ASC', 'DESC'), true)) {
		$order = 'DESC';
	}

	$args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'any',
		'posts_per_page'         => $per_page,
		'paged'                  => $page,
		'orderby'                => $sort_by,
		'order'                  => $order,
		'no_found_rows'          => false,
		'suppress_filters'       => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => true,
	);

	if (!empty($exclude_ids)) {
		$args['post__not_in'] = $exclude_ids;
	}

	$mime_query = pgc_sgb_media_folders_get_type_mime_query($type);
	if (!empty($mime_query)) {
		$args['post_mime_type'] = $mime_query;
	}

	if ($search !== '') {
		$args['s'] = $search;
	}

	$args = pgc_sgb_media_folders_apply_query_filter($args, $folder_id);

	if ($tag !== '') {
		if (empty($args['meta_query'])) {
			$args['meta_query'] = array();
		}

		$args['meta_query'][] = array(
			'key'     => 'pgc_sgb_tag',
			'value'   => $tag,
			'compare' => '=',
		);
	}

	pgc_sgb_media_folders_poster_debug_log(
		'media REST query',
		array(
			'folderId' => $folder_id,
			'type'     => $type === '' ? 'all' : $type,
			'search'   => $search,
			'page'     => $page,
			'perPage'  => $per_page,
			'sortBy'   => $sort_by,
			'order'    => $order,
			'queryArgs' => $args,
		),
		$debug_posters
	);

	$query = new WP_Query($args);
	$items = array();

	foreach ($query->posts as $post) {
		$items[] = pgc_sgb_media_folders_normalize_media_item($post, $debug_posters);
	}

	$response = rest_ensure_response(
		array(
			'items'      => $items,
			'total'      => (int) $query->found_posts,
			'totalPages' => (int) $query->max_num_pages,
			'page'       => $page,
			'perPage'    => $per_page,
			'folderId'   => $folder_id,
			'type'       => $type === '' ? 'all' : $type,
			'tag'        => $tag,
			'search'     => $search,
			'sortBy'     => $sort_by,
			'order'      => $order,
			'excludeIds' => $exclude_ids,
		)
	);

	wp_reset_postdata();

	return $response;
}

function pgc_sgb_media_folders_rest_get_tags()
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to view media tags.', 'simply-gallery-block'), array('status' => 403));
	}

	return rest_ensure_response(
		array(
			'tags' => pgc_sgb_media_folders_get_normalized_tags(),
		)
	);
}

function pgc_sgb_media_folders_rest_create_tags(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to create media tags.', 'simply-gallery-block'), array('status' => 403));
	}

	$tags = pgc_sgb_media_folders_get_request_tags($request);
	if (empty($tags)) {
		return new WP_Error('empty_tags', __('No tags were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if (function_exists('pgc_sgb_update_tags_list')) {
		pgc_sgb_update_tags_list($tags);
	}

	return rest_ensure_response(
		array(
			'tags' => pgc_sgb_media_folders_get_normalized_tags(),
		)
	);
}

function pgc_sgb_media_folders_rest_delete_tags(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to delete media tags.', 'simply-gallery-block'), array('status' => 403));
	}

	$tags = pgc_sgb_media_folders_get_request_tags($request);
	if (empty($tags)) {
		return new WP_Error('empty_tags', __('No tags were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if (function_exists('pgc_sgb_update_tags_list')) {
		pgc_sgb_update_tags_list($tags, true);
	}

	return rest_ensure_response(
		array(
			'deleted' => $tags,
			'tags'    => pgc_sgb_media_folders_get_normalized_tags(),
		)
	);
}

function pgc_sgb_media_folders_rest_create_folder(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to create media folders.', 'simply-gallery-block'), array('status' => 403));
	}

	$name = sanitize_text_field((string) $request->get_param('name'));
	if ($name === '') {
		return new WP_Error('empty_name', __('Folder name cannot be empty.', 'simply-gallery-block'), array('status' => 400));
	}

	$existing = term_exists($name, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if ($existing && !is_wp_error($existing)) {
		return new WP_Error('folder_exists', __('A folder with this name already exists.', 'simply-gallery-block'), array('status' => 409));
	}

	$inserted = wp_insert_term($name, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (is_wp_error($inserted)) {
		return $inserted;
	}

	$term_id = (int) $inserted['term_id'];
	$order = $request->get_param('order');
	if (is_null($order)) {
		$order = count(pgc_sgb_media_folders_get_terms()) + 1;
	}
	update_term_meta($term_id, PGC_SGB_MEDIA_FOLDER_ORDER_META, (int) $order);

	$color = pgc_sgb_media_folders_sanitize_color((string) $request->get_param('color'));
	if ($color !== '') {
		update_term_meta($term_id, PGC_SGB_MEDIA_FOLDER_COLOR_META, $color);
	}

	$term = get_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);

	return rest_ensure_response(pgc_sgb_media_folders_normalize_term($term, 0));
}

function pgc_sgb_media_folders_rest_update_folder(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to update media folders.', 'simply-gallery-block'), array('status' => 403));
	}

	$term_id = absint($request->get_param('id'));
	$term = get_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$term || is_wp_error($term)) {
		return new WP_Error('folder_not_found', __('Media folder not found.', 'simply-gallery-block'), array('status' => 404));
	}

	$args = array();
	if (!is_null($request->get_param('name'))) {
		$name = sanitize_text_field((string) $request->get_param('name'));
		if ($name === '') {
			return new WP_Error('empty_name', __('Folder name cannot be empty.', 'simply-gallery-block'), array('status' => 400));
		}
		$args['name'] = $name;
	}

	if (!empty($args)) {
		$updated = wp_update_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY, $args);
		if (is_wp_error($updated)) {
			return $updated;
		}
	}

	if (!is_null($request->get_param('color'))) {
		$color = pgc_sgb_media_folders_sanitize_color((string) $request->get_param('color'));
		if ($color === '') {
			delete_term_meta($term_id, PGC_SGB_MEDIA_FOLDER_COLOR_META);
		} else {
			update_term_meta($term_id, PGC_SGB_MEDIA_FOLDER_COLOR_META, $color);
		}
	}

	if (!is_null($request->get_param('order'))) {
		update_term_meta($term_id, PGC_SGB_MEDIA_FOLDER_ORDER_META, (int) $request->get_param('order'));
	}

	$term = get_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	$counts = pgc_sgb_media_folders_get_counts();

	return rest_ensure_response(pgc_sgb_media_folders_normalize_term($term, isset($counts[(string) $term_id]) ? $counts[(string) $term_id] : null));
}

function pgc_sgb_media_folders_rest_delete_folder(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to delete media folders.', 'simply-gallery-block'), array('status' => 403));
	}

	$term_id = absint($request->get_param('id'));
	$term = get_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (!$term || is_wp_error($term)) {
		return new WP_Error('folder_not_found', __('Media folder not found.', 'simply-gallery-block'), array('status' => 404));
	}

	$deleted = wp_delete_term($term_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
	if (is_wp_error($deleted)) {
		return $deleted;
	}

	return rest_ensure_response(array('deleted' => (bool) $deleted));
}

function pgc_sgb_media_folders_rest_assign_media(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to assign media folders.', 'simply-gallery-block'), array('status' => 403));
	}

	$folder_id = (int) $request->get_param('folder_id');
	$attachment_ids = $request->get_param('attachment_ids');

	if (!is_array($attachment_ids)) {
		$attachment_ids = array($attachment_ids);
	}

	$attachment_ids = array_values(array_filter(array_map('absint', $attachment_ids)));
	if (empty($attachment_ids)) {
		return new WP_Error('empty_attachments', __('No media items were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if ($folder_id > 0) {
		$term = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
		if (!$term || is_wp_error($term)) {
			return new WP_Error('folder_not_found', __('Media folder not found.', 'simply-gallery-block'), array('status' => 404));
		}
	}

	$updated = array();
	$skipped = array();

	foreach ($attachment_ids as $attachment_id) {
		if (!current_user_can('edit_post', $attachment_id) || get_post_type($attachment_id) !== 'attachment') {
			$skipped[] = $attachment_id;
			continue;
		}

		$terms = $folder_id > 0 ? array($folder_id) : array();
		$result = wp_set_object_terms($attachment_id, $terms, PGC_SGB_MEDIA_FOLDER_TAXONOMY, false);
		if (is_wp_error($result)) {
			$skipped[] = $attachment_id;
			continue;
		}

		$updated[] = $attachment_id;
	}

	return rest_ensure_response(
		array(
			'updated'   => $updated,
			'skipped'   => $skipped,
			'folder_id' => $folder_id,
			'counts'    => pgc_sgb_media_folders_get_counts(),
		)
	);
}

function pgc_sgb_media_folders_rest_delete_media(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to delete media.', 'simply-gallery-block'), array('status' => 403));
	}

	$attachment_ids = $request->get_param('attachment_ids');

	if (!is_array($attachment_ids)) {
		$attachment_ids = array($attachment_ids);
	}

	$attachment_ids = array_values(array_unique(array_filter(array_map('absint', $attachment_ids))));
	if (empty($attachment_ids)) {
		return new WP_Error('empty_attachments', __('No media items were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	$deleted = array();
	$skipped = array();
	$errors = array();

	foreach ($attachment_ids as $attachment_id) {
		if (get_post_type($attachment_id) !== 'attachment') {
			$skipped[] = $attachment_id;
			$errors[(string) $attachment_id] = __('Media item not found.', 'simply-gallery-block');
			continue;
		}

		if (!current_user_can('delete_post', $attachment_id)) {
			$skipped[] = $attachment_id;
			$errors[(string) $attachment_id] = __('You are not allowed to delete this media item.', 'simply-gallery-block');
			continue;
		}

		$result = wp_delete_attachment($attachment_id, true);
		if (!$result) {
			$skipped[] = $attachment_id;
			$errors[(string) $attachment_id] = __('Media item could not be deleted.', 'simply-gallery-block');
			continue;
		}

		$deleted[] = $attachment_id;
	}

	return rest_ensure_response(
		array(
			'deleted' => $deleted,
			'skipped' => $skipped,
			'errors'  => $errors,
			'counts'  => pgc_sgb_media_folders_get_counts(),
			'tags'    => pgc_sgb_media_folders_get_normalized_tags(),
		)
	);
}

function pgc_sgb_media_folders_rest_assign_media_tags(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to assign media tags.', 'simply-gallery-block'), array('status' => 403));
	}

	$attachment_ids = $request->get_param('attachment_ids');
	$tags = pgc_sgb_media_folders_get_request_tags($request);

	if (!is_array($attachment_ids)) {
		$attachment_ids = array($attachment_ids);
	}

	$attachment_ids = array_values(array_filter(array_map('absint', $attachment_ids)));
	if (empty($attachment_ids)) {
		return new WP_Error('empty_attachments', __('No media items were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if (empty($tags)) {
		return new WP_Error('empty_tags', __('No tags were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	$updated = array();
	$skipped = array();
	$assigned = array();

	foreach ($attachment_ids as $attachment_id) {
		if (!current_user_can('edit_post', $attachment_id) || get_post_type($attachment_id) !== 'attachment') {
			$skipped[] = $attachment_id;
			continue;
		}

		$current_tags = get_post_meta($attachment_id, 'pgc_sgb_tag');
		$added_tags = array();

		foreach ($tags as $tag) {
			if ($tag !== '' && !in_array($tag, $current_tags, true)) {
				if (add_post_meta($attachment_id, 'pgc_sgb_tag', $tag, false)) {
					$added_tags[] = $tag;
					$current_tags[] = $tag;
				}
			}
		}

		$updated[] = $attachment_id;
		$assigned[(string) $attachment_id] = $added_tags;
	}

	if (function_exists('pgc_sgb_update_tags_list')) {
		pgc_sgb_update_tags_list($tags);
	}

	return rest_ensure_response(
		array(
			'updated'  => $updated,
			'skipped'  => $skipped,
			'assigned' => $assigned,
			'tags'     => pgc_sgb_media_folders_get_normalized_tags(),
		)
	);
}

function pgc_sgb_media_folders_rest_remove_media_tags(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to remove media tags.', 'simply-gallery-block'), array('status' => 403));
	}

	$attachment_ids = $request->get_param('attachment_ids');
	$tags = pgc_sgb_media_folders_get_request_tags($request);

	if (!is_array($attachment_ids)) {
		$attachment_ids = array($attachment_ids);
	}

	$attachment_ids = array_values(array_filter(array_map('absint', $attachment_ids)));
	if (empty($attachment_ids)) {
		return new WP_Error('empty_attachments', __('No media items were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if (empty($tags)) {
		return new WP_Error('empty_tags', __('No tags were provided.', 'simply-gallery-block'), array('status' => 400));
	}

	if (!function_exists('pgc_sgb_delete_post_tags_meta')) {
		return new WP_Error('missing_tags_helper', __('Media tag removal is not available.', 'simply-gallery-block'), array('status' => 500));
	}

	$result = pgc_sgb_delete_post_tags_meta($attachment_ids, $tags, true);

	return rest_ensure_response(
		array(
			'deleted' => $result['deleted'],
			'skipped' => $result['skipped'],
		)
	);
}

function pgc_sgb_media_folders_rest_get_counts()
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to view media folder counts.', 'simply-gallery-block'), array('status' => 403));
	}

	return rest_ensure_response(pgc_sgb_media_folders_get_counts());
}

function pgc_sgb_media_folders_rest_save_active_folder(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to update media folder state.', 'simply-gallery-block'), array('status' => 403));
	}

	$folder_id = (int) $request->get_param('folder_id');
	if ($folder_id < -1) {
		$folder_id = -1;
	}

	if ($folder_id > 0) {
		$term = get_term($folder_id, PGC_SGB_MEDIA_FOLDER_TAXONOMY);
		if (!$term || is_wp_error($term)) {
			return new WP_Error('folder_not_found', __('Media folder not found.', 'simply-gallery-block'), array('status' => 404));
		}
	}

	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_FOLDER_ACTIVE_META, $folder_id);

	return rest_ensure_response(array('folder_id' => $folder_id));
}

function pgc_sgb_media_folders_rest_save_panel_state(WP_REST_Request $request)
{
	if (!pgc_sgb_media_folders_can_use()) {
		return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to update media folder state.', 'simply-gallery-block'), array('status' => 403));
	}

	$collapsed = rest_sanitize_boolean($request->get_param('collapsed'));
	update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_FOLDERS_COLLAPSED_META, $collapsed ? '1' : '0');

	return rest_ensure_response(
		array(
			'collapsed' => (bool) $collapsed,
		)
	);
}

function pgc_sgb_media_folders_register_rest_routes()
{
	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/folders',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_get_folders',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_create_folder',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
				'args'                => array(
					'name'  => array('required' => true, 'type' => 'string'),
					'color' => array('required' => false, 'type' => 'string'),
					'order' => array('required' => false, 'type' => 'integer'),
				),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/folders/(?P<id>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_update_folder',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_delete_folder',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media/assign',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_assign_media',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'folder_id'      => array('required' => true, 'type' => 'integer'),
				'attachment_ids' => array('required' => true, 'type' => 'array'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media/delete',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_delete_media',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'attachment_ids' => array('required' => true, 'type' => 'array'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/tags',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_get_tags',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_create_tags',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
				'args'                => array(
					'tags' => array('required' => false, 'type' => 'array'),
					'tag'  => array('required' => false, 'type' => 'string'),
					'name' => array('required' => false, 'type' => 'string'),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'pgc_sgb_media_folders_rest_delete_tags',
				'permission_callback' => 'pgc_sgb_media_folders_can_use',
				'args'                => array(
					'tags' => array('required' => false, 'type' => 'array'),
					'tag'  => array('required' => false, 'type' => 'string'),
					'name' => array('required' => false, 'type' => 'string'),
				),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media/tags/assign',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_assign_media_tags',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'attachment_ids' => array('required' => true, 'type' => 'array'),
				'tags'           => array('required' => false, 'type' => 'array'),
				'tag'            => array('required' => false, 'type' => 'string'),
				'name'           => array('required' => false, 'type' => 'string'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media/tags/remove',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_remove_media_tags',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'attachment_ids' => array('required' => true, 'type' => 'array'),
				'tags'           => array('required' => false, 'type' => 'array'),
				'tag'            => array('required' => false, 'type' => 'string'),
				'name'           => array('required' => false, 'type' => 'string'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_get_media',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'folder_id' => array('required' => false, 'type' => 'integer'),
				'type'      => array('required' => false, 'type' => 'string'),
				'search'    => array('required' => false, 'type' => 'string'),
				'page'      => array('required' => false, 'type' => 'integer'),
				'per_page'  => array('required' => false, 'type' => 'integer'),
				'sort_by'   => array('required' => false, 'type' => 'string'),
				'order'     => array('required' => false, 'type' => 'string'),
				'tag'         => array('required' => false, 'type' => 'string'),
				'exclude_ids' => array('required' => false, 'type' => 'string'),
				'poster_debug' => array('required' => false, 'type' => 'boolean'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/media/counts',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_get_counts',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/user/active-folder',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_save_active_folder',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'folder_id' => array('required' => true, 'type' => 'integer'),
			),
		)
	);

	register_rest_route(
		'pgc-sgb/v1',
		'/media-folders/user/panel-state',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'pgc_sgb_media_folders_rest_save_panel_state',
			'permission_callback' => 'pgc_sgb_media_folders_can_use',
			'args'                => array(
				'collapsed' => array('required' => true, 'type' => 'boolean'),
			),
		)
	);
}
add_action('rest_api_init', 'pgc_sgb_media_folders_register_rest_routes');

function pgc_sgb_media_folders_add_assistant_page()
{
	$GLOBALS['pgc_sgb_media_folders_assistant_hook'] = add_submenu_page(
		'upload.php',
		__('SimpLy Assistant', 'simply-gallery-block'),
		__('SimpLy Assistant', 'simply-gallery-block'),
		'upload_files',
		PGC_SGB_MEDIA_FOLDERS_ASSISTANT_PAGE,
		'pgc_sgb_media_folders_render_assistant_page',
		25
	);

	if (!empty($GLOBALS['pgc_sgb_media_folders_assistant_hook'])) {
		add_action('load-' . $GLOBALS['pgc_sgb_media_folders_assistant_hook'], 'pgc_sgb_media_folders_load_assistant_page');
	}
}
add_action('admin_menu', 'pgc_sgb_media_folders_add_assistant_page');

function pgc_sgb_media_folders_load_assistant_page()
{
	add_filter('screen_options_show_screen', 'pgc_sgb_media_folders_show_assistant_screen_options', 10, 2);
	add_filter('screen_settings', 'pgc_sgb_media_folders_render_assistant_screen_settings', 10, 2);
	add_filter('gettext', 'pgc_sgb_media_folders_rename_assistant_screen_options', 10, 3);

	if (
		isset($_POST['pgc_sgb_media_folders_screen_settings_nonce'])
		&& wp_verify_nonce(
			sanitize_text_field(wp_unslash($_POST['pgc_sgb_media_folders_screen_settings_nonce'])),
			'pgc_sgb_media_folders_screen_settings'
		)
		&& current_user_can('upload_files')
	) {
		update_user_meta(
			get_current_user_id(),
			PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META,
			isset($_POST['pgc_sgb_media_folders_show_library']) ? '1' : '0'
		);
		update_user_meta(
			get_current_user_id(),
			PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META,
			isset($_POST['pgc_sgb_media_folders_show_modal']) ? '1' : '0'
		);

		$assistant_per_page = isset($_POST['pgc_sgb_media_assistant_per_page'])
			? absint(wp_unslash($_POST['pgc_sgb_media_assistant_per_page']))
			: 20;
		if (!in_array($assistant_per_page, array(20, 50, 100), true)) {
			$assistant_per_page = 20;
		}

		$assistant_sort_by = isset($_POST['pgc_sgb_media_assistant_sort_by'])
			? sanitize_key(wp_unslash($_POST['pgc_sgb_media_assistant_sort_by']))
			: 'date';
		if (!in_array($assistant_sort_by, array('date', 'title', 'modified', 'author'), true)) {
			$assistant_sort_by = 'date';
		}

		$assistant_order = isset($_POST['pgc_sgb_media_assistant_order'])
			? strtoupper(sanitize_key(wp_unslash($_POST['pgc_sgb_media_assistant_order'])))
			: 'DESC';
		if (!in_array($assistant_order, array('ASC', 'DESC'), true)) {
			$assistant_order = 'DESC';
		}

		update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PER_PAGE_META, $assistant_per_page);
		update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_SORT_BY_META, $assistant_sort_by);
		update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_ORDER_META, $assistant_order);
		update_user_meta(
			get_current_user_id(),
			PGC_SGB_MEDIA_ASSISTANT_REMEMBER_FOLDER_META,
			isset($_POST['pgc_sgb_media_assistant_remember_folder']) ? '1' : '0'
		);

		$assistant_picker_mode = isset($_POST['pgc_sgb_media_assistant_picker_mode'])
			? sanitize_key(wp_unslash($_POST['pgc_sgb_media_assistant_picker_mode']))
			: 'select';
		if (!in_array($assistant_picker_mode, array('native', 'select'), true)) {
			$assistant_picker_mode = 'select';
		}

		update_user_meta(get_current_user_id(), PGC_SGB_MEDIA_ASSISTANT_PICKER_MODE_META, $assistant_picker_mode);
	}
}

function pgc_sgb_media_folders_rename_assistant_screen_options($translation, $text, $domain)
{
	if ($domain !== 'default' || $text !== 'Screen Options') {
		return $translation;
	}

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;

	if (
		!$screen
		|| empty($GLOBALS['pgc_sgb_media_folders_assistant_hook'])
		|| $screen->id !== $GLOBALS['pgc_sgb_media_folders_assistant_hook']
	) {
		return $translation;
	}

	return __('Options', 'simply-gallery-block');
}

function pgc_sgb_media_folders_show_assistant_screen_options($show_screen, $screen)
{
	if (!empty($GLOBALS['pgc_sgb_media_folders_assistant_hook']) && $screen->id === $GLOBALS['pgc_sgb_media_folders_assistant_hook']) {
		return true;
	}

	return $show_screen;
}

function pgc_sgb_media_folders_render_assistant_screen_settings($settings, $screen)
{
	if (empty($GLOBALS['pgc_sgb_media_folders_assistant_hook']) || $screen->id !== $GLOBALS['pgc_sgb_media_folders_assistant_hook']) {
		return $settings;
	}

	$show_library = pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META);
	$show_modal = pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META);
	$assistant_per_page = pgc_sgb_media_folders_get_assistant_per_page();
	$assistant_sort_by = pgc_sgb_media_folders_get_assistant_sort_by();
	$assistant_order = pgc_sgb_media_folders_get_assistant_order();
	$assistant_remember_folder = pgc_sgb_media_folders_get_assistant_remember_folder();
	$assistant_picker_mode = pgc_sgb_media_folders_get_assistant_picker_mode();

	ob_start();
?>
	<div class="pgc-sgb-media-folders-screen-settings">
		<fieldset class="metabox-prefs pgc-sgb-media-folders-screen-settings-section">
			<legend><?php esc_html_e('SimpLy Folders', 'simply-gallery-block'); ?></legend>
			<label for="pgc-sgb-media-folders-show-library">
				<input
					type="checkbox"
					id="pgc-sgb-media-folders-show-library"
					name="pgc_sgb_media_folders_show_library"
					value="1"
					<?php checked($show_library); ?> />
				<?php esc_html_e('Show folders panel in Media Library', 'simply-gallery-block'); ?>
			</label>
			<label for="pgc-sgb-media-folders-show-modal">
				<input
					type="checkbox"
					id="pgc-sgb-media-folders-show-modal"
					name="pgc_sgb_media_folders_show_modal"
					value="1"
					<?php checked($show_modal); ?> />
				<?php esc_html_e('Show folders panel in Media Select modal', 'simply-gallery-block'); ?>
			</label>
		</fieldset>
		<hr />
		<fieldset class="metabox-prefs pgc-sgb-media-folders-screen-settings-section">
			<legend><?php esc_html_e('SimpLy gallery block media picker', 'simply-gallery-block'); ?></legend>
			<p class="pgc-sgb-media-folders-screen-settings-description">
				<?php esc_html_e('This choice applies only to SimpLy gallery blocks. We recommend the SimpLy Assistant Picker because it gives direct access to SimpLy folders, tags, upload, and assignment workflows, and is less affected by third-party Media Library plugins. This choice is not critical and can be changed at any time.', 'simply-gallery-block'); ?>
			</p>
			<div class="pgc-spacer"></div>
			<label for="pgc-sgb-media-assistant-picker-mode-select">
				<input
					type="radio"
					id="pgc-sgb-media-assistant-picker-mode-select"
					name="pgc_sgb_media_assistant_picker_mode"
					value="select"
					<?php checked($assistant_picker_mode, 'select'); ?> />
				<?php esc_html_e('SimpLy Assistant Picker', 'simply-gallery-block'); ?>
			</label>
			<label for="pgc-sgb-media-assistant-picker-mode-native">
				<input
					type="radio"
					id="pgc-sgb-media-assistant-picker-mode-native"
					name="pgc_sgb_media_assistant_picker_mode"
					value="native"
					<?php checked($assistant_picker_mode, 'native'); ?> />
				<?php esc_html_e('WordPress Media Library', 'simply-gallery-block'); ?>
			</label>
		</fieldset>
		<hr />
		<fieldset class="metabox-prefs pgc-sgb-media-folders-screen-settings-section">
			<legend><?php esc_html_e('SimpLy Assistant', 'simply-gallery-block'); ?></legend>
			<label for="pgc-sgb-media-assistant-remember-folder">
				<input
					type="checkbox"
					id="pgc-sgb-media-assistant-remember-folder"
					name="pgc_sgb_media_assistant_remember_folder"
					value="1"
					<?php checked($assistant_remember_folder); ?> />
				<?php esc_html_e('Remember selected folder in Assistant', 'simply-gallery-block'); ?>
			</label>
			<div class="pgc-spacer"></div>
			<label for="pgc-sgb-media-assistant-per-page">
				<span><?php esc_html_e('Items per page', 'simply-gallery-block'); ?></span>
				<select id="pgc-sgb-media-assistant-per-page" name="pgc_sgb_media_assistant_per_page">
					<option value="20" <?php selected($assistant_per_page, 20); ?>>20</option>
					<option value="50" <?php selected($assistant_per_page, 50); ?>>50</option>
					<option value="100" <?php selected($assistant_per_page, 100); ?>>100</option>
				</select>
			</label>
			<label for="pgc-sgb-media-assistant-sort-by">
				<span><?php esc_html_e('Sort by', 'simply-gallery-block'); ?></span>
				<select id="pgc-sgb-media-assistant-sort-by" name="pgc_sgb_media_assistant_sort_by">
					<option value="date" <?php selected($assistant_sort_by, 'date'); ?>><?php esc_html_e('Date added', 'simply-gallery-block'); ?></option>
					<option value="title" <?php selected($assistant_sort_by, 'title'); ?>><?php esc_html_e('Title', 'simply-gallery-block'); ?></option>
					<option value="modified" <?php selected($assistant_sort_by, 'modified'); ?>><?php esc_html_e('Date modified', 'simply-gallery-block'); ?></option>
					<option value="author" <?php selected($assistant_sort_by, 'author'); ?>><?php esc_html_e('Author', 'simply-gallery-block'); ?></option>
				</select>
			</label>
			<label for="pgc-sgb-media-assistant-order">
				<span><?php esc_html_e('Order', 'simply-gallery-block'); ?></span>
				<select id="pgc-sgb-media-assistant-order" name="pgc_sgb_media_assistant_order">
					<option value="ASC" <?php selected($assistant_order, 'ASC'); ?>><?php esc_html_e('Sort ascending', 'simply-gallery-block'); ?></option>
					<option value="DESC" <?php selected($assistant_order, 'DESC'); ?>><?php esc_html_e('Sort descending', 'simply-gallery-block'); ?></option>
				</select>
			</label>
		</fieldset>
		<?php wp_nonce_field('pgc_sgb_media_folders_screen_settings', 'pgc_sgb_media_folders_screen_settings_nonce'); ?>
		<div class="pgc-sgb-media-folders-screen-settings-actions">
			<?php submit_button(__('Apply', 'simply-gallery-block'), 'primary', 'pgc_sgb_media_folders_screen_settings_submit', false); ?>
		</div>
	</div>
<?php

	return $settings . ob_get_clean();
}

function pgc_sgb_media_folders_render_assistant_page()
{
	if (!pgc_sgb_media_folders_can_use()) {
		wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'simply-gallery-block'));
	}

	echo '<div class="wrap pgc-sgb-media-assistant-page">';
	echo '<div id="pgc-sgb-media-assistant-root"></div>';
	echo '</div>';
}

function pgc_sgb_media_folders_enqueue_admin_assets($hook = '')
{
	static $did_enqueue = false;

	$pagenow = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
	$is_media_library = ($hook === 'upload.php' || $pagenow === 'upload.php') && empty($_GET['page']);
	$is_assistant_page = isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === PGC_SGB_MEDIA_FOLDERS_ASSISTANT_PAGE;
	$is_wp_enqueue_media = doing_action('wp_enqueue_media');
	$is_modal_host = pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META)
		&& !$is_media_library
		&& !$is_assistant_page
		&& (in_array($hook, array('post.php', 'post-new.php', 'widgets.php', 'customize.php'), true) || $is_wp_enqueue_media);

	if ((!$is_media_library && !$is_assistant_page && !$is_modal_host) || !pgc_sgb_media_folders_can_use()) {
		return;
	}

	if (
		$is_media_library
		&& !pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_LIBRARY_META)
	) {
		return;
	}

	if ($did_enqueue) {
		return;
	}
	$did_enqueue = true;

	$script_handle = PGC_SGB_SLUG . '-media-folders';
	$style_handle = PGC_SGB_SLUG . '-media-folders';
	$bootstrap_handle = PGC_SGB_SLUG . '-media-folders-modal-bootstrap';
	$script_path = PGC_SGB_PATH . '/dist/media_folders.build.js';
	$style_path = PGC_SGB_PATH . '/dist/media_folders.build.style.css';
	$bootstrap_path = PGC_SGB_PATH . '/plugins/media_folders/media-modal-bootstrap.js';
	$script_version = file_exists($script_path) ? PGC_SGB_VERSION : filemtime($script_path);
	$style_version = file_exists($style_path) ? PGC_SGB_VERSION : filemtime($script_path);
	$bootstrap_version = file_exists($bootstrap_path) ? PGC_SGB_VERSION : filemtime($script_path);
	$active_folder_id = $is_assistant_page
		? get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_FOLDER_ACTIVE_META, true)
		: 0;
	$is_collapsed = get_user_meta(get_current_user_id(), PGC_SGB_MEDIA_FOLDERS_COLLAPSED_META, true) === '1';
	$remember_assistant_folder = pgc_sgb_media_folders_get_assistant_remember_folder();

	if ($active_folder_id === '' || ($is_assistant_page && !$remember_assistant_folder)) {
		$active_folder_id = -1;
	}

	$script_dependencies = array('wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-components', 'media-views');

	if ($is_assistant_page || ($is_modal_host && !$is_wp_enqueue_media)) {
		wp_enqueue_media();
	}

	if ($is_assistant_page) {
		wp_enqueue_script('media-grid');
		$script_dependencies[] = 'media-grid';
	}

	wp_enqueue_style(
		$style_handle,
		PGC_SGB_URL . 'dist/media_folders.build.style.css',
		array(),
		$style_version
	);

	if ($is_modal_host) {
		wp_enqueue_script(
			$bootstrap_handle,
			PGC_SGB_URL . 'plugins/media_folders/media-modal-bootstrap.js',
			array('media-views'),
			$bootstrap_version,
			true
		);
		$script_dependencies[] = $bootstrap_handle;
	}

	wp_enqueue_script(
		$script_handle,
		PGC_SGB_URL . 'dist/media_folders.build.js',
		$script_dependencies,
		$script_version,
		true
	);

	wp_localize_script(
		$script_handle,
		'PGC_SGB_MEDIA_FOLDERS',
		array(
			'restBase'        => '/pgc-sgb/v1/media-folders',
			'nonce'           => wp_create_nonce('wp_rest'),
			'currentFolderId' => (int) $active_folder_id,
			'isCollapsed'     => (bool) $is_collapsed,
			'showMediaModal'  => pgc_sgb_media_folders_user_setting_enabled(PGC_SGB_MEDIA_FOLDERS_SHOW_MODAL_META),
			// Host environment for MediaFoldersApp:
			// sidebar = upload.php, assistant = SGB Assistant, modal = native wp-media-modal.
			// Future: select = SGB-owned lightweight Select Files picker.
			'mode'            => $is_assistant_page ? 'assistant' : ($is_media_library ? 'sidebar' : 'modal'),
			'uploadUrl'       => admin_url('upload.php'),
			'mediaUploadUrl'  => esc_url_raw(rest_url('wp/v2/media')),
			'assistantUrl'    => admin_url('upload.php?page=' . PGC_SGB_MEDIA_FOLDERS_ASSISTANT_PAGE),
			'assistant'       => array(
				'perPage'                => pgc_sgb_media_folders_get_assistant_per_page(),
				'sortBy'                 => pgc_sgb_media_folders_get_assistant_sort_by(),
				'order'                  => pgc_sgb_media_folders_get_assistant_order(),
				'rememberSelectedFolder' => $remember_assistant_folder,
				'pickerMode'             => pgc_sgb_media_folders_get_assistant_picker_mode(),
			),
		)
	);
}
add_action('admin_enqueue_scripts', 'pgc_sgb_media_folders_enqueue_admin_assets');
add_action('wp_enqueue_media', 'pgc_sgb_media_folders_enqueue_admin_assets');

function pgc_sgb_media_folders_apply_query_filter($query, $folder_id)
{
	$folder_id = (int) $folder_id;
	if ($folder_id === -1) {
		return $query;
	}

	if ($folder_id === 0) {
		$query['tax_query'] = isset($query['tax_query']) && is_array($query['tax_query']) ? $query['tax_query'] : array();
		$query['tax_query'][] = array(
			'taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY,
			'operator' => 'NOT EXISTS',
		);
		return $query;
	}

	if ($folder_id > 0) {
		$query['tax_query'] = isset($query['tax_query']) && is_array($query['tax_query']) ? $query['tax_query'] : array();
		$query['tax_query'][] = array(
			'taxonomy' => PGC_SGB_MEDIA_FOLDER_TAXONOMY,
			'field'    => 'term_id',
			'terms'    => array($folder_id),
		);
	}

	return $query;
}

function pgc_sgb_media_folders_apply_tag_query_filter($query, $tag)
{
	$tag = pgc_sgb_media_folders_normalize_tag_name($tag);

	if ($tag === '') {
		return $query;
	}

	$query['meta_query'] = isset($query['meta_query']) && is_array($query['meta_query']) ? $query['meta_query'] : array();
	$query['meta_query'][] = array(
		'key'     => 'pgc_sgb_tag',
		'value'   => $tag,
		'compare' => '=',
	);

	return $query;
}

function pgc_sgb_media_folders_filter_ajax_attachments($query)
{
	if (!pgc_sgb_media_folders_native_ui_enabled()) {
		return $query;
	}

	$folder_id = null;
	$tag = '';

	if (isset($_REQUEST['query']['pgc_sgb_media_folder'])) {
		$folder_id = wp_unslash($_REQUEST['query']['pgc_sgb_media_folder']);
	}

	if (isset($_REQUEST['query']['pgc_sgb_media_tag'])) {
		$tag = pgc_sgb_media_folders_normalize_tag_name(wp_unslash($_REQUEST['query']['pgc_sgb_media_tag']));
	}

	if (is_null($folder_id) && $tag === '') {
		return $query;
	}

	if (!is_null($folder_id)) {
		$query = pgc_sgb_media_folders_apply_query_filter($query, (int) $folder_id);
	}

	if ($tag !== '') {
		$query = pgc_sgb_media_folders_apply_tag_query_filter($query, $tag);
	}

	return $query;
}
add_filter('ajax_query_attachments_args', 'pgc_sgb_media_folders_filter_ajax_attachments', 30);

function pgc_sgb_media_folders_is_upload_list_request()
{
	$pagenow = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';

	if ($pagenow !== 'upload.php') {
		return false;
	}

	$mode = isset($_GET['mode'])
		? sanitize_key(wp_unslash($_GET['mode']))
		: get_user_option('media_library_mode', get_current_user_id());

	return $mode === 'list';
}

function pgc_sgb_media_folders_filter_upload_list_attachments($query)
{
	if (
		!is_admin()
		|| !$query instanceof WP_Query
		|| !$query->is_main_query()
		|| !pgc_sgb_media_folders_native_ui_enabled()
		|| !pgc_sgb_media_folders_is_upload_list_request()
	) {
		return;
	}

	$post_type = $query->get('post_type');

	if ($post_type && $post_type !== 'attachment') {
		return;
	}

	$folder_id = isset($_GET['pgc_sgb_media_folder'])
		? (int) wp_unslash($_GET['pgc_sgb_media_folder'])
		: 0;
	$tag = isset($_GET['pgc_sgb_media_tag'])
		? pgc_sgb_media_folders_normalize_tag_name(wp_unslash($_GET['pgc_sgb_media_tag']))
		: '';
	$query_args = array(
		'tax_query'  => $query->get('tax_query'),
		'meta_query' => $query->get('meta_query'),
	);

	$query_args = pgc_sgb_media_folders_apply_query_filter($query_args, $folder_id);
	$query_args = pgc_sgb_media_folders_apply_tag_query_filter($query_args, $tag);

	if (isset($query_args['tax_query'])) {
		$query->set('tax_query', $query_args['tax_query']);
	}

	if (isset($query_args['meta_query'])) {
		$query->set('meta_query', $query_args['meta_query']);
	}
}
add_action('pre_get_posts', 'pgc_sgb_media_folders_filter_upload_list_attachments', 30);
