<?php
defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_Keyword_Extractor
 *
 * Fetches all published posts (excluding the current one) and extracts
 * meaningful single-word and two-word (bigram) keywords from their titles.
 */
class Linkiya_Keyword_Extractor {

    /**
     * English + common Hindi-romanized stop words to ignore.
     */
    private static $stop_words = [
        'a','an','the','and','or','but','in','on','at','to','for','of','with',
        'by','from','up','about','into','through','during','is','are','was',
        'were','be','been','being','have','has','had','do','does','did','will',
        'would','could','should','may','might','shall','can','need','dare',
        'how','why','when','where','what','which','who','whom','this','that',
        'these','those','it','its','you','your','he','she','we','they','them',
        'their','my','our','i','me','him','her','us','not','no','nor','so',
        'yet','both','either','neither','each','few','more','most','other',
        'some','such','than','too','very','just','as','if','then','because',
        'while','although','though','after','before','since','until','unless',
        'get','got','make','made','take','know','go','come','say','see','use',
        'find','give','tell','work','call','try','ask','need','feel','become',
        'leave','put','mean','keep','let','begin','show','hear','play','run',
        'move','live','believe','hold','bring','happen','write','provide','sit',
        'stand','lose','pay','meet','include','continue','set','learn','change',
        'lead','understand','watch','follow','stop','create','speak','read',
        'spend','grow','open','walk','win','offer','remember','love','consider',
        // Hindi romanized
        'kya','kaise','kyun','aur','hai','hain','ka','ki','ke','se','mein',
        'par','ko','ne','ek','yeh','woh','apna','apni','apne','bhi','hi',
    ];

    /**
     * Get minimum word length from settings (F9 — free setting).
     */
    private static function get_min_word_len(): int {
        $settings = Linkiya_Settings::get();
        return max( 2, (int) ( $settings['min_word_length'] ?? 4 ) );
    }

    /**
     * Returns all public post types registered on the site —
     * built-in (post, page) + any custom post types that are
     * publicly queryable and have a UI.
     *
     * CPTs like attachment, revision, nav_menu_item, etc. are excluded
     * via the built-in filters on get_post_types().
     *
     * @return string[]
     */
    public static function get_all_public_post_types(): array {
        $types = get_post_types( [
            'public'  => true,
            'show_ui' => true,
        ], 'names' );

        // Remove types that are never useful for internal linking
        $exclude = [ 'attachment' ];
        foreach ( $exclude as $slug ) {
            unset( $types[ $slug ] );
        }

        return array_values( $types );
    }

    /**
     * Get all published posts across all public post types (excluding
     * the post currently being edited) and return a keyword map.
     *
     * @param int   $exclude_id  Post ID to exclude (the one being edited).
     * @param array $post_types  Pass an explicit list, or leave empty to
     *                           auto-detect all public post types.
     * @return array  [
     *   [
     *     'post_id'   => int,
     *     'title'     => string,
     *     'url'       => string,
     *     'post_type' => string,
     *     'keywords'  => string[],   // sorted longest-first
     *   ],
     *   ...
     * ]
     */
    public static function get_keyword_map( int $exclude_id, array $post_types = [] ): array {
        if ( empty( $post_types ) ) {
            $post_types = self::get_all_public_post_types();
        }

        $posts = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'exclude'        => [ $exclude_id ],
            'fields'         => 'all',
        ] );

        $map = [];

        foreach ( $posts as $post ) {
            $keywords = self::extract_keywords( $post->post_title );

            // Allow Pro plugin to add custom keywords via filter
            $keywords = apply_filters( 'linkiya_post_keywords', $keywords, $post->ID );

            if ( empty( $keywords ) ) {
                continue;
            }

            $map[] = [
                'post_id'   => $post->ID,
                'title'     => $post->post_title,
                'url'       => get_permalink( $post->ID ),
                'post_type' => $post->post_type,
                'keywords'  => $keywords,
            ];
        }

        return $map;
    }

    /**
     * Extract keywords (singles + bigrams) from a post title,
     * filtered by stop words and minimum length.
     * Returned sorted longest-first so bigrams are tried before singles.
     *
     * @param  string $title
     * @return string[]
     */
    public static function extract_keywords( string $title ): array {
        $min_len = self::get_min_word_len();

        $clean  = strtolower( preg_replace( '/[^\w\s\']/u', ' ', $title ) );
        $tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );

        $valid = array_values( array_filter( $tokens, function ( $t ) use ( $min_len ) {
            return strlen( $t ) >= $min_len
                && ! in_array( $t, self::$stop_words, true );
        } ) );

        $keywords = $valid;

        for ( $i = 0; $i < count( $tokens ) - 1; $i++ ) {
            $a = $tokens[ $i ];
            $b = $tokens[ $i + 1 ];
            if (
                strlen( $a ) >= $min_len && ! in_array( $a, self::$stop_words, true ) &&
                strlen( $b ) >= $min_len && ! in_array( $b, self::$stop_words, true )
            ) {
                $keywords[] = $a . ' ' . $b;
            }
        }

        $keywords = array_unique( $keywords );
        usort( $keywords, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

        return array_values( $keywords );
    }
}
