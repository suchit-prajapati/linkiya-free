<?php
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
     * @param  string $content     Raw post content (HTML from Gutenberg blocks).
     * @param  array  $keyword_map Output of Linkiya_Keyword_Extractor::get_keyword_map().
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
    public static function find_suggestions( string $content, array $keyword_map ): array {

        // Strip existing <a>…</a> spans so we don't re-suggest already-linked text.
        $stripped = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' LINKED_PLACEHOLDER ', $content );

        // Also strip all other HTML tags to get plain searchable text.
        $plain_text = wp_strip_all_tags( $stripped );

        $suggestions      = [];
        $matched_keywords = []; // O(1) set: keyword => true
        $matched_post_ids = []; // O(1) set: post_id => true

        foreach ( $keyword_map as $entry ) {
            $entry_id = (int) $entry['post_id'];

            if ( $entry_id > 0 && isset( $matched_post_ids[ $entry_id ] ) ) {
                continue;
            }

            // Keywords are already sorted longest-first by the extractor.
            foreach ( $entry['keywords'] as $keyword ) {
                if ( isset( $matched_keywords[ $keyword ] ) ) {
                    continue; // keyword already used for another post
                }

                if ( self::keyword_exists_in_text( $keyword, $plain_text ) ) {
                    $suggestions[]                    = [
                        'keyword'    => $keyword,
                        'post_id'    => $entry['post_id'],
                        'post_title' => $entry['title'],
                        'post_type'  => $entry['post_type'] ?? 'post',
                        'url'        => $entry['url'],
                        'nofollow'   => ! empty( $entry['nofollow'] ),
                        'new_tab'    => ! empty( $entry['new_tab'] ),
                    ];
                    $matched_keywords[ $keyword ]     = true;
                    $matched_post_ids[ $entry_id ]    = true;
                    break; // one keyword per post is enough
                }
            }
        }

        return $suggestions;
    }

    /**
     * Check if $keyword appears as a whole word (case-insensitive) in $text.
     *
     * @param  string $keyword
     * @param  string $text
     * @return bool
     */
    private static function keyword_exists_in_text( string $keyword, string $text ): bool {
        $escaped = preg_quote( $keyword, '/' );
        // \b is a word boundary — works well for ASCII/Latin text
        return (bool) preg_match( '/\b' . $escaped . '\b/iu', $text );
    }

    /**
     * Apply accepted suggestions to the raw Gutenberg HTML content.
     *
     * Only links the FIRST occurrence of each keyword in the content,
     * and never links text that is already inside an <a> tag.
     *
     * @param  string $content     Original post content HTML.
     * @param  array  $accepted    Array of accepted suggestions (same shape as find_suggestions output).
     * @return string              Modified content HTML.
     */
    public static function apply_links( string $content, array $accepted, string $link_target = '_self', string $link_rel = '' ): string {
        foreach ( $accepted as $suggestion ) {
            $keyword = $suggestion['keyword'];
            $anchor  = ! empty( $suggestion['anchor'] ) ? $suggestion['anchor'] : $keyword;
            $url     = esc_url( $suggestion['url'] );
            $title   = esc_attr( $suggestion['post_title'] ?? '' );

            // P6: per-suggestion overrides for external/affiliate links
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
     * @param string $content
     * @param string $keyword
     * @param string $url
     * @param string $title
     * @return string
     */
    private static function link_first_occurrence( string $content, string $keyword, string $anchor, string $url, string $title, string $target = '_self', string $rel = '' ): string {
        $linked = false;

        $pattern = '/(<a\b[^>]*>.*?<\/a>)/is';
        $parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        // Build the <a> tag with optional target and rel
        $attrs  = 'href="' . esc_attr( $url ) . '" title="' . esc_attr( $title ) . '"';
        if ( $target && $target !== '_self' ) {
            $attrs .= ' target="' . esc_attr( $target ) . '"';
        }
        if ( $rel ) {
            $attrs .= ' rel="' . esc_attr( $rel ) . '"';
        }
        // Allow Pro plugin to add extra link attributes (e.g. click tracking)
        $attrs = apply_filters( 'linkiya_link_attrs', $attrs, $url );
        $attrs = wp_kses( '<a ' . $attrs . '>', [ 'a' => array_fill_keys( [ 'href', 'title', 'target', 'rel', 'class', 'id', 'data-*' ], true ) ] );
        $attrs = trim( preg_replace( '/^<a\s*/i', '', rtrim( $attrs, '>' ) ) );

        $result = '';
        foreach ( $parts as $part ) {
            if ( ! $linked && ! preg_match( '/^<a\b/i', $part ) ) {
                $escaped  = preg_quote( $keyword, '/' );
                // F4: replace keyword with custom anchor text if different
                $link_text = ( $anchor !== $keyword ) ? esc_html( $anchor ) : '$1';
                $new_part = preg_replace(
                    '/\b(' . $escaped . ')\b/iu',
                    '<a ' . $attrs . '>' . $link_text . '</a>',
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
