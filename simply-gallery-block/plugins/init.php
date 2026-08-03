<?php

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
function pgc_sgb_plugin_init() {
    global $pgc_sgb_global_lightbox_use;
    $pgc_sgb_global_lightbox_use = get_option( 'pgc_sgb_global_lightbox_use' );
    register_meta( 'post', 'pgc_sgb_lightbox_settings', array(
        'show_in_rest'      => true,
        'type'              => 'string',
        'single'            => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => function () {
            return current_user_can( 'edit_posts' );
        },
    ) );
    wp_register_style(
        PGC_SGB_PLUGIN_SLUG . '-editor',
        PGC_SGB_URL . 'dist/plugin.build.style.css',
        array('wp-edit-blocks'),
        PGC_SGB_VERSION
    );
    wp_register_script(
        PGC_SGB_PLUGIN_SLUG . '-script',
        PGC_SGB_URL . 'dist/plugin.build.js',
        array(
            'wp-plugins',
            'wp-edit-post',
            'wp-element',
            'wp-i18n',
            'wp-components',
            'wp-data'
        ),
        PGC_SGB_VERSION,
        true
    );
    $globalJS = array(
        'ajaxurl'        => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'pgc-sgb-nonce' ),
        'lightboxPreset' => pgc_sgb_get_preset_by_slug( 'pgc_sgb_lightbox' ),
        'globalLightbox' => $pgc_sgb_global_lightbox_use,
    );
    wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-script', 'PGC_SGB_LIGHTBOX', $globalJS );
    if ( function_exists( 'wp_set_script_translations' ) ) {
        wp_set_script_translations( PGC_SGB_PLUGIN_SLUG . '-script', 'simply-gallery-block', PGC_SGB_URL . 'languages' );
    }
    if ( !is_admin() ) {
        return;
    }
    $rewrite_version = get_option( 'pgc_sgb_gallery_rewrite_rules_version', '' );
    $has_gallery_rewrite_rules = pgc_sgb_has_gallery_rewrite_rules();
    if ( $rewrite_version !== PGC_SGB_VERSION || !$has_gallery_rewrite_rules ) {
        flush_rewrite_rules( false );
        update_option( 'pgc_sgb_gallery_rewrite_rules_version', PGC_SGB_VERSION, false );
    }
}

function pgc_sgb_has_gallery_rewrite_rules() {
    if ( !get_option( 'permalink_structure' ) ) {
        return true;
    }
    $rewrite_rules = get_option( 'rewrite_rules' );
    if ( !is_array( $rewrite_rules ) || empty( $rewrite_rules ) ) {
        return false;
    }
    $default_base = 'pgc_simply_gallery';
    $gallery_base = get_option( 'pgc_sgb_galleries_base' );
    $gallery_base = ( $gallery_base ? $gallery_base : $default_base );
    $gallery_base = trim( (string) $gallery_base, '/' );
    $escaped_gallery_base = preg_quote( $gallery_base, '/' );
    foreach ( $rewrite_rules as $rule => $query ) {
        if ( !is_string( $rule ) || !is_string( $query ) ) {
            continue;
        }
        if ( strpos( $query, PGC_SGB_POST_TYPE . '=' ) === false ) {
            continue;
        }
        if ( $gallery_base === '' || strpos( $rule, $gallery_base . '/' ) === 0 || strpos( $rule, $escaped_gallery_base . '/' ) === 0 ) {
            return true;
        }
    }
    return false;
}

function pgc_sgb_plugin_frontend_scripts() {
    global $post, $pgc_sgb_global_lightbox_use;
    if ( is_404() || is_search() ) {
        return;
    }
    if ( $pgc_sgb_global_lightbox_use && is_object( $post ) && ($post->post_type === 'post' || $post->post_type === 'page') ) {
        $lightboxURL = PGC_SGB_URL . 'plugins/pgc_sgb_lightbox.min.js';
        $lightboxStyleURL = PGC_SGB_URL . 'plugins/pgc_sgb_lightbox.min.style.css';
        $lightboxPreset = pgc_sgb_get_preset_by_slug( 'pgc_sgb_lightbox' );
        $field_value = get_post_meta( $post->ID, 'pgc_sgb_lightbox_settings', true );
        if ( isset( $field_value ) && $field_value !== '' ) {
            $field_value = json_decode( $field_value, true );
            if ( isset( $field_value ) ) {
                if ( isset( $field_value['enableLightbox'] ) ) {
                    if ( $field_value['enableLightbox'] === false ) {
                        return;
                    }
                }
            }
        }
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-lightbox-style',
            $lightboxStyleURL,
            array(),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-lightbox-script',
            $lightboxURL,
            false,
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'lightboxPreset'  => $lightboxPreset,
            'postType'        => $post->post_type,
            'lightboxSettigs' => $field_value,
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-lightbox-script', 'PGC_SGB_LIGHTBOX', $globalJS );
    }
}

