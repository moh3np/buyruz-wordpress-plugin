<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Static Page Type Detector.
 *
 * Determines the page type profile for any given post or taxonomy term
 * based on a priority chain. Used by the Map Generator to classify pages
 * for the Processing Engine's asset whitelisting profiles.
 *
 * Priority chain for posts:
 *   1. product (WooCommerce active + post_type === 'product')
 *   2. elementor_page (Elementor active + _elementor_edit_mode === 'builder')
 *   3. blog_post (post_type === 'post')
 *   4. unknown (fallback with warning log)
 *
 * Priority chain for terms:
 *   1. archive (WooCommerce active + taxonomy in product_cat/product_brand/product_tag)
 *   2. blog_category (taxonomy === 'category')
 *   3. unknown (fallback with warning log)
 */
class BRZ_Static_Page_Detector {

    public const PROFILE_PRODUCT       = 'product';
    public const PROFILE_ARCHIVE       = 'archive';
    public const PROFILE_ELEMENTOR     = 'elementor_page';
    public const PROFILE_BLOG_POST     = 'blog_post';
    public const PROFILE_BLOG_CATEGORY = 'blog_category';
    public const PROFILE_UNKNOWN       = 'unknown';

    /**
     * Detect page type profile for a post ID.
     *
     * Evaluates profiles in priority order:
     * product → elementor_page → blog_post → unknown
     *
     * @param int $post_id The post ID to detect.
     * @return string One of the PROFILE_* constants.
     */
    public static function detect( int $post_id ): string {
        $post = get_post( $post_id );

        if ( ! $post || $post->post_status !== 'publish' ) {
            error_log( sprintf(
                '[BRZ Static Controller] Unknown page profile: post_id=%d, url=%s (post not found or not published)',
                $post_id,
                get_permalink( $post_id ) ?: 'N/A'
            ) );
            return self::PROFILE_UNKNOWN;
        }

        // Priority 1: WooCommerce Product
        if ( self::is_woocommerce_active() && $post->post_type === 'product' ) {
            return self::PROFILE_PRODUCT;
        }

        // Priority 2: Elementor Page
        if ( self::is_elementor_active() && self::is_elementor_page( $post_id ) ) {
            return self::PROFILE_ELEMENTOR;
        }

        // Priority 3: Blog Post
        if ( $post->post_type === 'post' ) {
            return self::PROFILE_BLOG_POST;
        }

        // Priority 4: Unknown — log warning
        error_log( sprintf(
            '[BRZ Static Controller] Unknown page profile: post_id=%d, url=%s',
            $post_id,
            get_permalink( $post_id ) ?: 'N/A'
        ) );

        return self::PROFILE_UNKNOWN;
    }

    /**
     * Detect page type profile for a taxonomy term.
     *
     * Evaluates profiles in priority order:
     * archive → blog_category → unknown
     *
     * @param int    $term_id  The term ID to detect.
     * @param string $taxonomy The taxonomy slug.
     * @return string One of the PROFILE_* constants.
     */
    public static function detect_term( int $term_id, string $taxonomy ): string {
        // Priority 1: WooCommerce taxonomy archives
        if ( self::is_woocommerce_active() &&
             in_array( $taxonomy, [ 'product_cat', 'product_brand', 'product_tag' ], true ) ) {
            return self::PROFILE_ARCHIVE;
        }

        // Priority 2: Blog category
        if ( $taxonomy === 'category' ) {
            return self::PROFILE_BLOG_CATEGORY;
        }

        // Priority 3: Unknown — log warning
        $term_link = get_term_link( $term_id, $taxonomy );
        error_log( sprintf(
            '[BRZ Static Controller] Unknown term profile: term_id=%d, taxonomy=%s, url=%s',
            $term_id,
            $taxonomy,
            ( ! is_wp_error( $term_link ) ) ? $term_link : 'N/A'
        ) );

        return self::PROFILE_UNKNOWN;
    }

    /**
     * Check if WooCommerce is active.
     *
     * @return bool True if WooCommerce class exists.
     */
    public static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Check if Elementor is active.
     *
     * @return bool True if ELEMENTOR_VERSION constant is defined.
     */
    public static function is_elementor_active(): bool {
        return defined( 'ELEMENTOR_VERSION' );
    }

    /**
     * Check if a post is built with Elementor.
     *
     * @param int $post_id The post ID to check.
     * @return bool True if the post has _elementor_edit_mode set to 'builder'.
     */
    private static function is_elementor_page( int $post_id ): bool {
        return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
    }
}
