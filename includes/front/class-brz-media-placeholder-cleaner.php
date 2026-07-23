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
        if ( ! is_admin() ) {
            add_filter( 'the_content', array( __CLASS__, 'clean_placeholders' ), 5 );
            add_filter( 'woocommerce_short_description', array( __CLASS__, 'clean_placeholders' ), 5 );
            add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'clean_placeholders' ), 5 );
        }
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
        $content = preg_replace( '/<(p|div|span)[^>]*class=["\']?[^"\']*brz-media-placeholder[^"\']*["\']?[^>]*>.*?<\/\1>/is', '', $content );

        // 2. Remove legacy [[IMAGE: ...]] tags and any wrapping <p>
        $content = preg_replace( '/<p>\s*\[\[IMAGE:\s*.*?\]\]\s*<\/p>/is', '', $content );
        $content = preg_replace( '/\[\[IMAGE:\s*.*?\]\]/is', '', $content );

        // 3. Remove HTML comments <!-- IMAGE: ... -->
        $content = preg_replace( '/<!--\s*IMAGE:\s*.*?-->/is', '', $content );

        return $content;
    }
}