function pgc_sgb_plugin_enqueue_assets() {
    /** Block Editor - Global Lightbox Panel/Plugin */
    global $post, $pgc_sgb_global_lightbox_use, $pagenow;
    if ( !$pgc_sgb_global_lightbox_use || $pgc_sgb_global_lightbox_use === false ) {
        return;
    }
    if ( is_object( $post ) && ($post->post_type === 'post' || $post->post_type === 'page') ) {
        if ( $pagenow !== 'widgets.php' ) {
            wp_enqueue_script( PGC_SGB_PLUGIN_SLUG . '-script' );
            wp_enqueue_style( PGC_SGB_PLUGIN_SLUG . '-editor' );
        }
    }
}

function pgc_sgb_activation_hook() {
    if ( get_option( 'pgc_sgb_global_lightbox_use', null ) === null ) {
        add_option( 'pgc_sgb_global_lightbox_use', true );
    }
    delete_option( 'pgc_sgb_gallery_rewrite_rules_version' );
}

function pgc_sgb_add_albums_preset_page() {
    function pgc_sgb_plugin_albums_sh_options() {
        global $pgc_sgb_skins_presets;
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-prem-albums-sh-page-settings',
            // Handle.
            PGC_SGB_URL . 'dist/albums.page.build.style.css',
            array('wp-components', 'code-editor'),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-prem-albums-page-sh-settings-script',
            PGC_SGB_URL . 'dist/albums.page.build.js',
            array(
                'wp-api',
                'wp-element',
                'wp-i18n',
                'wp-components',
                'code-editor',
                'csslint'
            ),
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'adminurl'       => get_admin_url(),
            'postType'       => PGC_SGB_POST_TYPE,
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'pgc-sgb-nonce' ),
            'isPremium'      => wp_json_encode( pgc_sgb_fs()->can_use_premium_code() ),
            'isPro'          => wp_json_encode( pgc_sgb_fs()->is_plan_or_trial( 'pro' ) ),
            'skinsSettings'  => $pgc_sgb_skins_presets,
            'albumShcPreset' => get_option( 'pgc_sgb_album_shc_preset' ),
            'version'        => PGC_SGB_VERSION,
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-prem-albums-page-sh-settings-script', 'PGC_SGB_OPTIONS_PAGE', $globalJS );
    }

    function pgc_sgb_plugin_albums_sh_page() {
        echo '<div id="' . esc_html( PGC_SGB_PLUGIN_SLUG ) . '-prem-page"></div>';
    }

    $pr_sub_page_albums_hook_suffix = add_submenu_page(
        'edit.php?post_type=' . PGC_SGB_POST_TYPE,
        'SimpLy Premium',
        esc_html__( 'Albums Preset', 'simply-gallery-block' ),
        'manage_options',
        'pgc-simply-albums-presets',
        'pgc_sgb_plugin_albums_sh_page'
    );
    add_action( "admin_print_scripts-{$pr_sub_page_albums_hook_suffix}", 'pgc_sgb_plugin_albums_sh_options' );
}

