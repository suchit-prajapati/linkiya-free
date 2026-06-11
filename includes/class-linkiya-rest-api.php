<?php
defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_REST_API — Free version
 *
 * POST /wp-json/linkiya/v1/suggest  — scans content, returns link suggestions
 * POST /wp-json/linkiya/v1/apply    — applies accepted links to content
 * GET  /wp-json/linkiya/v1/status   — returns plugin status for sidebar
 */
class Linkiya_REST_API {

    const NAMESPACE = 'linkiya/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/suggest', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_suggest' ],
            'permission_callback' => [ __CLASS__, 'check_suggest_permission' ],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/apply', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_apply' ],
            'permission_callback' => [ __CLASS__, 'check_apply_permission' ],
            'args'                => [
                'post_id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_status' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }

    /**
     * Permission: user can edit posts AND can edit this specific post.
     */
    public static function check_suggest_permission( WP_REST_Request $request ): bool {
        if ( ! current_user_can( 'edit_posts' ) ) return false;
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) return false;
        return true;
    }

    public static function check_apply_permission( WP_REST_Request $request ): bool {
        if ( ! current_user_can( 'edit_posts' ) ) return false;
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) return false;
        return true;
    }

    /* ── GET /linkiya/v1/status ──────────────────────────────────── */

    public static function handle_status( WP_REST_Request $request ): WP_REST_Response {
        // Allow Pro plugin to filter status
        $status = apply_filters( 'linkiya_rest_status', [
            'is_pro'    => false,
            'version'   => LINKIYA_VERSION,
            'features'  => [
                'cpt_support'     => false,
                'category_filter' => false,
                'exclusions'      => false,
                'bulk_mode'       => false,
                'link_report'     => false,
            ],
        ] );

        return new WP_REST_Response( $status, 200 );
    }

    /* ── POST /linkiya/v1/suggest ────────────────────────────────── */

    public static function handle_suggest( WP_REST_Request $request ): WP_REST_Response {
        $body    = $request->get_json_params();
        $post_id = absint( $body['post_id'] ?? 0 );
        $content = wp_kses_post( $body['content'] ?? '' );

        if ( ! $post_id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid post_id.' ], 400 );
        }

        // Free: posts and pages only
        $post_types = apply_filters( 'linkiya_suggest_post_types', [ 'post', 'page' ], $post_id );

        $keyword_map = Linkiya_Keyword_Extractor::get_keyword_map( $post_id, $post_types );

        // Allow Pro plugin to extend keyword map
        $keyword_map = apply_filters( 'linkiya_keyword_map', $keyword_map, $post_id );

        $suggestions = Linkiya_Matcher::find_suggestions( $content, $keyword_map );

        // Max links — free setting
        $settings = Linkiya_Settings::get();
        $max      = (int) ( $settings['max_links_per_post'] ?? 5 );
        if ( $max > 0 ) {
            $suggestions = array_slice( $suggestions, 0, $max );
        }

        // Excluded post IDs — free setting (F6)
        $excluded_ids = array_filter( array_map( 'absint', explode( "\n", $settings['excluded_post_ids'] ?? '' ) ) );
        if ( ! empty( $excluded_ids ) ) {
            $suggestions = array_values( array_filter( $suggestions, function ( $s ) use ( $excluded_ids ) {
                return ! in_array( (int) ( $s['post_id'] ?? 0 ), $excluded_ids, true );
            } ) );
        }

        // Allow Pro plugin to further filter suggestions
        $suggestions = apply_filters( 'linkiya_suggestions', $suggestions, $post_id );

        return new WP_REST_Response( [
            'success'     => true,
            'post_id'     => $post_id,
            'suggestions' => $suggestions,
            'total'       => count( $suggestions ),
        ], 200 );
    }

    /* ── POST /linkiya/v1/apply ──────────────────────────────────── */

    public static function handle_apply( WP_REST_Request $request ): WP_REST_Response {
        $body     = $request->get_json_params();
        $post_id  = absint( $body['post_id'] ?? 0 );
        $content  = wp_kses_post( $body['content'] ?? '' );
        $accepted = $body['accepted'] ?? [];

        if ( ! $post_id ) {
            return new WP_REST_Response( [ 'error' => 'Invalid post_id.' ], 400 );
        }
        if ( empty( $content ) ) {
            return new WP_REST_Response( [ 'error' => 'No content provided.' ], 400 );
        }
        if ( empty( $accepted ) ) {
            return new WP_REST_Response( [ 'error' => 'No accepted suggestions.' ], 400 );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_REST_Response( [ 'error' => 'Permission denied.' ], 403 );
        }

        // Sanitize accepted suggestions
        $sanitized = [];
        foreach ( $accepted as $item ) {
            if ( empty( $item['keyword'] ) || empty( $item['url'] ) ) continue;
            $sanitized[] = [
                'keyword'    => sanitize_text_field( $item['keyword'] ),
                'anchor'     => sanitize_text_field( $item['anchor'] ?? $item['keyword'] ),
                'post_id'    => absint( $item['post_id'] ?? 0 ),
                'post_title' => sanitize_text_field( $item['post_title'] ?? '' ),
                'url'        => esc_url_raw( $item['url'] ),
                'nofollow'   => ! empty( $item['nofollow'] ),
                'new_tab'    => ! empty( $item['new_tab'] ),
            ];
        }

        $settings    = Linkiya_Settings::get();
        $link_target = $settings['link_target'] ?: '_self';
        $link_rel    = $settings['link_rel'] ?: '';

        $new_content = Linkiya_Matcher::apply_links( $content, $sanitized, $link_target, $link_rel );

        // Allow Pro plugin to log applied links
        do_action( 'linkiya_links_applied', $post_id, $sanitized );

        return new WP_REST_Response( [
            'success'     => true,
            'post_id'     => $post_id,
            'new_content' => $new_content,
            'applied'     => count( $sanitized ),
        ], 200 );
    }
}
