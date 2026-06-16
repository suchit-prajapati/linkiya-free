<?php
/**
 * Linkiya REST API — Free version endpoints.
 *
 * @package Linkiya
 */

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

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_suggest' ),
				'permission_callback' => array( __CLASS__, 'check_suggest_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_apply' ),
				'permission_callback' => array( __CLASS__, 'check_apply_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			)
		);
	}

	/**
	 * Permission: user can edit posts AND can edit this specific post.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool
	 */
	public static function check_suggest_permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Permission: user can edit posts AND can edit this specific post.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool
	 */
	public static function check_apply_permission( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return true;
	}

	/* ── GET /linkiya/v1/status ──────────────────────────────────── */

	/**
	 * Handle GET /linkiya/v1/status.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_status( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP REST API callback signature; the parameter must be declared even if unused.
		// Allow Pro plugin to filter status.
		$status = apply_filters(
			'linkiya_rest_status',
			array(
				'is_pro'   => false,
				'version'  => LINKIYA_VERSION,
				'features' => array(
					'cpt_support'     => false,
					'category_filter' => false,
					'exclusions'      => false,
					'bulk_mode'       => false,
					'link_report'     => false,
				),
			)
		);

		return new WP_REST_Response( $status, 200 );
	}

	/* ── POST /linkiya/v1/suggest ────────────────────────────────── */

	/**
	 * Handle POST /linkiya/v1/suggest.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_suggest( WP_REST_Request $request ): WP_REST_Response {
		// Rate limit: max 20 scans per user per minute.
		// Atomic increment via direct DB query prevents TOCTOU race conditions.
		global $wpdb;
		$rate_key    = '_transient_linkiya_rl_' . get_current_user_id();
		$timeout_key = '_transient_timeout_linkiya_rl_' . get_current_user_id();
		$expiry      = time() + MINUTE_IN_SECONDS;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic rate limiting via direct DB; no WordPress API supports atomic transient increments or expiry-based resets.
		// If the window has expired, delete both rows so the next INSERT IGNORE starts fresh.
		$stored_expiry = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$timeout_key
			)
		);
		if ( $stored_expiry > 0 && time() > $stored_expiry ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s",
					$rate_key,
					$timeout_key
				)
			);
		}

		// Insert counter row if not present (atomic — ignores duplicate key).
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
                 VALUES (%s, %d, 'no'), (%s, %d, 'no')",
				$rate_key,
				1,
				$timeout_key,
				$expiry
			)
		);

		if ( 0 === $wpdb->rows_affected ) {
			// Row already existed — atomically increment.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
					$rate_key
				)
			);
		}

		$attempts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$rate_key
			)
		);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $attempts > 20 ) {
			return new WP_REST_Response( array( 'error' => __( 'Too many requests. Please wait a moment.', 'linkiya-free' ) ), 429 );
		}

		$body    = $request->get_json_params();
		$post_id = absint( $body['post_id'] ?? 0 );
		$content = wp_kses_post( $body['content'] ?? '' );

		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'error' => 'Invalid post_id.' ), 400 );
		}

		// If no content passed from JS, read directly from the database.
		if ( '' === $content ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$content = $post->post_content;
			}
		}

		// Free: posts and pages only.
		$post_types = apply_filters( 'linkiya_suggest_post_types', array( 'post', 'page' ), $post_id );

		$keyword_map = Linkiya_Keyword_Extractor::get_keyword_map( $post_id, $post_types );

		// Allow Pro plugin to extend keyword map.
		$keyword_map = apply_filters( 'linkiya_keyword_map', $keyword_map, $post_id );

		$suggestions = Linkiya_Matcher::find_suggestions( $content, $keyword_map );

		// Max links — free setting.
		$settings = Linkiya_Settings::get();
		$max      = (int) ( $settings['max_links_per_post'] ?? 5 );
		if ( $max > 0 ) {
			$suggestions = array_slice( $suggestions, 0, $max );
		}

		// Excluded post IDs — free setting.
		$excluded_ids = array_filter( array_map( 'absint', explode( "\n", $settings['excluded_post_ids'] ?? '' ) ) );
		if ( ! empty( $excluded_ids ) ) {
			$suggestions = array_values(
				array_filter(
					$suggestions,
					function ( $s ) use ( $excluded_ids ) {
						return ! in_array( (int) ( $s['post_id'] ?? 0 ), $excluded_ids, true );
					}
				)
			);
		}

		// Allow Pro plugin to further filter suggestions.
		$suggestions = apply_filters( 'linkiya_suggestions', $suggestions, $post_id );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'post_id'     => $post_id,
				'suggestions' => $suggestions,
				'total'       => count( $suggestions ),
			),
			200
		);
	}

	/* ── POST /linkiya/v1/apply ──────────────────────────────────── */

	/**
	 * Handle POST /linkiya/v1/apply.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_apply( WP_REST_Request $request ): WP_REST_Response {
		$body     = $request->get_json_params();
		$post_id  = absint( $body['post_id'] ?? 0 );
		$content  = wp_kses_post( $body['content'] ?? '' );
		$accepted = is_array( $body['accepted'] ?? null ) ? $body['accepted'] : array();

		if ( ! $post_id ) {
			return new WP_REST_Response( array( 'error' => 'Invalid post_id.' ), 400 );
		}

		// If no content passed from JS, read directly from the database.
		if ( '' === $content ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$content = $post->post_content;
			}
		}

		if ( empty( $content ) ) {
			return new WP_REST_Response( array( 'error' => 'No content provided.' ), 400 );
		}
		if ( empty( $accepted ) ) {
			return new WP_REST_Response( array( 'error' => 'No accepted suggestions.' ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'Permission denied.' ), 403 );
		}

		// Sanitize accepted suggestions.
		$sanitized = array();
		foreach ( $accepted as $item ) {
			if ( empty( $item['keyword'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$sanitized[] = array(
				'keyword'    => sanitize_text_field( $item['keyword'] ),
				'anchor'     => sanitize_text_field( $item['anchor'] ?? $item['keyword'] ?? '' ),
				'post_id'    => absint( $item['post_id'] ?? 0 ),
				'post_title' => sanitize_text_field( $item['post_title'] ?? '' ),
				'url'        => esc_url_raw( $item['url'] ),
				'nofollow'   => ! empty( $item['nofollow'] ),
				'new_tab'    => ! empty( $item['new_tab'] ),
			);
		}

		$settings    = Linkiya_Settings::get();
		$link_target = ! empty( $settings['link_target'] ) ? $settings['link_target'] : '_self';
		$link_rel    = ! empty( $settings['link_rel'] ) ? $settings['link_rel'] : '';

		$new_content = Linkiya_Matcher::apply_links( $content, $sanitized, $link_target, $link_rel );

		// Allow Pro plugin to log applied links.
		do_action( 'linkiya_links_applied', $post_id, $sanitized );

		return new WP_REST_Response(
			array(
				'success'     => true,
				'post_id'     => $post_id,
				'new_content' => $new_content,
				'applied'     => count( $sanitized ),
			),
			200
		);
	}
}