add_action( 'admin_menu', 'pgc_sgb_add_albums_preset_page' );
function pgc_sgb_add_blocks_preset_page() {
    function pgc_sgb_plugin_options_assets() {
        global $pgc_sgb_global_lightbox_use, $pgc_sgb_skins_presets, $user_ID;
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-page-settings',
            PGC_SGB_URL . 'dist/page.build.style.css',
            array('wp-components', 'code-editor'),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-page-settings-script',
            PGC_SGB_URL . 'dist/page.build.js',
            array(
                'wp-api',
                'wp-element',
                'wp-i18n',
                'wp-components',
                'code-editor',
                'csslint'
            ),
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'adminurl'       => get_admin_url(),
            'postType'       => PGC_SGB_POST_TYPE,
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'pgc-sgb-nonce' ),
            'globalLightbox' => $pgc_sgb_global_lightbox_use,
            'lightboxPreset' => pgc_sgb_get_preset_by_slug( 'pgc_sgb_lightbox' ),
            'skinsSettings'  => $pgc_sgb_skins_presets,
            'version'        => PGC_SGB_VERSION,
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-page-settings-script', 'PGC_SGB_OPTIONS_PAGE', $globalJS );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( PGC_SGB_PLUGIN_SLUG . '-page-settings-script', 'simply-gallery-block', PGC_SGB_URL . 'languages' );
        }
    }

    function pgc_sgb_print_global_preset() {
        echo '<div id="' . esc_html( PGC_SGB_PLUGIN_SLUG ) . '-settings-page"></div>';
    }

    $pr_sub_page_hook_suffix = add_submenu_page(
        'edit.php?post_type=' . PGC_SGB_POST_TYPE,
        'SimpLy Blocks Presets',
        ( pgc_sgb_fs()->can_use_premium_code() ? esc_html__( 'Core Blocks Presets', 'simply-gallery-block' ) : esc_html__( 'Blocks Presets', 'simply-gallery-block' ) ),
        'manage_options',
        'pgc-simply-presets',
        'pgc_sgb_print_global_preset'
    );
    add_action( "admin_print_scripts-{$pr_sub_page_hook_suffix}", 'pgc_sgb_plugin_options_assets' );
}

add_action( 'admin_menu', 'pgc_sgb_add_blocks_preset_page' );
function pgc_sgb_add_lightbox_admin_page() {
    function pgc_sgb_plugin_lightbox_options_assets() {
        global $pgc_sgb_global_lightbox_use;
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-lightbox-page-settings',
            PGC_SGB_URL . 'dist/lightbox.page.build.style.css',
            array('wp-components'),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-lightbox-page-settings-script',
            PGC_SGB_URL . 'dist/lightbox.page.build.js',
            array(
                'wp-api',
                'wp-element',
                'wp-i18n',
                'wp-components'
            ),
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'adminurl'       => get_admin_url(),
            'postType'       => PGC_SGB_POST_TYPE,
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'pgc-sgb-nonce' ),
            'globalLightbox' => $pgc_sgb_global_lightbox_use,
            'lightboxPreset' => pgc_sgb_get_preset_by_slug( 'pgc_sgb_lightbox' ),
            'version'        => PGC_SGB_VERSION,
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-lightbox-page-settings-script', 'PGC_SGB_OPTIONS_PAGE', $globalJS );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( PGC_SGB_PLUGIN_SLUG . '-lightbox-page-settings-script', 'simply-gallery-block', PGC_SGB_URL . 'languages' );
        }
    }

    function pgc_sgb_plugin_lightbox_admin_page() {
        echo '<div id="' . esc_html( PGC_SGB_PLUGIN_SLUG ) . '-lightbox-page"></div>';
    }

    $pr_sub_page_lightbox_hook_suffix = add_submenu_page(
        'edit.php?post_type=' . PGC_SGB_POST_TYPE,
        'SimpLy Lightbox',
        esc_html__( 'Lightbox for native WordPress Gallery', 'simply-gallery-block' ),
        'manage_options',
        'pgc-simply-lightbox-options',
        'pgc_sgb_plugin_lightbox_admin_page'
    );
    add_action( "admin_print_scripts-{$pr_sub_page_lightbox_hook_suffix}", 'pgc_sgb_plugin_lightbox_options_assets' );
}

