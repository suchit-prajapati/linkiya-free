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
 * Given the current post's plain text content and the keyword map from
 * Linkiya_Keyword_Extractor, finds which keywords appear (whole-word, not
 * already linked) and returns a deduplicated suggestion list.
 */
class Linkiya_Matcher {

	/**
	 * Run the matching engine.
	 *
	 * @param  string $content          Raw post content (HTML from Gutenberg blocks).
	 * @param  array  $keyword_map      Output of Linkiya_Keyword_Extractor::get_keyword_map().
	 * @param  array  $meta_applied_ids Post IDs already linked, keyed by post ID.
	 * @return array  Suggestions: [
	 *   [
	 *     'keyword'    => string,
	 *     'post_id'    => int,
	 *     'post_title' => string,
	 *     'url'        => string,
	 *   ],
	 *   ...
	 * ]
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

		// Get plain searchable text.
		$plain_text = wp_strip_all_tags( $stripped );

		// Flatten all (keyword, entry_index) pairs from the keyword map into one list,
		// skipping posts that are already linked or excluded.
		$all_candidates = array();
		foreach ( $keyword_map as $idx => $entry ) {
			if ( empty( $entry['post_id'] ) || empty( $entry['keywords'] ) || ! is_array( $entry['keywords'] ) ) {
				continue;
			}
			$entry_id = (int) $entry['post_id'];
			if ( isset( $meta_applied_ids[ $entry_id ] ) || isset( $already_linked_ids[ $entry_id ] ) ) {
				continue;
			}
			foreach ( $entry['keywords'] as $keyword ) {
				$all_candidates[] = array( $keyword, $idx );
			}
		}

		// Sort all candidates globally so the most specific/matchable keywords win
		// regardless of which post they come from or the post's position in the map.
		// Priority: bigrams without digits > bigrams with digits > single words.
		// Within each tier: longer first.
		usort(
			$all_candidates,
			static function ( $a, $b ) {
				$a_kw    = $a[0];
				$b_kw    = $b[0];
				$a_is_bi = strpos( $a_kw, ' ' ) !== false;
				$b_is_bi = strpos( $b_kw, ' ' ) !== false;
				$a_digit = (int) preg_match( '/\d/', $a_kw );
				$b_digit = (int) preg_match( '/\d/', $b_kw );

				// Tier: bigram-no-digit=0, bigram-with-digit=1, single=2.
				$a_tier = $a_is_bi ? $a_digit : 2;
				$b_tier = $b_is_bi ? $b_digit : 2;

				if ( $a_tier !== $b_tier ) {
					return $a_tier - $b_tier;
				}
				return strlen( $b_kw ) - strlen( $a_kw );
			}
		);

		$suggestions      = array();
		$matched_keywords = array();
		$matched_post_ids = array();

		foreach ( $all_candidates as list( $keyword, $idx ) ) {
			$entry    = $keyword_map[ $idx ];
			$entry_id = (int) $entry['post_id'];

			if ( isset( $matched_post_ids[ $entry_id ] ) ) {
				continue; // Already have a suggestion for this post.
			}
			if ( isset( $matched_keywords[ $keyword ] ) ) {
				continue; // Keyword already claimed by another post.
			}
			if ( isset( $already_linked_texts[ strtolower( $keyword ) ] ) ) {
				continue;
			}

			if ( self::keyword_exists_in_text( $keyword, $plain_text ) ) {
				$suggestions[]                 = array(
					'keyword'    => $keyword,
					'post_id'    => $entry['post_id'],
					'post_title' => $entry['title'],
					'post_type'  => $entry['post_type'] ?? 'post',
					'url'        => $entry['url'],
					'nofollow'   => ! empty( $entry['nofollow'] ),
					'new_tab'    => ! empty( $entry['new_tab'] ),
				);
				$matched_keywords[ $keyword ]  = true;
				$matched_post_ids[ $entry_id ] = true;
			}
		}

		return $suggestions;
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
