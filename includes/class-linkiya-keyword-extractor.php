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

	/**
	 * English + common Hindi-romanized stop words (as associative map for O(1) lookup).
	 *
	 * @var array<string, int>
	 */
	private static $stop_words = array(
		// Articles / prepositions / conjunctions.
		'a'            => 1, 'an'          => 1, 'the'         => 1, 'and'         => 1,
		'or'           => 1, 'but'         => 1, 'in'          => 1, 'on'          => 1,
		'at'           => 1, 'to'          => 1, 'for'         => 1, 'of'          => 1,
		'with'         => 1, 'by'          => 1, 'from'        => 1, 'up'          => 1,
		'about'        => 1, 'into'        => 1, 'through'     => 1, 'during'      => 1,
		'after'        => 1, 'before'      => 1, 'since'       => 1, 'until'       => 1,
		'unless'       => 1, 'as'          => 1, 'if'          => 1, 'then'        => 1,
		'because'      => 1, 'while'       => 1, 'although'    => 1, 'though'      => 1,
		'than'         => 1, 'vs'          => 1, 'via'         => 1, 'per'         => 1,
		// Auxiliary verbs.
		'is'           => 1, 'are'         => 1, 'was'         => 1, 'were'        => 1,
		'be'           => 1, 'been'        => 1, 'being'       => 1, 'have'        => 1,
		'has'          => 1, 'had'         => 1, 'do'          => 1, 'does'        => 1,
		'did'          => 1, 'will'        => 1, 'would'       => 1, 'could'       => 1,
		'should'       => 1, 'may'         => 1, 'might'       => 1, 'shall'       => 1,
		'can'          => 1, 'need'        => 1, 'dare'        => 1,
		// Pronouns.
		'i'            => 1, 'me'          => 1, 'my'          => 1, 'we'          => 1,
		'our'          => 1, 'us'          => 1, 'you'         => 1, 'your'        => 1,
		'he'           => 1, 'him'         => 1, 'his'         => 1, 'she'         => 1,
		'her'          => 1, 'hers'        => 1, 'it'          => 1, 'its'         => 1,
		'they'         => 1, 'them'        => 1, 'their'       => 1, 'who'         => 1,
		'whom'         => 1, 'this'        => 1, 'that'        => 1, 'these'       => 1,
		'those'        => 1,
		// Question words.
		'how'          => 1, 'why'         => 1, 'when'        => 1, 'where'       => 1,
		'what'         => 1, 'which'       => 1, 'whats'       => 1,
		// Negation / quantifiers.
		'not'          => 1, 'no'          => 1, 'nor'         => 1, 'so'          => 1,
		'yet'          => 1, 'both'        => 1, 'either'      => 1, 'neither'     => 1,
		'each'         => 1, 'few'         => 1, 'more'        => 1, 'most'        => 1,
		'other'        => 1, 'some'        => 1, 'such'        => 1, 'too'         => 1,
		'very'         => 1, 'just'        => 1, 'only'        => 1, 'also'        => 1,
		'even'         => 1, 'ever'        => 1, 'never'       => 1, 'always'      => 1,
		'often'        => 1, 'already'     => 1, 'still'       => 1, 'again'       => 1,
		'back'         => 1, 'away'        => 1, 'here'        => 1, 'there'       => 1,
		'now'          => 1, 'then'        => 1, 'once'        => 1, 'actually'    => 1,
		'really'       => 1, 'truly'       => 1, 'simply'      => 1,
		// Generic action verbs (too broad as anchors).
		'get'          => 1, 'got'         => 1, 'make'        => 1, 'made'        => 1,
		'take'         => 1, 'know'        => 1, 'go'          => 1, 'come'        => 1,
		'say'          => 1, 'see'         => 1, 'use'         => 1, 'find'        => 1,
		'give'         => 1, 'tell'        => 1, 'work'        => 1, 'call'        => 1,
		'try'          => 1, 'ask'         => 1, 'feel'        => 1, 'become'      => 1,
		'leave'        => 1, 'put'         => 1, 'mean'        => 1, 'keep'        => 1,
		'let'          => 1, 'begin'       => 1, 'show'        => 1, 'hear'        => 1,
		'play'         => 1, 'run'         => 1, 'move'        => 1, 'live'        => 1,
		'believe'      => 1, 'hold'        => 1, 'bring'       => 1, 'happen'      => 1,
		'write'        => 1, 'provide'     => 1, 'sit'         => 1, 'stand'       => 1,
		'lose'         => 1, 'pay'         => 1, 'meet'        => 1, 'include'     => 1,
		'continue'     => 1, 'set'         => 1, 'learn'       => 1, 'change'      => 1,
		'lead'         => 1, 'understand'  => 1, 'watch'       => 1, 'follow'      => 1,
		'stop'         => 1, 'create'      => 1, 'speak'       => 1, 'read'        => 1,
		'spend'        => 1, 'grow'        => 1, 'open'        => 1, 'walk'        => 1,
		'win'          => 1, 'offer'       => 1, 'remember'    => 1, 'love'        => 1,
		'consider'     => 1, 'avoid'       => 1, 'improve'     => 1, 'reduce'      => 1,
		'manage'       => 1, 'boost'       => 1, 'build'       => 1, 'start'       => 1,
		'help'         => 1, 'need'        => 1, 'want'        => 1, 'overcome'    => 1,
		'achieve'      => 1, 'reach'       => 1, 'share'       => 1, 'choose'      => 1,
		'develop'      => 1, 'increase'    => 1, 'decrease'    => 1, 'deal'        => 1,
		'handle'       => 1, 'navigate'    => 1, 'explore'     => 1, 'discover'    => 1,
		'transform'    => 1, 'unlock'      => 1, 'master'      => 1, 'beat'        => 1,
		'fix'          => 1, 'solve'       => 1, 'protect'     => 1, 'support'     => 1,
		// Generic nouns — too common to be meaningful anchors.
		'life'         => 1, 'self'        => 1, 'time'        => 1, 'ways'        => 1,
		'way'          => 1, 'tips'        => 1, 'tip'         => 1, 'guide'       => 1,
		'book'         => 1, 'care'        => 1, 'day'         => 1, 'days'        => 1,
		'year'         => 1, 'years'       => 1, 'week'        => 1, 'month'       => 1,
		'time'         => 1, 'times'       => 1, 'hour'        => 1, 'type'        => 1,
		'types'        => 1, 'kind'        => 1, 'part'        => 1, 'step'        => 1,
		'steps'        => 1, 'list'        => 1, 'thing'       => 1, 'things'      => 1,
		'idea'         => 1, 'ideas'       => 1, 'rule'        => 1, 'rules'       => 1,
		'fact'         => 1, 'facts'       => 1, 'sign'        => 1, 'signs'       => 1,
		'reason'       => 1, 'reasons'     => 1, 'word'        => 1, 'words'       => 1,
		'mind'         => 1, 'body'        => 1, 'soul'        => 1, 'world'       => 1,
		'people'       => 1, 'person'      => 1, 'man'         => 1, 'woman'       => 1,
		'men'          => 1, 'women'       => 1, 'child'       => 1, 'children'    => 1,
		'team'         => 1, 'group'       => 1, 'community'   => 1, 'family'      => 1,
		'home'         => 1, 'place'       => 1, 'side'        => 1, 'point'       => 1,
		'sense'        => 1, 'level'       => 1, 'process'     => 1, 'system'      => 1,
		'line'         => 1, 'plan'        => 1, 'goal'        => 1, 'goals'       => 1,
		'role'         => 1, 'area'        => 1, 'form'        => 1, 'case'        => 1,
		'power'        => 1, 'energy'      => 1, 'force'       => 1, 'state'       => 1,
		'space'        => 1, 'moment'      => 1, 'number'      => 1, 'name'        => 1,
		'example'      => 1, 'examples'    => 1, 'result'      => 1, 'results'     => 1,
		'impact'       => 1, 'effect'      => 1, 'effects'     => 1, 'cause'       => 1,
		'difference'   => 1, 'question'    => 1, 'answer'      => 1, 'problem'     => 1,
		'solution'     => 1, 'method'      => 1, 'approach'    => 1, 'pattern'     => 1,
		// Generic adjectives — meaningless as solo anchors.
		'good'         => 1, 'bad'         => 1, 'best'        => 1, 'worst'       => 1,
		'new'          => 1, 'old'         => 1, 'big'         => 1, 'small'       => 1,
		'great'        => 1, 'little'      => 1, 'long'        => 1, 'short'       => 1,
		'high'         => 1, 'low'         => 1, 'next'        => 1, 'last'        => 1,
		'first'        => 1, 'second'      => 1, 'third'       => 1, 'real'        => 1,
		'true'         => 1, 'false'       => 1, 'right'       => 1, 'wrong'       => 1,
		'easy'         => 1, 'hard'        => 1, 'free'        => 1, 'full'        => 1,
		'able'         => 1, 'sure'        => 1, 'clear'       => 1, 'deep'        => 1,
		'fast'         => 1, 'slow'        => 1, 'same'        => 1, 'different'   => 1,
		'common'       => 1, 'simple'      => 1, 'basic'       => 1, 'quick'       => 1,
		'early'        => 1, 'late'        => 1, 'final'       => 1, 'strong'      => 1,
		'weak'         => 1, 'major'       => 1, 'minor'       => 1, 'complete'    => 1,
		'possible'     => 1, 'important'   => 1, 'effective'   => 1, 'healthy'     => 1,
		'natural'      => 1, 'positive'    => 1, 'negative'    => 1, 'normal'      => 1,
		'daily'        => 1, 'morning'     => 1, 'evening'     => 1, 'night'       => 1,
		'ultimate'     => 1, 'complete'    => 1, 'practical'   => 1, 'powerful'    => 1,
		'essential'    => 1, 'helpful'     => 1, 'useful'      => 1, 'better'      => 1,
		'worse'        => 1, 'amazing'     => 1, 'incredible'  => 1, 'proven'      => 1,
		'beginners'    => 1, 'beginner'    => 1, 'advanced'    => 1, 'actually'    => 1,
		'mindfully'    => 1, 'naturally'   => 1, 'physically'  => 1, 'mentally'    => 1,
		'emotionally'  => 1, 'effectively' => 1, 'successfully'=> 1,
		// Content / publishing meta words.
		'tips'         => 1, 'guide'       => 1, 'guides'      => 1, 'tutorial'    => 1,
		'review'       => 1, 'overview'    => 1, 'intro'       => 1, 'introduction'=> 1,
		'summary'      => 1, 'complete'    => 1, 'ultimate'    => 1, 'definitive'  => 1,
		'explained'    => 1, 'everything'  => 1, 'know'        => 1, 'need'        => 1,
		'recommendations' => 1, 'editorial' => 1, 'commitment' => 1, 'affiliate'   => 1,
		// Generic words that slip through length filter.
		'setting'      => 1, 'settings'    => 1, 'problems'    => 1, 'problem'     => 1,
		'worrying'     => 1, 'matters'     => 1, 'realistic'   => 1, 'relaxation'  => 1,
		'triggers'     => 1, 'trigger'     => 1, 'meaningful'  => 1, 'challenge'   => 1,
		'challenges'   => 1, 'wellbeing'   => 1, 'wellness'    => 1, 'feeling'     => 1,
		'feelings'     => 1, 'emotions'    => 1, 'emotion'     => 1, 'thinking'    => 1,
		'thoughts'     => 1, 'thought'     => 1, 'behavior'    => 1, 'behaviour'   => 1,
		'response'     => 1, 'responses'   => 1, 'reaction'    => 1, 'reactions'   => 1,
		'situation'    => 1, 'situations'  => 1, 'experience'  => 1, 'experiences' => 1,
		'activity'     => 1, 'activities'  => 1, 'exercise'    => 1, 'exercises'   => 1,
		'practice'     => 1, 'practices'   => 1, 'technique'   => 1, 'techniques'  => 1,
		'strategy'     => 1, 'strategies'  => 1, 'skill'       => 1, 'skills'      => 1,
		'habit'        => 1, 'habits'      => 1, 'routine'     => 1, 'routines'    => 1,
		'benefit'      => 1, 'benefits'    => 1, 'advantage'   => 1, 'disadvantage'=> 1,
		'relation'     => 1, 'relations'   => 1, 'connection'  => 1, 'connections' => 1,
		'conversation' => 1, 'conversations'=> 1,'communication'=> 1,'interaction' => 1,
		'awareness'    => 1, 'knowledge'   => 1, 'learning'    => 1, 'teaching'    => 1,
		'training'     => 1, 'coaching'    => 1, 'therapy'     => 1, 'treatment'   => 1,
		'condition'    => 1, 'conditions'  => 1, 'disorder'    => 1, 'symptoms'    => 1,
		'recovery'     => 1, 'healing'     => 1, 'prevention'  => 1, 'protection'  => 1,
		'potential'    => 1, 'capacity'    => 1, 'ability'     => 1, 'abilities'   => 1,
		'quality'      => 1, 'standard'    => 1, 'value'       => 1, 'values'      => 1,
		'principle'    => 1, 'principles'  => 1, 'concept'     => 1, 'concepts'    => 1,
		'foundation'   => 1, 'framework'   => 1, 'structure'   => 1, 'model'       => 1,
		'research'     => 1, 'studies'     => 1, 'science'     => 1, 'evidence'    => 1,
		'inspiring'    => 1, 'motivated'   => 1, 'motivation'  => 1, 'inspiration' => 1,
		// Hindi romanized.
		'kya'          => 1, 'kaise'       => 1, 'kyun'        => 1, 'aur'         => 1,
		'hai'          => 1, 'hain'        => 1, 'ka'          => 1, 'ki'          => 1,
		'ke'           => 1, 'se'          => 1, 'mein'        => 1, 'par'         => 1,
		'ko'           => 1, 'ne'          => 1, 'ek'          => 1, 'yeh'         => 1,
		'woh'          => 1, 'apna'        => 1, 'apni'        => 1, 'apne'        => 1,
		'bhi'          => 1, 'hi'          => 1,
	);

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

		// Single words — include all non-stop-word tokens (DF + length filter happens in build_keyword_map).
		foreach ( $tokens as $t ) {
			if ( strlen( $t ) >= $min_len && ! isset( self::$stop_words[ $t ] ) ) {
				$keywords[] = $t;
			}
		}

		// Bigrams — both tokens must pass the stop word filter (length >= min_len relaxed to 3 for bigrams).
		for ( $i = 0; $i < $token_count - 1; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			if (
				strlen( $a ) >= 3 && ! isset( self::$stop_words[ $a ] ) &&
				strlen( $b ) >= 3 && ! isset( self::$stop_words[ $b ] )
			) {
				$keywords[] = $a . ' ' . $b;
			}
		}

		// Trigrams — three consecutive non-stop tokens for highly specific phrases.
		for ( $i = 0; $i < $token_count - 2; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			$c = $tokens[ $i + 2 ];
			if (
				strlen( $a ) >= 3 && ! isset( self::$stop_words[ $a ] ) &&
				strlen( $b ) >= 3 && ! isset( self::$stop_words[ $b ] ) &&
				strlen( $c ) >= 3 && ! isset( self::$stop_words[ $c ] )
			) {
				$keywords[] = $a . ' ' . $b . ' ' . $c;
			}
		}

		$keywords = array_unique( $keywords );
		// Sort longest-first so trigrams > bigrams > singles in priority.
		usort( $keywords, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		return array_values( $keywords );
	}
}
