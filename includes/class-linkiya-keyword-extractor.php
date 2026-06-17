<?php
/**
 * Linkiya Keyword Extractor — builds the keyword-to-post map.
 *
 * @package Linkiya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_Keyword_Extractor
 *
 * Fetches all published posts (excluding the current one) and extracts
 * meaningful keywords from their titles. For each post we keep up to 3
 * bigrams (two-word phrases) and up to 2 single words, giving a balanced
 * set that works for both short and long post bodies.
 *
 * Trigrams and 4-grams are intentionally omitted: they almost never appear
 * verbatim in other posts' body text and only waste keyword slots.
 */
class Linkiya_Keyword_Extractor {

	const CACHE_KEY    = 'linkiya_keyword_map_v5';
	const CACHE_EXPIRY = HOUR_IN_SECONDS;

	/**
	 * Runtime-cached stop word map.
	 *
	 * @var array<string,int>|null
	 */
	private static $stop_words_cache = null;

	/**
	 * Return the stop word map (O(1) lookup) built from the user's settings.
	 *
	 * @return array<string, int>
	 */
	private static function get_stop_words(): array {
		if ( null !== self::$stop_words_cache ) {
			return self::$stop_words_cache;
		}

		$settings = Linkiya_Settings::get();
		$raw      = $settings['stop_words'] ?? '';

		$words = array_filter(
			array_map( 'trim', explode( "\n", strtolower( $raw ) ) )
		);

		self::$stop_words_cache = array_fill_keys( array_values( $words ), 1 );
		return self::$stop_words_cache;
	}

	/**
	 * Clear the runtime stop-word cache when settings are saved.
	 */
	public static function flush_stop_words_cache(): void {
		self::$stop_words_cache = null;
	}

	/**
	 * Register cache-invalidation hooks. Called once from linkiya.php.
	 */
	public static function init(): void {
		add_action( 'save_post', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'delete_post', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'save_post', array( __CLASS__, 'clear_applied_ids_meta' ) );
	}

	/**
	 * Clear applied-link post IDs meta after a post is saved.
	 *
	 * @param int $post_id Saved post ID.
	 * @return void
	 */
	public static function clear_applied_ids_meta( int $post_id ): void {
		delete_post_meta( $post_id, '_linkiya_applied_ids' );
	}

