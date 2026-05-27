<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_WC_Shortcodes {
    private static $enabled_cache = null;
    private static $processed     = array();
    private static $primed        = false;

    public static function init() {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( self::blocked_request() ) {
            return;
        }

        add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'process_product_content' ), 20, 2 );
        add_filter( 'woocommerce_product_get_short_description', array( __CLASS__, 'process_product_content' ), 20, 2 );
        add_filter( 'the_content', array( __CLASS__, 'process_the_content' ), 11 );
        add_filter( 'woocommerce_short_description', array( __CLASS__, 'process_woocommerce_short_description' ), 11 );
        add_filter( 'the_excerpt', array( __CLASS__, 'process_the_excerpt' ), 11 );
        add_action( 'wp', array( __CLASS__, 'prime_global_post_content' ) );
    }

    private static function is_enabled() {
        if ( null !== self::$enabled_cache ) {
            return self::$enabled_cache;
        }

        // Default to true (1) to ensure shortcodes work out of the box
        self::$enabled_cache = (bool) get_option( 'myplugin_enable_wc_product_shortcodes', 1 );
        return self::$enabled_cache;
    }

    private static function blocked_request() {
        if ( is_admin() ) { return true; }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return true; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return true; }
        return false;
    }

    private static function should_run( $post_id, $post_type = '' ) {
        if ( ! self::is_enabled() ) {
            return false;
        }

        if ( self::blocked_request() ) {
            return false;
        }

        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return false;
        }

        if ( empty( $post_type ) ) {
            $post_type = get_post_type( $post_id );
        }

        // Normalize WooCommerce product types (simple, variable, etc.) to product post type.
        if ( $post_type && $post_type !== 'product' && class_exists( 'WC_Product' ) ) {
            $wc_product = wc_get_product( $post_id );
            if ( $wc_product && is_a( $wc_product, 'WC_Product' ) ) {
                $post_type = 'product';
            }
        }

        if ( 'product' !== $post_type ) {
            return false;
        }

        if ( function_exists( 'is_product' ) && is_product() ) {
            return true;
        }

        return is_singular( 'product' );
    }

    public static function process_product_content( $content, $product = null ) {
        $post_id   = 0;
        $post_type = '';

        if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
            $post_id   = (int) $product->get_id();
            $post_type = get_post_type( $post_id ) ?: 'product';
        } else {
            $post     = get_post();
            $post_id   = $post ? $post->ID : 0;
            $post_type = $post ? $post->post_type : '';
        }

        if ( ! self::should_run( $post_id, $post_type ) ) {
            return $content;
        }

        $context = current_filter() === 'woocommerce_product_get_short_description' ? 'short' : 'desc';
        return self::process_value( $content, $post_id, $context );
    }

    public static function process_the_content( $content ) {
        $post = get_post();
        $post_id   = $post ? $post->ID : 0;
        $post_type = $post ? $post->post_type : '';

        if ( ! self::should_run( $post_id, $post_type ) ) {
            return $content;
        }

        return self::process_value( $content, $post_id, 'content' );
    }

    public static function process_the_excerpt( $excerpt ) {
        $post = get_post();
        $post_id   = $post ? $post->ID : 0;
        $post_type = $post ? $post->post_type : '';

        if ( ! self::should_run( $post_id, $post_type ) ) {
            return $excerpt;
        }

        return self::process_value( $excerpt, $post_id, 'excerpt' );
    }

    public static function process_woocommerce_short_description( $excerpt ) {
        $post = get_post();
        $post_id   = $post ? $post->ID : 0;
        $post_type = $post ? $post->post_type : '';

        if ( ! self::should_run( $post_id, $post_type ) ) {
            return $excerpt;
        }

        return self::process_value( $excerpt, $post_id, 'wc_short_desc' );
    }

    public static function prime_global_post_content() {
        if ( self::$primed ) {
            return;
        }

        $post = get_post();
        if ( ! $post ) {
            return;
        }

        $post_id   = $post->ID;
        $post_type = $post->post_type;

        if ( ! self::should_run( $post_id, $post_type ) ) {
            return;
        }

        $post->post_content = self::process_value( $post->post_content, $post_id, 'content' );
        $post->post_excerpt = self::process_value( $post->post_excerpt, $post_id, 'excerpt' );

        self::$primed = true;
    }

    private static function process_value( $content, $post_id, $context ) {
        if ( isset( self::$processed[ $post_id ][ $context ] ) ) {
            return self::$processed[ $post_id ][ $context ];
        }

        $processed = do_shortcode( $content );
        self::$processed[ $post_id ][ $context ] = is_string( $processed ) ? $processed : $content;

        return self::$processed[ $post_id ][ $context ];
    }
}
