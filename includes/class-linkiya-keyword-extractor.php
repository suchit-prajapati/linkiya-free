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
 * Indexes keywords from each post's title AND body text, then scores them
 * with IDF weights so the matcher can use proper TF-IDF ranking.
 *
 * N-gram strategy:
 *   - Title  → singles + bigrams + trigrams + quadgrams
 *   - Body   → bigrams + trigrams + quadgrams (singles too noisy without IDF filter)
 *   - Slug   → single words only (lightweight extra signal)
 *
 * Slot budget per post (sorted rarest-first within each tier):
 *   2 quadgrams + 3 trigrams + 3 bigrams + 2 singles
 *
 * DF thresholds scale with n-gram length:
 *   singles 20% | bigrams 35% | trigrams 50% | quadgrams 65%
 */
class Linkiya_Keyword_Extractor {

	const CACHE_KEY    = 'linkiya_keyword_map_v1';
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
	public static function get_stop_words(): array {
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
	 *
	 * @return int
	 */
	public static function get_min_word_len(): int {
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
	 * @param int   $exclude_id Post ID to exclude (the one being edited).
	 * @param array $post_types Explicit list, or empty to auto-detect.
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
	 * Each entry in the returned map has:
	 *   post_id        => int
	 *   title          => string
	 *   url            => string
	 *   post_type      => string
	 *   keywords       => string[]             (ordered best-first for the matcher)
	 *   idf_weights    => array<string,float>  (keyword => IDF score for TF-IDF ranking)
	 *   taxonomy_terms => string[]             (lowercased category + tag names)
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
				'update_post_term_cache' => true,
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		update_post_caches( $posts, 'posts', false, true );

		$total_posts = count( $posts );
		$min_len     = self::get_min_word_len();
		$stop_words  = self::get_stop_words();

		// DF thresholds — how many posts a keyword can appear in before being
		// considered too common for its n-gram length.
		$df_thresholds = array(
			1 => max( 3, (int) round( $total_posts * 0.20 ) ),
			2 => max( 5, (int) round( $total_posts * 0.35 ) ),
			3 => max( 8, (int) round( $total_posts * 0.50 ) ),
			4 => max( 10, (int) round( $total_posts * 0.65 ) ),
		);

		// Slot budget per post per n-gram tier.
		$slot_budget = array(
			4 => 2,
			3 => 3,
			2 => 3,
			1 => 2,
		);

		// Pass 1 — extract all candidate keywords and build the corpus DF index.
		$raw      = array(); // post_id => string[].
		$df_index = array(); // keyword => int (how many posts contain it).

		foreach ( $posts as $post ) {
			$title_kws = self::extract_title_keywords( $post->post_title, $min_len, $stop_words );
			$body_kws  = self::extract_body_keywords( $post->post_content, $min_len, $stop_words );
			$slug_kws  = self::extract_slug_keywords( get_post_field( 'post_name', $post->ID ), $min_len, $stop_words );

			// Title keywords first (higher priority), then body, then slug.
			$candidates = array_values( array_unique( array_merge( $title_kws, $body_kws, $slug_kws ) ) );
			$candidates = apply_filters( 'linkiya_post_keywords', $candidates, $post->ID );

			$raw[ $post->ID ] = $candidates;

			foreach ( $candidates as $kw ) {
				$df_index[ $kw ] = ( $df_index[ $kw ] ?? 0 ) + 1;
			}
		}

		// Pass 2 — select final keywords per post, compute IDF weights, build map.
		$map = array();

		foreach ( $posts as $post ) {
			$candidates = $raw[ $post->ID ] ?? array();
			if ( empty( $candidates ) ) {
				continue;
			}

			// Group candidates by n-gram length, filter by DF threshold.
			$by_size = array(
				4 => array(),
				3 => array(),
				2 => array(),
				1 => array(),
			);

			foreach ( $candidates as $kw ) {
				$n_words   = substr_count( $kw, ' ' ) + 1;
				$n_clamped = min( $n_words, 4 );
				$df        = $df_index[ $kw ] ?? 1;

				if ( $df > $df_thresholds[ $n_clamped ] ) {
					continue;
				}

				$by_size[ $n_clamped ][] = array(
					'kw' => $kw,
					'df' => $df,
				);
			}

			// Within each tier, sort rarest first (lowest DF), then longer first on ties.
			foreach ( $by_size as $n => &$tier ) {
				usort(
					$tier,
					static function ( $a, $b ) {
						if ( $a['df'] !== $b['df'] ) {
							return $a['df'] - $b['df'];
						}
						return strlen( $b['kw'] ) - strlen( $a['kw'] );
					}
				);
			}
			unset( $tier );

			// Apply slot budget and compute IDF weights.
			$keywords    = array();
			$idf_weights = array();

			foreach ( array( 4, 3, 2, 1 ) as $n ) {
				$taken = 0;
				foreach ( $by_size[ $n ] as $item ) {
					if ( $taken >= $slot_budget[ $n ] ) {
						break;
					}
					$kw = $item['kw'];
					$df = $item['df'];
					// IDF = log( (N+1) / (df+1) ) + 1.0  (Laplace-smoothed, always >= 1).
					$idf                = log( ( $total_posts + 1 ) / ( $df + 1 ) ) + 1.0;
					$keywords[]         = $kw;
					$idf_weights[ $kw ] = round( $idf, 4 );
					++$taken;
				}
			}

			if ( empty( $keywords ) ) {
				continue;
			}

			// Collect category and tag names for taxonomy overlap scoring.
			// wp_get_post_terms() hits the term cache warmed above — no extra DB query.
			$raw_terms      = wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) );
			$taxonomy_terms = ! is_wp_error( $raw_terms )
				? array_map( 'strtolower', $raw_terms )
				: array();

			$map[] = array(
				'post_id'        => $post->ID,
				'title'          => $post->post_title,
				'url'            => get_permalink( $post ),
				'post_type'      => $post->post_type,
				'keywords'       => $keywords,
				'idf_weights'    => $idf_weights,
				'taxonomy_terms' => $taxonomy_terms,
			);
		}

