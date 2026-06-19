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
 * Scoring pipeline (find_suggestions):
 *
 *   1. Strip existing links and headings from content → plain text.
 *   2. Extract content topics (singles + bigrams + trigrams + quadgrams)
 *      with per-term frequency counts.
 *   3. For each post in the keyword map, find the best-scoring anchor phrase:
 *      either the indexed keyword verbatim, OR any body phrase that shares a
 *      content token with the indexed keyword (e.g. body has "emotional boundaries",
 *      post indexed "boundaries" → suggest "emotional boundaries" as anchor).
 *
 * Score formula per keyword:
 *   TF   = freq_in_content / total_content_words
 *   IDF  = stored in keyword map (computed at index time, Laplace-smoothed)
 *   base = TF × IDF
 *   ×    n-gram length bonus    (quad ×2.0 / tri ×1.6 / bi ×1.3 / single ×1.0)
 *   ×    position bonus         (up to +40% for first occurrence in top 25% of text)
 *   ×    taxonomy overlap bonus (×1.0–×1.6 based on shared categories/tags)
 *   —    single-word gate       (singles must appear ≥2 times or are skipped)
 *
 * Deduplication: any keyword whose every token is already covered by a
 * longer accepted phrase is suppressed.
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

		// ── 1. Collect already-linked post IDs and anchor texts ───────────────

		$normalize_url = static function ( string $url ): string {
			$url = strtolower( trim( $url ) );
			$url = preg_replace( '#^https?://#', '', $url );
			$url = preg_replace( '#^www\.#', '', $url );
			return rtrim( $url, '/' );
		};

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

		$already_linked_texts = array();
		if ( preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $anchor_matches ) ) {
			foreach ( $anchor_matches[1] as $anchor_html ) {
				$text = strtolower( trim( wp_strip_all_tags( $anchor_html ) ) );
				if ( '' !== $text ) {
					$already_linked_texts[ $text ] = true;
				}
			}
		}

		// ── 2. Prepare plain text for topic extraction ─────────────────────────

		$stripped   = preg_replace( '/<a\b[^>]*>.*?<\/a>/is', ' LINKED_PLACEHOLDER ', $content );
		$stripped   = preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $stripped );
		$plain_text = wp_strip_all_tags( $stripped );

		// Fetch the current post title to include in topic extraction.
		// The title is the strongest signal of what the article is about.
		$current_post_id    = get_the_ID();
		$current_post_title = $current_post_id ? get_the_title( $current_post_id ) : '';

		// Extend plain_text with the post title so verbatim keyword matching can
		// find phrases that only appear in the title (e.g. "control anger").
		// We append it rather than prepend so position bonus still favours body phrases.
		$searchable_text = $plain_text . ' ' . $current_post_title;

		// ── 3. Extract content topics with frequencies ─────────────────────────

		// Extract from body first.
		$content_topics = self::extract_content_topics( $plain_text );

		// Extract from title and boost each term by 3× (title = primary topic signal).
		// This ensures "Control Anger" from the title always scores above incidental
		// body matches, even when the body is short.
		if ( '' !== $current_post_title ) {
			$title_topics = self::extract_content_topics( $current_post_title );
			foreach ( $title_topics as $topic => $freq ) {
				$content_topics[ $topic ] = ( $content_topics[ $topic ] ?? 0 ) + ( $freq * 3 );
			}
		}

		$topic_lookup = array();
		foreach ( $content_topics as $topic => $freq ) {
			$topic_lookup[ strtolower( $topic ) ] = $freq;
		}

		$total_words = max( 1, str_word_count( $plain_text ) );

		// ── 4. Load current post's taxonomy terms for overlap scoring ──────────

		$current_tax_terms = array();

		if ( $current_post_id ) {
			$raw_current = wp_get_post_terms(
				$current_post_id,
				array( 'category', 'post_tag' ),
				array( 'fields' => 'names' )
			);
			if ( ! is_wp_error( $raw_current ) ) {
				$current_tax_terms = array_map( 'strtolower', $raw_current );
			}
		}

		// ── 5. Build bigram lookup from searchable text ───────────────────────
		//
		// Extract only bigrams from the body+title. We use these so that when a
		// post is indexed as "boundaries" (single), we can suggest "emotional boundaries"
		// (bigram) if it appears in the body — but ONLY bigrams, never longer fragments.

		$min_len      = Linkiya_Keyword_Extractor::get_min_word_len();
		$stop_words   = Linkiya_Keyword_Extractor::get_stop_words();
		$body_bigrams = Linkiya_Keyword_Extractor::tokens_to_ngrams(
			Linkiya_Keyword_Extractor::tokenize( $searchable_text ),
			$min_len,
			$stop_words,
			2, // Max bigrams only.
			2  // Min bigrams only.
		);

		// Index bigrams by each content token they contain.
		// Since body_bigrams are generated using get_stop_words() (which now includes
		// built-in weak tokens), all bigrams here are already clean anchor candidates.
		$token_to_bigrams = array();
		foreach ( $body_bigrams as $bigram ) {
			foreach ( explode( ' ', $bigram ) as $t ) {
				if ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) ) {
					$token_to_bigrams[ $t ][] = $bigram;
				}
			}
		}

		// ── 6. Score each post in the keyword map ─────────────────────────────

		$scored         = array();
		$already_scored = array();

		foreach ( $keyword_map as $entry ) {
			if ( empty( $entry['post_id'] ) || empty( $entry['keywords'] ) || ! is_array( $entry['keywords'] ) ) {
				continue;
			}

			$entry_id = (int) $entry['post_id'];

			if ( isset( $meta_applied_ids[ $entry_id ] ) || isset( $already_linked_ids[ $entry_id ] ) ) {
				continue;
			}

			$idf_weights  = is_array( $entry['idf_weights'] ?? null ) ? $entry['idf_weights'] : array();
			$best_keyword = null;
			$best_score   = 0.0;

			foreach ( $entry['keywords'] as $keyword ) {
				$kw_lower = strtolower( $keyword );
				$n_words  = substr_count( $keyword, ' ' ) + 1;

				// For single-word indexed keywords, also check if a body bigram
				// containing that word exists — prefer the bigram as anchor text.
				// e.g. post indexed "boundaries" + body has "emotional boundaries" → suggest bigram.
				$candidates = array(); // anchor_lower => is_bigram_upgrade.

				if ( 1 === $n_words && ! empty( $token_to_bigrams[ $kw_lower ] ) ) {
					foreach ( $token_to_bigrams[ $kw_lower ] as $bigram ) {
						$bl = strtolower( $bigram );
						if ( ! isset( $already_linked_texts[ $bl ] )
							&& false !== self::keyword_exists_in_text( $bigram, $searchable_text ) ) {
							$candidates[ $bl ] = true; // Bigram upgrade.
						}
					}
				}

				// Always consider the indexed keyword itself (exact/flex match).
				// keyword_exists_in_text returns the actual matched text (may include stop words).
				$matched = self::keyword_exists_in_text( $keyword, $searchable_text );
				if ( false !== $matched && ! isset( $already_linked_texts[ $matched ] ) ) {
					// Use matched text as anchor (e.g. "anger in relationships" not "anger relationships").
					$candidates[ $matched ] = false;
				}

				foreach ( $candidates as $anchor_lower => $is_upgrade ) {
					$a_words = substr_count( $anchor_lower, ' ' ) + 1;
					$freq    = $topic_lookup[ $anchor_lower ] ?? ( $topic_lookup[ $kw_lower ] ?? 1 );

					if ( 1 === $a_words && $freq < 1 ) {
						continue;
					}

					$tf    = $freq / $total_words;
					$idf   = $idf_weights[ $keyword ] ?? ( log( 2.0 ) + 1.0 );
					$score = $tf * $idf;

					if ( $is_upgrade ) {
						// Count how many tokens of the bigram appear in this post's title.
						// "emotional boundaries" post title contains BOTH tokens → strong match.
						// "emotional strength" post title contains only "emotional" → weaker.
						$post_title_lower = strtolower( $entry['title'] );
						$anchor_tokens    = explode( ' ', $anchor_lower );
						$title_token_hits = 0;
						foreach ( $anchor_tokens as $at ) {
							if ( preg_match( '/\b' . preg_quote( $at, '/' ) . '\b/i', $post_title_lower ) ) {
								++$title_token_hits;
							}
						}
						// Only accept bigram upgrade if ALL tokens appear in the post title.
						// This prevents "emotional strength" post from stealing "emotional boundaries".
						if ( $title_token_hits < count( $anchor_tokens ) ) {
							continue;
						}
						$score *= 1.5;
					}

					// N-gram length bonus.
					if ( $a_words >= 4 ) {
						$score *= 8.0;
					} elseif ( 3 === $a_words ) {
						$score *= 5.0;
					} elseif ( 2 === $a_words ) {
						$score *= 3.0;
					}

					// Position bonus: first occurrence in top 25% of text scores up to +40%.
					$first_pos = mb_stripos( $searchable_text, $anchor_lower );
					if ( false !== $first_pos ) {
						$pos_ratio = $first_pos / max( 1, mb_strlen( $searchable_text ) );
						$score    *= 1.0 + max( 0.0, ( 0.25 - $pos_ratio ) * 1.6 );
					}

					if ( $score > $best_score ) {
						$best_score = $score;
						// anchor_lower is either the bigram upgrade text or the flex-matched text.
						$best_keyword = $anchor_lower;
					}
				}
			}

			if ( null !== $best_keyword ) {
				// Taxonomy overlap bonus — each shared category/tag adds 20%, capped at ×1.6.
				if ( ! empty( $current_tax_terms ) ) {
					$candidate_terms = is_array( $entry['taxonomy_terms'] ?? null )
						? $entry['taxonomy_terms']
						: array();
					$overlap_count   = count( array_intersect( $current_tax_terms, $candidate_terms ) );
					$best_score     *= 1.0 + min( $overlap_count * 0.2, 0.6 );
				}

				$scored[]                    = array(
					'score'      => $best_score,
					'keyword'    => $best_keyword,
					'post_id'    => $entry['post_id'],
					'post_title' => $entry['title'],
					'post_type'  => $entry['post_type'] ?? 'post',
					'url'        => $entry['url'],
					'nofollow'   => ! empty( $entry['nofollow'] ),
					'new_tab'    => ! empty( $entry['new_tab'] ),
				);
				$already_scored[ $entry_id ] = true;
			}
		}

		// ── 7. Partial-phrase fallback ─────────────────────────────────────────
		//
		// For posts that scored zero above (no keyword appeared verbatim), check
		// whether their two rarest keywords BOTH appear individually in the content.
		// If yes, and the anchor keyword also appears verbatim, suggest it at 60%
		// confidence. Catches related posts that share concepts but not exact phrases.

		foreach ( $keyword_map as $entry ) {
			if ( empty( $entry['post_id'] ) || empty( $entry['keywords'] ) ) {
				continue;
			}

			$entry_id = (int) $entry['post_id'];

			if ( isset( $already_scored[ $entry_id ] )
				|| isset( $meta_applied_ids[ $entry_id ] )
				|| isset( $already_linked_ids[ $entry_id ] ) ) {
				continue;
			}

			$idf_weights = is_array( $entry['idf_weights'] ?? null ) ? $entry['idf_weights'] : array();

			// Sort keywords by IDF descending (rarest first).
			$ranked = $entry['keywords'];
			usort(
				$ranked,
				static function ( $a, $b ) use ( $idf_weights ) {
					$ia = $idf_weights[ $a ] ?? 1.0;
					$ib = $idf_weights[ $b ] ?? 1.0;
					return $ib <=> $ia;
				}
			);

			$top2 = array_slice( $ranked, 0, 2 );
			if ( count( $top2 ) < 2 ) {
				continue;
			}

			$match0 = self::keyword_exists_in_text( $top2[0], $searchable_text );
			$match1 = self::keyword_exists_in_text( $top2[1], $searchable_text );

			if ( false === $match0 || false === $match1 ) {
				continue;
			}

			$anchor_kw = $match0; // use actual matched text as anchor.

			$n_words = substr_count( $anchor_kw, ' ' ) + 1;
			$freq    = $topic_lookup[ strtolower( $anchor_kw ) ] ?? 0;

			// Single-word partial matches require at least 1 occurrence.
			if ( 1 === $n_words && $freq < 1 ) {
				continue;
			}

			$base_idf = $idf_weights[ $anchor_kw ] ?? ( log( 2.0 ) + 1.0 );
			$scored[] = array(
				'score'      => 0.6 * $base_idf,
				'keyword'    => $anchor_kw,
				'post_id'    => $entry['post_id'],
				'post_title' => $entry['title'],
				'post_type'  => $entry['post_type'] ?? 'post',
				'url'        => $entry['url'],
				'nofollow'   => ! empty( $entry['nofollow'] ),
				'new_tab'    => ! empty( $entry['new_tab'] ),
			);
		}

		// ── 8. Sort by score descending ────────────────────────────────────────

		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// ── 9. Deduplicate and suppress covered keywords ───────────────────────
		//
		// If every token of keyword A is already present as a whole word inside
		// an accepted longer keyword B, suppress A — it would link the same text
		// that B already covers.

		// Normalise scores to a 0–100 confidence value using the top score as ceiling.
		$max_score = ! empty( $scored ) ? max( array_column( $scored, 'score' ) ) : 1.0;
		$max_score = max( $max_score, 0.0001 );

		$matched_keywords = array();
		$accepted_phrases = array();
		$suggestions      = array();

		foreach ( $scored as $item ) {
			$kw = $item['keyword'];

			if ( isset( $matched_keywords[ $kw ] ) ) {
				continue;
			}

			$kw_tokens  = explode( ' ', $kw );
			$is_covered = false;

			foreach ( $accepted_phrases as $accepted ) {
				$all_in = true;
				foreach ( $kw_tokens as $token ) {
					if ( ! preg_match( '/\b' . preg_quote( $token, '/' ) . '\b/i', $accepted ) ) {
						$all_in = false;
						break;
					}
				}
				if ( $all_in ) {
					$is_covered = true;
					break;
				}
			}

			if ( $is_covered ) {
				continue;
			}

			$matched_keywords[ $kw ] = true;
			$accepted_phrases[]      = $kw;
			$item['confidence']      = (int) round( ( $item['score'] / $max_score ) * 100 );
			unset( $item['score'] );
			$suggestions[] = $item;
		}

		return $suggestions;
	}

	/**
	 * Extract significant topics from the current post's plain text content.
	 *
	 * Returns topic => frequency map for singles, bigrams, trigrams, and quadgrams.
	 * Uses the same tokenization and content-token rules as the keyword extractor
	 * so that keyword map terms and content topics align perfectly.
	 *
	 * @param  string $text Plain text content of the current post.
	 * @return array<string, int> topic => frequency map.
	 */
	private static function extract_content_topics( string $text ): array {
		$min_len    = Linkiya_Keyword_Extractor::get_min_word_len();
		$stop_words = Linkiya_Keyword_Extractor::get_stop_words();
		$tokens     = Linkiya_Keyword_Extractor::tokenize( $text );

		$token_count = count( $tokens );
		$topics      = array();

		$is_content = static function ( string $t ) use ( $min_len, $stop_words ): bool {
			return ctype_digit( $t ) || ( strlen( $t ) >= $min_len && ! isset( $stop_words[ $t ] ) );
		};

		for ( $n = 1; $n <= 4; $n++ ) {
			for ( $i = 0; $i <= $token_count - $n; $i++ ) {
				$window = array_slice( $tokens, $i, $n );

				if ( 1 === $n ) {
					if ( $is_content( $window[0] ) ) {
						$topics[ $window[0] ] = ( $topics[ $window[0] ] ?? 0 ) + 1;
					}
					continue;
				}

				if ( ! $is_content( $window[0] ) || ! $is_content( $window[ $n - 1 ] ) ) {
					continue;
				}

				if ( $n >= 3 ) {
					$content_count = count( array_filter( $window, $is_content ) );
					if ( $content_count < (int) ceil( $n / 2 ) ) {
						continue;
					}
				}

				$phrase            = implode( ' ', $window );
				$topics[ $phrase ] = ( $topics[ $phrase ] ?? 0 ) + 1;
			}
		}

		return $topics;
	}

	/**
	 * Check if $keyword appears as a whole word (case-insensitive) in $text.
	 *
	 * Allows up to one short stop word (≤4 chars) between each token so that
	 * an indexed keyword like "anger relationships" (stop word "in" removed
	 * at index time) still matches "Anger in Relationships" in the text.
	 *
	 * Returns the actual matched text from $text (preserving stop words), or
	 * false if no match. Use the return value as the anchor text so the link
	 * reads naturally (e.g. "anger in relationships" not "anger relationships").
	 *
	 * @param  string $keyword Word or phrase to search for.
	 * @param  string $text    Plain text to search within.
	 * @return string|false Matched substring (lowercased) or false.
	 */
	private static function keyword_exists_in_text( string $keyword, string $text ): string|false {
		$tokens = explode( ' ', $keyword );
		if ( count( $tokens ) < 2 ) {
			$escaped = preg_quote( $keyword, '/' );
			return preg_match( '/\b' . $escaped . '\b/iu', $text ) ? $keyword : false;
		}
		// Between each pair of tokens, allow an optional single stop word (1–4 chars).
		$parts   = array_map( fn( $t ) => preg_quote( $t, '/' ), $tokens );
		$pattern = '/\b(' . implode( '(?:\s+\w{1,4})?\s+', $parts ) . ')\b/iu';
		if ( preg_match( $pattern, $text, $m ) ) {
			return strtolower( $m[1] );
		}
		return false;
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
