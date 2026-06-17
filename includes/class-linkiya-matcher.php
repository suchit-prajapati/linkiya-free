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

		// Also collect anchor texts already linked, as a fallback for URL-mismatch cases.
		$already_linked_texts = array();
		if ( preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $anchor_matches ) ) {
			foreach ( $anchor_matches[1] as $anchor_html ) {
				$text = strtolower( trim( wp_strip_all_tags( $anchor_html ) ) );
				if ( '' !== $text ) {
					$already_linked_texts[ $text ] = true;
				}
			}
		}

		// Strip existing <a>…</a> spans so we don't re-suggest already-linked text.
		$stripped = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' LINKED_PLACEHOLDER ', $content );

		// Strip heading tags so keywords that only appear in headings are not suggested.
		$stripped = preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $stripped );

		// Strip all other HTML tags to get plain searchable text.
		$plain_text = wp_strip_all_tags( $stripped );

		$suggestions      = array();
		$matched_keywords = array(); // O(1) set: keyword => true.
		$matched_post_ids = array(); // O(1) set: post_id => true.

		foreach ( $keyword_map as $entry ) {
			if ( empty( $entry['post_id'] ) || empty( $entry['keywords'] ) || ! is_array( $entry['keywords'] ) ) {
				continue;
			}
			$entry_id = (int) $entry['post_id'];

			if ( $entry_id > 0 && isset( $matched_post_ids[ $entry_id ] ) ) {
				continue;
			}

			// Skip posts already linked — check meta first (most reliable), then content scan.
			if ( $entry_id > 0 && ( isset( $meta_applied_ids[ $entry_id ] ) || isset( $already_linked_ids[ $entry_id ] ) ) ) {
				continue;
			}

			// Keywords are already sorted longest-first by the extractor.
			foreach ( $entry['keywords'] as $keyword ) {
				if ( isset( $matched_keywords[ $keyword ] ) ) {
					continue; // Keyword already used for another post.
				}

				// Skip if this keyword is already used as anchor text in an existing link.
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
					break; // One keyword per post is enough.
				}
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
		// \b is a word boundary — works well for ASCII/Latin text.
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

			// Per-suggestion overrides for external/affiliate links.
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
	 * NOT inside an existing <a> tag, and wrap it with a link.
	 *
	 * Strategy: split content on <a>…</a> blocks, operate only on the
	 * text/non-link segments.
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

		// Build the <a> tag with optional target and rel.
		$attrs = 'href="' . esc_attr( $url ) . '" title="' . esc_attr( $title ) . '"';
		if ( $target && '_self' !== $target ) {
			$attrs .= ' target="' . esc_attr( $target ) . '"';
		}
		if ( $rel ) {
			$attrs .= ' rel="' . esc_attr( $rel ) . '"';
		}
		// Allow Pro plugin to add extra link attributes (e.g. click tracking).
		$attrs = apply_filters( 'linkiya_link_attrs', $attrs, $url );
		$attrs = wp_kses( '<a ' . $attrs . '>', array( 'a' => array_fill_keys( array( 'href', 'title', 'target', 'rel', 'class', 'id' ), true ) ) );
		$attrs = trim( preg_replace( '/^<a\s*/i', '', rtrim( $attrs, '>' ) ) );

		$result = '';
		foreach ( $parts as $part ) {
			if ( ! $linked && ! preg_match( '/^<a\b/i', $part ) && ! preg_match( '/^<h[1-6]\b/i', $part ) ) {
				$escaped = preg_quote( $keyword, '/' );
				// Use preg_replace_callback to avoid backreference interpretation in anchor text.
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
