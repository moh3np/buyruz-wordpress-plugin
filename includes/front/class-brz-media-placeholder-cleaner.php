<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * پاک‌سازی خودکار تگ‌ها و پلیس‌هولدرهای تصویر در فرانت‌اند برای مشتریان سایت.
 * این کلاس اجازه می‌دهد پلیس‌هولدرها در ادیتور دیداری/متن وردپرس برای اپراتور دیده شوند،
 * اما در فرانت‌اند برای خریداران سایت کلاً حذف و پاک‌سازی گردند.
 */
class BRZ_Media_Placeholder_Cleaner {

    public static function init() {
        if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            add_filter( 'the_content', array( __CLASS__, 'clean_placeholders' ), 1 );
            add_filter( 'the_content', array( __CLASS__, 'clean_placeholders' ), 999 );
            add_filter( 'woocommerce_short_description', array( __CLASS__, 'clean_placeholders' ), 1 );
            add_filter( 'woocommerce_short_description', array( __CLASS__, 'clean_placeholders' ), 999 );
            add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'clean_placeholders' ), 10 );
            add_filter( 'woocommerce_product_get_short_description', array( __CLASS__, 'clean_placeholders' ), 10 );
            add_filter( 'get_the_excerpt', array( __CLASS__, 'clean_placeholders' ), 10 );
            add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'clean_product_tabs' ), 999 );
        }
    }

    /**
     * Clean placeholders in WooCommerce product tabs output buffer.
     *
     * @param array $tabs
     * @return array
     */
    public static function clean_product_tabs( $tabs ) {
        if ( is_array( $tabs ) ) {
            foreach ( $tabs as $key => &$tab ) {
                if ( isset( $tab['callback'] ) && is_callable( $tab['callback'] ) ) {
                    $original_callback = $tab['callback'];
                    $tab['callback'] = function() use ( $original_callback ) {
                        ob_start();
                        call_user_func_array( $original_callback, func_get_args() );
                        $output = ob_get_clean();
                        echo self::clean_placeholders( $output );
                    };
                }
            }
        }
        return $tabs;
    }

    /**
     * Remove media placeholder blocks and strings from post content on frontend.
     *
     * @param string $content
     * @return string
     */
    public static function clean_placeholders( $content ) {
        if ( empty( $content ) || ! is_string( $content ) ) {
            return $content;
        }

        // 1. Remove elements with brz-media-placeholder class (e.g., <p class="brz-media-placeholder">...</p>)
        $content = preg_replace( '/<(p|div|span)[^>]*class=["\']?[^"\']*brz-media-placeholder[^"\']*["\']?[^>]*>.*?<\/\1>/isu', '', $content );

        // 2. Remove legacy [[IMAGE: ...]] tags and any wrapping <p> or whitespace
        $content = preg_replace( '/<p[^>]*>[\s\xc2\xa0]*\[\[IMAGE:[\s\S]*?\]\][\s\xc2\xa0]*<\/p>/isu', '', $content );
        $content = preg_replace( '/\[\[IMAGE:[\s\S]*?\]\]/isu', '', $content );

        // 3. Remove HTML comments <!-- IMAGE: ... -->
        $content = preg_replace( '/<!--[\s\S]*?IMAGE:[\s\S]*?-->/isu', '', $content );

        return $content;
    }
}
