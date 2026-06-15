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
            __( 'Linkiya', 'linkiya-free' ),
            __( 'Linkiya', 'linkiya-free' ),
            'manage_options',
            'linkiya',
            [ __CLASS__, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' ),
            80
        );

        add_submenu_page(
            'linkiya',
            __( 'Settings', 'linkiya-free' ),
            __( 'Settings', 'linkiya-free' ),
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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya-free' ) );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is individually sanitized below via absint(), sanitize_text_field(), etc.
        $raw   = isset( $_POST['linkiya_settings'] ) ? wp_unslash( $_POST['linkiya_settings'] ) : [];

        $clean = [
            'min_word_length'    => absint( $raw['min_word_length'] ?? 4 ),
            'max_links_per_post' => absint( $raw['max_links_per_post'] ?? 5 ),
            'link_target'        => in_array( $raw['link_target'] ?? '', [ '_self', '_blank' ], true )
                                        ? sanitize_text_field( $raw['link_target'] )
                                        : '_self',
            'link_rel'           => sanitize_text_field( $raw['link_rel'] ?? '' ),
            'excluded_post_ids'  => sanitize_textarea_field( $raw['excluded_post_ids'] ?? '' ),
        ];

        // Allow Pro plugin to save its own settings fields — pass sanitized $clean, not raw input.
        $clean = apply_filters( 'linkiya_save_settings', $clean, $clean );

        update_option( self::OPTION_KEY, $clean );

        wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Export ──────────────────────────────────────────────────── */

    public static function handle_export(): void {
        check_admin_referer( 'linkiya_export_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya-free' ) );

        $data = self::get();
        $data = apply_filters( 'linkiya_export_settings', $data );

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="linkiya-settings-' . wp_date( 'Y-m-d' ) . '.json"' );
        $encoded = wp_json_encode( $data, JSON_PRETTY_PRINT );
        if ( false !== $encoded ) {
            // Output is application/json — not HTML — so standard HTML escaping does not apply.
            // wp_json_encode() produces safe JSON; the Content-Type header prevents browser HTML parsing.
            echo $encoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON output served as application/json, not HTML.
        }
        exit;
    }

    /* ── Import ──────────────────────────────────────────────────── */

    public static function handle_import(): void {
        check_admin_referer( 'linkiya_import_settings' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized', 'linkiya-free' ) );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path validated via is_uploaded_file(); other fields validated below.
        $file = isset( $_FILES['linkiya_import_file'] ) ? wp_unslash( $_FILES['linkiya_import_file'] ) : null;
        if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Reject files larger than 1 MB to prevent memory exhaustion.
        if ( $file['size'] > 1024 * 1024 ) {
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
        // tmp_name is a server-generated path — validate it is within the system temp dir, do not sanitize.
        $tmp_name = $file['tmp_name'];
        if ( ! is_uploaded_file( $tmp_name ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        $json = $wp_filesystem->get_contents( $tmp_name );
        if ( false === $json ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'import_error' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Re-use the same validation logic as handle_save() so types are enforced.
        $clean = [
            'min_word_length'    => absint( $decoded['min_word_length'] ?? 4 ),
            'max_links_per_post' => absint( $decoded['max_links_per_post'] ?? 5 ),
            'link_target'        => in_array( $decoded['link_target'] ?? '', [ '_self', '_blank' ], true )
                                        ? $decoded['link_target']
                                        : '_self',
            'link_rel'           => sanitize_text_field( $decoded['link_rel'] ?? '' ),
            'excluded_post_ids'  => sanitize_textarea_field( $decoded['excluded_post_ids'] ?? '' ),
        ];
        $clean = apply_filters( 'linkiya_import_settings', $clean, $decoded );

        update_option( self::OPTION_KEY, $clean );

        wp_safe_redirect( add_query_arg( [ 'page' => 'linkiya', 'imported' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
