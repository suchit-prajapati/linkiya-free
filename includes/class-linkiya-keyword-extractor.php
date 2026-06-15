<?php
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
     */
    private static $stop_words = [
        'a'=>1,'an'=>1,'the'=>1,'and'=>1,'or'=>1,'but'=>1,'in'=>1,'on'=>1,'at'=>1,
        'to'=>1,'for'=>1,'of'=>1,'with'=>1,'by'=>1,'from'=>1,'up'=>1,'about'=>1,
        'into'=>1,'through'=>1,'during'=>1,'is'=>1,'are'=>1,'was'=>1,'were'=>1,
        'be'=>1,'been'=>1,'being'=>1,'have'=>1,'has'=>1,'had'=>1,'do'=>1,'does'=>1,
        'did'=>1,'will'=>1,'would'=>1,'could'=>1,'should'=>1,'may'=>1,'might'=>1,
        'shall'=>1,'can'=>1,'need'=>1,'dare'=>1,'how'=>1,'why'=>1,'when'=>1,
        'where'=>1,'what'=>1,'which'=>1,'who'=>1,'whom'=>1,'this'=>1,'that'=>1,
        'these'=>1,'those'=>1,'it'=>1,'its'=>1,'you'=>1,'your'=>1,'he'=>1,'she'=>1,
        'we'=>1,'they'=>1,'them'=>1,'their'=>1,'my'=>1,'our'=>1,'i'=>1,'me'=>1,
        'him'=>1,'her'=>1,'us'=>1,'not'=>1,'no'=>1,'nor'=>1,'so'=>1,'yet'=>1,
        'both'=>1,'either'=>1,'neither'=>1,'each'=>1,'few'=>1,'more'=>1,'most'=>1,
        'other'=>1,'some'=>1,'such'=>1,'than'=>1,'too'=>1,'very'=>1,'just'=>1,
        'as'=>1,'if'=>1,'then'=>1,'because'=>1,'while'=>1,'although'=>1,'though'=>1,
        'after'=>1,'before'=>1,'since'=>1,'until'=>1,'unless'=>1,'get'=>1,'got'=>1,
        'make'=>1,'made'=>1,'take'=>1,'know'=>1,'go'=>1,'come'=>1,'say'=>1,'see'=>1,
        'use'=>1,'find'=>1,'give'=>1,'tell'=>1,'work'=>1,'call'=>1,'try'=>1,'ask'=>1,
        'feel'=>1,'become'=>1,'leave'=>1,'put'=>1,'mean'=>1,'keep'=>1,'let'=>1,
        'begin'=>1,'show'=>1,'hear'=>1,'play'=>1,'run'=>1,'move'=>1,'live'=>1,
        'believe'=>1,'hold'=>1,'bring'=>1,'happen'=>1,'write'=>1,'provide'=>1,
        'sit'=>1,'stand'=>1,'lose'=>1,'pay'=>1,'meet'=>1,'include'=>1,'continue'=>1,
        'set'=>1,'learn'=>1,'change'=>1,'lead'=>1,'understand'=>1,'watch'=>1,
        'follow'=>1,'stop'=>1,'create'=>1,'speak'=>1,'read'=>1,'spend'=>1,'grow'=>1,
        'open'=>1,'walk'=>1,'win'=>1,'offer'=>1,'remember'=>1,'love'=>1,'consider'=>1,
        // Hindi romanized
        'kya'=>1,'kaise'=>1,'kyun'=>1,'aur'=>1,'hai'=>1,'hain'=>1,'ka'=>1,'ki'=>1,
        'ke'=>1,'se'=>1,'mein'=>1,'par'=>1,'ko'=>1,'ne'=>1,'ek'=>1,'yeh'=>1,
        'woh'=>1,'apna'=>1,'apni'=>1,'apne'=>1,'bhi'=>1,'hi'=>1,
    ];

    /**
     * Register cache-invalidation hooks. Called once from linkiya.php.
     */
    public static function init(): void {
        add_action( 'save_post',   [ __CLASS__, 'invalidate_cache' ] );
        add_action( 'delete_post', [ __CLASS__, 'invalidate_cache' ] );
        add_action( 'trashed_post', [ __CLASS__, 'invalidate_cache' ] );
    }

    /**
     * Delete the cached keyword map so the next scan rebuilds it.
     */
    public static function invalidate_cache(): void {
        global $wpdb;
        // Delete all variants of the keyword map transient regardless of post-type hash suffix.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                    OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . self::CACHE_KEY ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . self::CACHE_KEY ) . '%'
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
        $types = get_post_types( [
            'public'  => true,
            'show_ui' => true,
        ], 'names' );

        $exclude = [ 'attachment' ];
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
    public static function get_keyword_map( int $exclude_id, array $post_types = [] ): array {
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
     * @param array $post_types
     * @return array
     */
    private static function build_keyword_map( array $post_types ): array {
        // Fetch only the fields we need — avoids loading post_content into memory.
        $posts = get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'all',
            'no_found_rows'  => true,   // skip SQL_CALC_FOUND_ROWS, faster
            'update_post_meta_cache' => false, // skip meta cache, not needed
            'update_post_term_cache' => false, // skip term cache, not needed
        ] );

        if ( empty( $posts ) ) {
            return [];
        }

        // Warm the permalink cache for all posts in a single pass
        // so get_permalink() below hits the cache instead of the DB.
        update_post_caches( $posts, 'posts', false, false );

        $map = [];

        foreach ( $posts as $post ) {
            $keywords = self::extract_keywords( $post->post_title );

            // Allow Pro plugin to add custom keywords via filter.
            $keywords = apply_filters( 'linkiya_post_keywords', $keywords, $post->ID );

            if ( empty( $keywords ) ) {
                continue;
            }

            $map[] = [
                'post_id'   => $post->ID,
                'title'     => $post->post_title,
                'url'       => get_permalink( $post ),  // pass object — uses cache
                'post_type' => $post->post_type,
                'keywords'  => $keywords,
            ];
        }

        return $map;
    }

    /**
     * Extract keywords (singles + bigrams) from a post title.
     * Returned sorted longest-first so bigrams are tried before singles.
     *
     * @param  string $title
     * @return string[]
     */
    public static function extract_keywords( string $title ): array {
        $min_len = self::get_min_word_len();

        $clean  = strtolower( preg_replace( '/[^\w\s\']/u', ' ', $title ) );
        $tokens = preg_split( '/\s+/', trim( $clean ), -1, PREG_SPLIT_NO_EMPTY );

        // O(1) stop word lookup via isset() on associative map.
        $valid = array_values( array_filter( $tokens, function ( $t ) use ( $min_len ) {
            return strlen( $t ) >= $min_len && ! isset( self::$stop_words[ $t ] );
        } ) );

        $keywords    = $valid;
        $token_count = count( $tokens );

        for ( $i = 0; $i < $token_count - 1; $i++ ) {
            $a = $tokens[ $i ];
            $b = $tokens[ $i + 1 ];
            if (
                strlen( $a ) >= $min_len && ! isset( self::$stop_words[ $a ] ) &&
                strlen( $b ) >= $min_len && ! isset( self::$stop_words[ $b ] )
            ) {
                $keywords[] = $a . ' ' . $b;
            }
        }

        $keywords = array_unique( $keywords );
        usort( $keywords, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

        return array_values( $keywords );
    }
}