add_action( 'admin_menu', 'pgc_sgb_add_lightbox_admin_page' );
function pgc_sgb_add_settings_page() {
    function pgc_sgb_mask_api_key(  $api_key  ) {
        if ( !is_string( $api_key ) ) {
            return '';
        }
        $api_key = trim( $api_key );
        $api_key_length = strlen( $api_key );
        if ( $api_key_length === 0 ) {
            return '';
        }
        if ( $api_key_length <= 10 ) {
            return str_repeat( '.', $api_key_length );
        }
        $visible_start = substr( $api_key, 0, 6 );
        $visible_end = substr( $api_key, -4 );
        $hidden_length = $api_key_length - 10;
        return $visible_start . str_repeat( '.', $hidden_length ) . $visible_end;
    }

    function pgc_sgb_plugin_settings_page_assets() {
        $youtube_api_key = get_option( 'pgc_sgb_ytk' );
        $vimeo_access_token = get_option( 'pgc_sgb_vtk' );
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-main-settings-page',
            PGC_SGB_URL . 'dist/plugin_settings_page.build.style.css',
            array('wp-components'),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-main-settings-page-script',
            PGC_SGB_URL . 'dist/plugin_settings_page.build.js',
            array(
                'wp-api',
                'wp-element',
                'wp-i18n',
                'wp-components'
            ),
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'assets'            => PGC_SGB_URL . 'assets/',
            'adminurl'          => get_admin_url(),
            'ajaxurl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'pgc-sgb-nonce' ),
            'postType'          => PGC_SGB_POST_TYPE,
            'version'           => PGC_SGB_VERSION,
            'hasYtk'            => is_string( $youtube_api_key ) && trim( $youtube_api_key ) !== '',
            'hasVtk'            => is_string( $vimeo_access_token ) && trim( $vimeo_access_token ) !== '',
            'ytkMasked'         => pgc_sgb_mask_api_key( $youtube_api_key ),
            'vtkMasked'         => pgc_sgb_mask_api_key( $vimeo_access_token ),
            'assistantSettings' => ( function_exists( 'pgc_sgb_media_folders_get_assistant_settings' ) ? pgc_sgb_media_folders_get_assistant_settings() : null ),
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-main-settings-page-script', 'PGC_SGB_OPTIONS_PAGE', $globalJS );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( PGC_SGB_PLUGIN_SLUG . '-main-settings-page-script', 'simply-gallery-block', PGC_SGB_URL . 'languages' );
        }
    }

    function pgc_sgb_print_plugin_settings_page() {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'simply-gallery-block' ) );
        }
        echo '<div id="' . esc_html( PGC_SGB_PLUGIN_SLUG ) . '-main-settings-page"></div>';
    }

    if ( current_user_can( 'manage_options' ) ) {
        $pr_sub_main_settings_page_hook_suffix = add_submenu_page(
            'edit.php?post_type=' . PGC_SGB_POST_TYPE,
            'Settings',
            esc_html__( 'Settings', 'simply-gallery-block' ),
            'manage_options',
            'pgc-simply-settings',
            'pgc_sgb_print_plugin_settings_page'
        );
        add_action( "admin_print_scripts-{$pr_sub_main_settings_page_hook_suffix}", 'pgc_sgb_plugin_settings_page_assets' );
    }
}

add_action( 'admin_menu', 'pgc_sgb_add_settings_page' );
function pgc_sgb_add_welcome_page() {
    function pgc_sgb_plugin_welcome_assets() {
        wp_enqueue_style(
            PGC_SGB_PLUGIN_SLUG . '-page-welcome',
            PGC_SGB_URL . 'dist/welcome.build.style.css',
            array('wp-components'),
            PGC_SGB_VERSION
        );
        wp_enqueue_script(
            PGC_SGB_PLUGIN_SLUG . '-page-welcome-script',
            PGC_SGB_URL . 'dist/welcome.build.js',
            array(
                'wp-api',
                'wp-element',
                'wp-i18n',
                'wp-components'
            ),
            PGC_SGB_VERSION,
            true
        );
        $globalJS = array(
            'assets'   => PGC_SGB_URL . 'assets/',
            'adminurl' => get_admin_url(),
            'postType' => PGC_SGB_POST_TYPE,
            'version'  => PGC_SGB_VERSION,
        );
        wp_localize_script( PGC_SGB_PLUGIN_SLUG . '-page-welcome-script', 'PGC_SGB_WELCOME_PAGE', $globalJS );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( PGC_SGB_PLUGIN_SLUG . '-page-welcome-script', 'simply-gallery-block', PGC_SGB_URL . 'languages' );
        }
    }

    function pgc_sgb_print_welcome_page() {
        echo '<div id="' . esc_html( PGC_SGB_PLUGIN_SLUG ) . '-welcome-page"></div>';
    }

    if ( current_user_can( 'upload_files' ) ) {
        $pr_sub_page_hook_suffix = add_submenu_page(
            'edit.php?post_type=' . PGC_SGB_POST_TYPE,
            'Welcome',
            esc_html__( 'FEATURES & FAQ', 'simply-gallery-block' ),
            'read',
            'pgc-simply-welcome',
            'pgc_sgb_print_welcome_page'
        );
        add_action( "admin_print_scripts-{$pr_sub_page_hook_suffix}", 'pgc_sgb_plugin_welcome_assets' );
    }
}

add_action( 'admin_menu', 'pgc_sgb_add_welcome_page' );
add_action( 'init', 'pgc_sgb_plugin_init', 12 );
add_action( 'enqueue_block_editor_assets', 'pgc_sgb_plugin_enqueue_assets' );
add_action( 'wp_enqueue_scripts', 'pgc_sgb_plugin_frontend_scripts' );
register_activation_hook( PGC_SGB_FILE, 'pgc_sgb_activation_hook' );