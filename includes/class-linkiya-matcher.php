<?php
/**
 * Linkiya Matcher — finds and applies internal link suggestions.
 *
 * @package Linkiya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_Matcher
 *
 * Extracts the main topics of the current post's content, then finds
 * other published posts whose keywords cover those topics.
 *
 * Matching direction: content topics → keyword map (topic-driven).
 * This means a post about "Reiki" finds the Reiki article even if
 * the word only appears once, because the topic is extracted from
 * the content itself rather than searching for pre-defined title keywords.
 */
class Linkiya_Matcher {

	/**
	 * Run the matching engine.
	 *
	 * @param  string $content          Raw post content (HTML from Gutenberg blocks).
	 * @param  array  $keyword_map      Output of Linkiya_Keyword_Extractor::get_keyword_map().
	 * @param  array  $meta_applied_ids Post IDs already linked, keyed by post ID.
	 * @return array  Suggestions.
	 */
	public static function find_suggestions( string $content, array $keyword_map, array $meta_applied_ids = array() ): array {

		// Normalize a URL for comparison: lowercase, strip protocol, www, and trailing slash.
		$normalize_url = static function ( string $url ): string {
			$url = strtolower( trim( $url ) );
			$url = preg_replace( '#^https?://#', '', $url );
			$url = preg_replace( '#^www\.#', '', $url );
			return rtrim( $url, '/' );
		};

		// Collect post IDs already linked anywhere in the content.
		$already_linked_ids = array();
		if ( preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/is', $content, $href_matches ) ) {
			foreach ( $href_matches[1] as $href ) {
				$href_norm = $normalize_url( $href );
				foreach ( $keyword_map as $entry ) {
					if ( ! empty( $entry['url'] ) && $normalize_url( $entry['url'] ) === $href_norm ) {
						$already_linked_ids[ (int) $entry['post_id'] ] = true;
					}
				}
			}
		}

		// Collect anchor texts already linked as a fallback for URL-mismatch cases.
		$already_linked_texts = array();
		if ( preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $anchor_matches ) ) {
			foreach ( $anchor_matches[1] as $anchor_html ) {
				$text = strtolower( trim( wp_strip_all_tags( $anchor_html ) ) );
				if ( '' !== $text ) {
					$already_linked_texts[ $text ] = true;
				}
			}
		}

