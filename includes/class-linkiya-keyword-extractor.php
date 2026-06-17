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
 * meaningful single-word and two-word (bigram) keywords from their titles.
 * Results are cached via transients and invalidated on post save/delete.
 */
class Linkiya_Keyword_Extractor {

	const CACHE_KEY    = 'linkiya_keyword_map';
	const CACHE_EXPIRY = HOUR_IN_SECONDS;

	/** @var array<string,int>|null Runtime-cached stop word map. */
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
		// After save the DB content is authoritative — clear the unsaved-state meta.
		add_action( 'save_post', array( __CLASS__, 'clear_applied_ids_meta' ) );
	}

	/**
	 * Clear applied-link post IDs meta after a post is saved.
	 * After save, the DB content is the source of truth for already-linked detection.
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
		// Delete all variants of the keyword map transient regardless of post-type hash suffix.
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
	 * The cache is invalidated whenever any post is saved or deleted.
	 *
	 * @param int   $exclude_id  Post ID to exclude (the one being edited).
	 * @param array $post_types  Explicit list, or empty to auto-detect.
	 * @return array
	 */
	public static function get_keyword_map( int $exclude_id, array $post_types = array() ): array {
		if ( empty( $post_types ) ) {
			$post_types = self::get_all_public_post_types();
		}

		// Build a cache key that is stable per post-type combination.
		$cache_key = self::CACHE_KEY . '_' . md5( implode( ',', $post_types ) );

		$map = get_transient( $cache_key );

		if ( false === $map ) {
			$map = self::build_keyword_map( $post_types );
			set_transient( $cache_key, $map, self::CACHE_EXPIRY );
		}

		// Exclude the currently edited post from the cached map.
		return array_values( array_filter( $map, fn( $e ) => (int) $e['post_id'] !== $exclude_id ) );
	}

	/**
	 * Build the full keyword map from the database (uncached).
	 * Only called on cache miss.
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

		// Pass 1 — extract raw candidates for every post and build a global
		// document-frequency (DF) index: keyword => number of posts it appears in.
		$raw       = array(); // post_id => candidate keywords array.
		$df_index  = array(); // keyword => count of posts.

		foreach ( $posts as $post ) {
			$candidates = self::extract_keywords( $post->post_title );

			// Allow Pro plugin to add custom keywords.
			$candidates = apply_filters( 'linkiya_post_keywords', $candidates, $post->ID );

			$raw[ $post->ID ] = $candidates;

			foreach ( $candidates as $kw ) {
				$df_index[ $kw ] = ( $df_index[ $kw ] ?? 0 ) + 1;
			}
		}

		// Pass 2 — for each post, keep only keywords that are unique (DF = 1)
		// or rare (DF <= 2 for bigrams only), enforce single-word min length of 7,
		// then pick the top 3 most specific (longest first).
		$map = array();

		foreach ( $posts as $post ) {
			$candidates = $raw[ $post->ID ] ?? array();
			if ( empty( $candidates ) ) {
				continue;
			}

			$filtered = array();
			foreach ( $candidates as $kw ) {
				$df       = $df_index[ $kw ] ?? 1;
				$is_multi = strpos( $kw, ' ' ) !== false;

				// Bigrams/trigrams: allow DF <= 2 (slightly relaxed — multi-word phrases are inherently specific).
				// Single words: must be unique (DF = 1) AND at least 7 characters.
				if ( $is_multi ) {
					if ( $df <= 2 ) {
						$filtered[] = $kw;
					}
				} else {
					// Single words: must be unique (DF=1) and at least 8 chars to avoid generic words.
					if ( $df === 1 && strlen( $kw ) >= 8 ) {
						$filtered[] = $kw;
					}
				}
			}

			if ( empty( $filtered ) ) {
				continue;
			}

			// Sort: longest first (bigrams naturally bubble up), then cap at 3.
			usort( $filtered, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			$filtered = array_slice( $filtered, 0, 3 );

			$map[] = array(
				'post_id'   => $post->ID,
				'title'     => $post->post_title,
				'url'       => get_permalink( $post ),
				'post_type' => $post->post_type,
				'keywords'  => $filtered,
			);
		}

		return $map;
	}

	/**
	 * Extract keywords (singles + bigrams) from a post title.
	 * Returned sorted longest-first so bigrams are tried before singles.
	 *
	 * @param  string $title Post title to extract keywords from.
	 * @return string[]
	 */
	public static function extract_keywords( string $title ): array {
		$min_len = self::get_min_word_len();

		// Replace hyphens with nothing so "Well-Being" → "wellbeing" (one token, not two).
		$clean  = strtolower( preg_replace( '/[^\w\s]/u', ' ', str_replace( '-', '', $title ) ) );
		$tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );

		$keywords    = array();
		$token_count = count( $tokens );
		$stop_words  = self::get_stop_words();

		// Single words — include all non-stop-word tokens (DF + length filter happens in build_keyword_map).
		foreach ( $tokens as $t ) {
			if ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) ) {
				$keywords[] = $t;
			}
		}

		// A token is valid for multi-word phrases if it's a number (any length)
		// OR a non-stop word of at least 3 chars.
		$is_phrase_token = static function ( string $t ) use ( $stop_words ): bool {
			return ctype_digit( $t ) || ( strlen( $t ) >= 3 && ! isset( $stop_words[ $t ] ) );
		};

		// Bigrams.
		for ( $i = 0; $i < $token_count - 1; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			if ( $is_phrase_token( $a ) && $is_phrase_token( $b ) ) {
				$keywords[] = $a . ' ' . $b;
			}
		}

		// Trigrams.
		for ( $i = 0; $i < $token_count - 2; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			$c = $tokens[ $i + 2 ];
			if ( $is_phrase_token( $a ) && $is_phrase_token( $b ) && $is_phrase_token( $c ) ) {
				$keywords[] = $a . ' ' . $b . ' ' . $c;
			}
		}

		// 4-grams — for number-heavy titles like "5 5 5 rule" (4 tokens all valid).
		for ( $i = 0; $i < $token_count - 3; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			$c = $tokens[ $i + 2 ];
			$d = $tokens[ $i + 3 ];
			if ( $is_phrase_token( $a ) && $is_phrase_token( $b ) && $is_phrase_token( $c ) && $is_phrase_token( $d ) ) {
				$keywords[] = $a . ' ' . $b . ' ' . $c . ' ' . $d;
			}
		}

		$keywords = array_unique( $keywords );
		// Sort longest-first so trigrams > bigrams > singles in priority.
		usort( $keywords, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		return array_values( $keywords );
	}
}