	/**
	 * Delete the cached keyword map so the next scan rebuilds it.
	 */
	public static function invalidate_cache(): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required to delete all hash-suffixed transient variants; no WordPress API supports wildcard transient deletion. Cache is being invalidated, not read.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                    OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE_KEY ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE_KEY ) . '%'
			)
		);
	}

	/**
	 * Get minimum word length from settings.
	 */
	private static function get_min_word_len(): int {
		$settings = Linkiya_Settings::get();
		return max( 2, (int) ( $settings['min_word_length'] ?? 4 ) );
	}

	/**
	 * Returns all public post types registered on the site.
	 *
	 * @return string[]
	 */
	public static function get_all_public_post_types(): array {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);

		$exclude = array( 'attachment' );
		foreach ( $exclude as $slug ) {
			unset( $types[ $slug ] );
		}

		return array_values( $types );
	}

	/**
	 * Get all published posts and return a keyword map.
	 * Results are cached in a transient for CACHE_EXPIRY seconds.
	 *
	 * @param int   $exclude_id  Post ID to exclude (the one being edited).
	 * @param array $post_types  Explicit list, or empty to auto-detect.
	 * @return array
	 */
	public static function get_keyword_map( int $exclude_id, array $post_types = array() ): array {
		if ( empty( $post_types ) ) {
			$post_types = self::get_all_public_post_types();
		}

		$cache_key = self::CACHE_KEY . '_' . md5( implode( ',', $post_types ) );
		$map       = get_transient( $cache_key );

		if ( false === $map ) {
			$map = self::build_keyword_map( $post_types );
			set_transient( $cache_key, $map, self::CACHE_EXPIRY );
		}

		return array_values( array_filter( $map, fn( $e ) => (int) $e['post_id'] !== $exclude_id ) );
	}

	/**
	 * Build the full keyword map from the database (uncached).
	 *
	 * @param array $post_types Post type slugs to include.
	 * @return array
	 */
	private static function build_keyword_map( array $post_types ): array {
		$posts = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		update_post_caches( $posts, 'posts', false, false );

		$total_posts = count( $posts );

		// Pass 1 — extract candidates and build document-frequency index.
		$raw      = array();
		$df_index = array();

		foreach ( $posts as $post ) {
			$candidates = self::extract_keywords( $post->post_title );
			$candidates = apply_filters( 'linkiya_post_keywords', $candidates, $post->ID );

			$raw[ $post->ID ] = $candidates;

			foreach ( $candidates as $kw ) {
				$df_index[ $kw ] = ( $df_index[ $kw ] ?? 0 ) + 1;
			}
		}

		// Pass 2 — select final keywords for each post.
		// Strategy: up to 3 bigrams + up to 2 single words, always guaranteeing
		// at least one single word so short post bodies can still get a match.
		$df_limit_single = max( 3, (int) round( $total_posts * 0.2 ) ); // 20% for singles.
		$map             = array();

		foreach ( $posts as $post ) {
			$candidates = $raw[ $post->ID ] ?? array();
			if ( empty( $candidates ) ) {
				continue;
			}

			$bigrams = array();
			$singles = array();

			foreach ( $candidates as $kw ) {
				$is_bigram = strpos( $kw, ' ' ) !== false;
				$df        = $df_index[ $kw ] ?? 1;

				if ( $is_bigram ) {
					// Prefer bigrams without digits — sort them after collection.
					$bigrams[] = $kw;
				} else {
					// Single words: DF must be within limit.
					if ( $df <= $df_limit_single ) {
						$singles[] = $kw;
					}
				}
			}

			// Sort bigrams: no-digit first, then longer first.
			usort(
				$bigrams,
				static function ( $a, $b ) {
					$a_digit = (int) preg_match( '/\d/', $a );
					$b_digit = (int) preg_match( '/\d/', $b );
					if ( $a_digit !== $b_digit ) {
						return $a_digit - $b_digit; // no-digit first.
					}
					return strlen( $b ) - strlen( $a ); // longer first.
				}
			);

			// Sort singles: longer first (longer = more specific / rarer).
			usort( $singles, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

			// Take top 3 bigrams and top 2 singles.
			// Always include at least 1 single so a post body with just one keyword word can match.
			$keywords = array_merge(
				array_slice( $bigrams, 0, 3 ),
				array_slice( $singles, 0, 2 )
			);

			if ( empty( $keywords ) ) {
				continue;
			}

			$map[] = array(
				'post_id'   => $post->ID,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post ),
				'post_type' => $post->post_type,
				'keywords'  => $keywords,
			);
		}

		return $map;
	}

	/**
	 * Extract single words and bigrams from a post title.
	 *
	 * Trigrams and 4-grams are excluded by design: they are too long to appear
	 * verbatim in most post bodies and only create noise in the keyword map.
	 *
	 * @param  string $title Post title to extract keywords from.
	 * @return string[]
	 */
	public static function extract_keywords( string $title ): array {
		$min_len = self::get_min_word_len();

		// Strip hyphens so "Well-Being" → "wellbeing", then lowercase and remove punctuation.
		$clean  = strtolower( preg_replace( '/[^\w\s]/u', ' ', str_replace( '-', '', $title ) ) );
		$tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );

		$stop_words  = self::get_stop_words();
		$keywords    = array();
		$token_count = count( $tokens );

		// Single words: must meet min length and not be a stop word.
		foreach ( $tokens as $t ) {
			if ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) ) {
				$keywords[] = $t;
			}
		}

		// Bigrams: both tokens must be non-stop-words of at least 3 chars, OR digits.
		// This allows connective stop words (in, of, the) to sit BETWEEN two valid words
		// only at the bigram level — e.g. "control anger" not "anger in".
		$is_content_token = static function ( string $t ) use ( $stop_words ): bool {
			return ctype_digit( $t ) || ( strlen( $t ) >= 3 && ! isset( $stop_words[ $t ] ) );
		};

		for ( $i = 0; $i < $token_count - 1; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			if ( $is_content_token( $a ) && $is_content_token( $b ) ) {
				$keywords[] = $a . ' ' . $b;
			}
		}

		return array_values( array_unique( $keywords ) );
	}
}