		return $map;
	}

	/**
	 * Extract n-grams (singles, bi, tri, quad) from a post title.
	 *
	 * @param string            $title      Post title.
	 * @param int               $min_len    Minimum token character length.
	 * @param array<string,int> $stop_words Stop word lookup map.
	 * @return string[]
	 */
	public static function extract_title_keywords( string $title, int $min_len, array $stop_words ): array {
		$tokens = self::tokenize( $title );
		return self::tokens_to_ngrams( $tokens, $min_len, $stop_words, 4 );
	}

	/**
	 * Extract n-grams from post body content.
	 *
	 * Strips shortcodes and HTML first. Starts at bigrams (singles from body
	 * are too noisy without the IDF filter applied first).
	 *
	 * @param string            $body       Raw post content (HTML).
	 * @param int               $min_len    Minimum token character length.
	 * @param array<string,int> $stop_words Stop word lookup map.
	 * @return string[]
	 */
	public static function extract_body_keywords( string $body, int $min_len, array $stop_words ): array {
		$plain  = wp_strip_all_tags( strip_shortcodes( $body ) );
		$tokens = self::tokenize( $plain );
		return self::tokens_to_ngrams( $tokens, $min_len, $stop_words, 4, 2 );
	}

	/**
	 * Extract single keyword tokens from a post slug.
	 *
	 * @param string            $slug       Post slug (URL name).
	 * @param int               $min_len    Minimum token character length.
	 * @param array<string,int> $stop_words Stop word lookup map.
	 * @return string[]
	 */
	private static function extract_slug_keywords( string $slug, int $min_len, array $stop_words ): array {
		$tokens   = preg_split( '/-+/', strtolower( trim( $slug ) ), -1, PREG_SPLIT_NO_EMPTY );
		$keywords = array();
		foreach ( $tokens as $t ) {
			if ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) ) {
				$keywords[] = $t;
			}
		}
		return $keywords;
	}

	/**
	 * Normalize text to a clean, lowercase token array.
	 *
	 * Hyphens are removed (Well-Being → wellbeing), punctuation replaced with
	 * spaces, and consecutive whitespace collapsed.
	 *
	 * @param  string $text Raw text.
	 * @return string[]
	 */
	public static function tokenize( string $text ): array {
		$clean  = strtolower( preg_replace( '/[^\w\s]/u', ' ', str_replace( '-', '', $text ) ) );
		$tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );
		return false !== $tokens ? $tokens : array();
	}

	/**
	 * Convert a token array into n-grams up to $max_n words.
	 *
	 * Rules for a valid n-gram:
	 *   - First and last token must be content tokens (meet min_len, not stop word).
	 *   - For n >= 3: at least ceil(n/2) tokens must be content tokens.
	 *   - Single words (n=1): must meet min_len and not be a stop word.
	 *
	 * @param string[]          $tokens     Pre-tokenized word list.
	 * @param int               $min_len    Minimum character length for a content token.
	 * @param array<string,int> $stop_words Stop word lookup map.
	 * @param int               $max_n      Maximum n-gram size (1–4).
	 * @param int               $min_n      Minimum n-gram size (default 1).
	 * @return string[]
	 */
	public static function tokens_to_ngrams( array $tokens, int $min_len, array $stop_words, int $max_n = 4, int $min_n = 1 ): array {
		$token_count = count( $tokens );
		$keywords    = array();

		$is_content = static function ( string $t ) use ( $min_len, $stop_words ): bool {
			return ctype_digit( $t ) || ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) );
		};

		for ( $n = $min_n; $n <= $max_n; $n++ ) {
			for ( $i = 0; $i <= $token_count - $n; $i++ ) {
				$window = array_slice( $tokens, $i, $n );

				if ( 1 === $n ) {
					if ( $is_content( $window[0] ) ) {
						$keywords[] = $window[0];
					}
					continue;
				}

				// First and last tokens must be content tokens.
				if ( ! $is_content( $window[0] ) || ! $is_content( $window[ $n - 1 ] ) ) {
					continue;
				}

				// For trigrams and quadgrams: majority of tokens must be content tokens.
				if ( $n >= 3 ) {
					$content_count = count( array_filter( $window, $is_content ) );
					if ( $content_count < (int) ceil( $n / 2 ) ) {
						continue;
					}
				}

				$keywords[] = implode( ' ', $window );
			}
		}

		return array_values( array_unique( $keywords ) );
	}

	/**
	 * Legacy shim — kept so any Pro plugin calling extract_keywords() still works.
	 *
	 * @deprecated Use extract_title_keywords() instead.
	 * @param string $title Post title.
	 * @return string[]
	 */
	public static function extract_keywords( string $title ): array {
		return self::extract_title_keywords( $title, self::get_min_word_len(), self::get_stop_words() );
	}
}