		// Strip existing <a>…</a> so we don't re-suggest already-linked text.
		$stripped = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' LINKED_PLACEHOLDER ', $content );

		// Strip heading tags — never suggest links for text that only appears in headings.
		$stripped = preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $stripped );

		// Plain searchable text — used for topic extraction and anchor placement.
		$plain_text = wp_strip_all_tags( $stripped );

		// --- Topic-driven matching ---
		//
		// Step 1: Extract topics (significant words + bigrams) from the current
		// post's content. These represent what this article is ABOUT.
		//
		// Step 2: For each other post in the keyword map, check whether any of
		// its keywords match a topic from the current content.
		//
		// This reverses the old approach (title keyword → content search) so that
		// the content's meaning drives the suggestions, not the keyword map order.

		$content_topics = self::extract_content_topics( $plain_text );

		// Build a fast lookup: topic_string => frequency in content.
		// Higher frequency = more central to the article's meaning.
		$topic_lookup = array();
		foreach ( $content_topics as $topic => $freq ) {
			$topic_lookup[ strtolower( $topic ) ] = $freq;
		}

		// Score each post in the keyword map by how well its keywords match
		// the content's topics. Score = sum of frequencies of matching topics.
		$scored = array();
		foreach ( $keyword_map as $idx => $entry ) {
			if ( empty( $entry['post_id'] ) || empty( $entry['keywords'] ) || ! is_array( $entry['keywords'] ) ) {
				continue;
			}
			$entry_id = (int) $entry['post_id'];
			if ( isset( $meta_applied_ids[ $entry_id ] ) || isset( $already_linked_ids[ $entry_id ] ) ) {
				continue;
			}

			$best_keyword = null;
			$best_score   = 0;

			foreach ( $entry['keywords'] as $keyword ) {
				$kw_lower = strtolower( $keyword );

				// Skip if already used as anchor text.
				if ( isset( $already_linked_texts[ $kw_lower ] ) ) {
					continue;
				}

				// Check if this keyword exists verbatim in the content.
				if ( ! self::keyword_exists_in_text( $keyword, $plain_text ) ) {
					continue;
				}

				// Score = frequency of this keyword/topic in the content.
				// Bigrams score higher than singles when frequency is equal
				// because they are more specific.
				$freq      = $topic_lookup[ $kw_lower ] ?? 1;
				$is_bigram = strpos( $keyword, ' ' ) !== false;
				$score     = $freq * ( $is_bigram ? 2 : 1 );

				if ( $score > $best_score ) {
					$best_score   = $score;
					$best_keyword = $keyword;
				}
			}

			if ( null !== $best_keyword ) {
				$scored[] = array(
					'score'      => $best_score,
					'keyword'    => $best_keyword,
					'post_id'    => $entry['post_id'],
					'post_title' => $entry['title'],
					'post_type'  => $entry['post_type'] ?? 'post',
					'url'        => $entry['url'],
					'nofollow'   => ! empty( $entry['nofollow'] ),
					'new_tab'    => ! empty( $entry['new_tab'] ),
				);
			}
		}

		// Sort by score descending — posts whose keywords appear most frequently
		// in the content (= most topically relevant) come first.
		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Deduplicate: one suggestion per keyword string across all posts.
		$matched_keywords = array();
		$suggestions      = array();

		foreach ( $scored as $item ) {
			if ( isset( $matched_keywords[ $item['keyword'] ] ) ) {
				continue;
			}
			$matched_keywords[ $item['keyword'] ] = true;
			unset( $item['score'] );
			$suggestions[] = $item;
		}

		return $suggestions;
	}

	/**
	 * Extract significant topics from the current post's plain text content.
	 *
	 * Returns an associative array of topic => frequency, where frequency
	 * is how many times that word or bigram appears in the content.
	 * Only terms meeting the minimum word length are included.
	 * Stop words are excluded from singles but allowed as connectors in bigrams.
	 *
	 * @param  string $text Plain text content of the current post.
	 * @return array<string, int> topic => frequency map.
	 */
	private static function extract_content_topics( string $text ): array {
		$settings   = Linkiya_Settings::get();
		$min_len    = max( 2, (int) ( $settings['min_word_length'] ?? 4 ) );
		$stop_words = self::get_stop_words( $settings );

		// Normalise: lowercase, strip punctuation, collapse whitespace.
		$clean  = strtolower( preg_replace( '/[^\w\s]/u', ' ', str_replace( '-', '', $text ) ) );
		$tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );

		$topics      = array();
		$token_count = count( $tokens );

		$is_content_token = static function ( string $t ) use ( $stop_words, $min_len ): bool {
			return strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] );
		};

		// Count single-word frequencies.
		foreach ( $tokens as $t ) {
			if ( $is_content_token( $t ) ) {
				$topics[ $t ] = ( $topics[ $t ] ?? 0 ) + 1;
			}
		}

		// Count bigram frequencies — both tokens must be content tokens.
		for ( $i = 0; $i < $token_count - 1; $i++ ) {
			$a = $tokens[ $i ];
			$b = $tokens[ $i + 1 ];
			if ( $is_content_token( $a ) && $is_content_token( $b ) ) {
				$bigram            = $a . ' ' . $b;
				$topics[ $bigram ] = ( $topics[ $bigram ] ?? 0 ) + 1;
			}
		}

		return $topics;
	}

	/**
	 * Build stop word map from settings.
	 *
	 * @param  array $settings Linkiya settings array.
	 * @return array<string, int>
	 */
	private static function get_stop_words( array $settings ): array {
		$raw   = $settings['stop_words'] ?? '';
		$words = array_filter( array_map( 'trim', explode( "\n", strtolower( $raw ) ) ) );
		return array_fill_keys( array_values( $words ), 1 );
	}

	/**
	 * Check if $keyword appears as a whole word (case-insensitive) in $text.
	 *
	 * @param  string $keyword Word or phrase to search for.
	 * @param  string $text    Plain text to search within.
	 * @return bool
	 */
	private static function keyword_exists_in_text( string $keyword, string $text ): bool {
		$escaped = preg_quote( $keyword, '/' );
		return (bool) preg_match( '/\b' . $escaped . '\b/iu', $text );
	}

	/**
	 * Apply accepted suggestions to the raw Gutenberg HTML content.
	 *
	 * Only links the FIRST occurrence of each keyword in the content,
	 * and never links text that is already inside an <a> tag.
	 *
	 * @param  string $content     Original post content HTML.
	 * @param  array  $accepted    Accepted suggestions (same shape as find_suggestions output).
	 * @param  string $link_target Link target attribute value.
	 * @param  string $link_rel    Link rel attribute value.
	 * @return string Modified content HTML.
	 */
	public static function apply_links( string $content, array $accepted, string $link_target = '_self', string $link_rel = '' ): string {
		foreach ( $accepted as $suggestion ) {
			$keyword = $suggestion['keyword'];
			$anchor  = ! empty( $suggestion['anchor'] ) ? $suggestion['anchor'] : $keyword;
			$url     = esc_url( $suggestion['url'] );
			$title   = esc_attr( $suggestion['post_title'] ?? '' );

			$target = $link_target;
			$rel    = $link_rel;

			if ( ! empty( $suggestion['new_tab'] ) ) {
				$target = '_blank';
			}
			if ( ! empty( $suggestion['nofollow'] ) ) {
				$rel = trim( 'nofollow ' . $rel );
			}

			$content = self::link_first_occurrence( $content, $keyword, $anchor, $url, $title, $target, $rel );
		}

		return $content;
	}

	/**
	 * Find the first whole-word occurrence of $keyword in $content that is
	 * NOT inside an existing <a> tag or heading, and wrap it with a link.
	 *
	 * @param string $content Post content HTML.
	 * @param string $keyword Keyword to find and link.
	 * @param string $anchor  Anchor text to display (may differ from keyword).
	 * @param string $url     Destination URL.
	 * @param string $title   Link title attribute.
	 * @param string $target  Link target attribute.
	 * @param string $rel     Link rel attribute.
	 * @return string
	 */
	private static function link_first_occurrence( string $content, string $keyword, string $anchor, string $url, string $title, string $target = '_self', string $rel = '' ): string {
		$linked = false;

		// Split on existing <a> tags AND heading tags — never link inside either.
		$pattern = '/(<a\b[^>]*>.*?<\/a>|<h[1-6]\b[^>]*>.*?<\/h[1-6]>)/is';
		$parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		$attrs = 'href="' . esc_attr( $url ) . '" title="' . esc_attr( $title ) . '"';
		if ( $target && '_self' !== $target ) {
			$attrs .= ' target="' . esc_attr( $target ) . '"';
		}
		if ( $rel ) {
			$attrs .= ' rel="' . esc_attr( $rel ) . '"';
		}
		$attrs = apply_filters( 'linkiya_link_attrs', $attrs, $url );
		$attrs = wp_kses( '<a ' . $attrs . '>', array( 'a' => array_fill_keys( array( 'href', 'title', 'target', 'rel', 'class', 'id' ), true ) ) );
		$attrs = trim( preg_replace( '/^<a\s*/i', '', rtrim( $attrs, '>' ) ) );

		$result = '';
		foreach ( $parts as $part ) {
			if ( ! $linked && ! preg_match( '/^<a\b/i', $part ) && ! preg_match( '/^<h[1-6]\b/i', $part ) ) {
				$escaped    = preg_quote( $keyword, '/' );
				$link_text  = ( $anchor !== $keyword ) ? esc_html( $anchor ) : null;
				$link_open  = '<a ' . $attrs . '>';
				$link_close = '</a>';
				$count      = 0;
				$new_part   = preg_replace_callback(
					'/\b(' . $escaped . ')\b/iu',
					static function ( $matches ) use ( $link_open, $link_close, $link_text ) {
						return $link_open . ( null !== $link_text ? $link_text : esc_html( $matches[1] ) ) . $link_close;
					},
					$part,
					1,
					$count
				);
				if ( $count > 0 ) {
					$linked = true;
					$part   = $new_part;
				}
			}
			$result .= $part;
		}

		return $result;
	}
}
