<?php
defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_Settings — Free settings page (F1–F10)
 * Handles: min word length, max links, link target, link rel,
 *          post exclusion, import/export settings.
 * Pro plugin adds its own tabs via the linkiya_settings_tabs filter.
 */
class Linkiya_Settings {

    const OPTION_KEY = 'linkiya_settings';

    public static function init(): void {
        add_action( 'admin_menu',                           [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_linkiya_save_settings',     [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_linkiya_export_settings',   [ __CLASS__, 'handle_export' ] );
        add_action( 'admin_post_linkiya_import_settings',   [ __CLASS__, 'handle_import' ] );
    }

    /* ── Defaults ────────────────────────────────────────────────── */

    public static function get_defaults(): array {
        return [
            'min_word_length'    => 4,
            'max_links_per_post' => 5,
            'link_target'        => '_self',
            'link_rel'           => '',
            'excluded_post_ids'  => '',
        ];
    }

    public static function get(): array {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), self::get_defaults() );
    }

    /* ── Menu ────────────────────────────────────────────────────── */

    public static function register_menu(): void {
        add_menu_page(
            __( 'Linkiya', 'linkiya' ),
            __( 'Linkiya', 'linkiya' ),
            'manage_options',
            'linkiya',
            [ __CLASS__, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' ),
            80
        );

        add_submenu_page(
            'linkiya',
            __( 'Settings', 'linkiya' ),
            __( 'Settings', 'linkiya' ),
            'manage_options',
            'linkiya',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ── Render ──────────────────────────────────────────────────── */

    public static function render_page(): void {
        $settings = self::get();
        include LINKIYA_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /* ── Save ────────────────────────────────────────────────────── */

    public static function handle_save(): void {
        check_admin_referer( 'linkiya_settings_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya' ) );

        $input = isset( $_POST['linkiya_settings'] ) ? wp_unslash( $_POST['linkiya_settings'] ) : [];

        $clean = [
            'min_word_length'    => absint( $input['min_word_length'] ?? 4 ),
            'max_links_per_post' => absint( $input['max_links_per_post'] ?? 5 ),
            'link_target'        => in_array( $input['link_target'] ?? '', [ '_self', '_blank' ], true )
                                        ? $input['link_target']
                                        : '_self',
            'link_rel'           => sanitize_text_field( $input['link_rel'] ?? '' ),
            'excluded_post_ids'  => sanitize_textarea_field( $input['excluded_post_ids'] ?? '' ),
        ];

        // Allow Pro plugin to save its own settings fields
        $clean = apply_filters( 'linkiya_save_settings', $clean, $input );

        update_option( self::OPTION_KEY, $clean );

        wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Export ──────────────────────────────────────────────────── */

    public static function handle_export(): void {
        check_admin_referer( 'linkiya_export_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya' ) );

        $data = self::get();
        $data = apply_filters( 'linkiya_export_settings', $data );

        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="linkiya-settings-' . wp_date( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT );
        exit;
    }

    /* ── Import ──────────────────────────────────────────────────── */

    public static function handle_import(): void {
        check_admin_referer( 'linkiya_import_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya' ) );

        $file = isset( $_FILES['linkiya_import_file'] ) ? wp_unslash( $_FILES['linkiya_import_file'] ) : null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $creds = request_filesystem_credentials( '', '', false, false, null );
        if ( ! WP_Filesystem( $creds ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        global $wp_filesystem;
        $json = $wp_filesystem->get_contents( $file['tmp_name'] );
        if ( false === $json ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $defaults = self::get_defaults();
        $clean    = [];
        foreach ( $defaults as $key => $default ) {
            $clean[ $key ] = $decoded[ $key ] ?? $default;
        }
        $clean = apply_filters( 'linkiya_import_settings', $clean, $decoded );

        update_option( self::OPTION_KEY, $clean );

        wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'imported' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
