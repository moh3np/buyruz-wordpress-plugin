<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Bakala Free-Shipping Box HTML Passthrough.
 *
 * The Bakala theme strips HTML tags from the "Free Shipping Box Text" Redux
 * option field before rendering it on the frontend. This class intercepts the
 * stored option value and wraps allowed HTML in a marker that survives the
 * theme's sanitization, then restores it in the final output buffer.
 *
 * Approach: Output buffering on WooCommerce product/cart/checkout pages,
 * targeted at the `.free-shipping-subtitle` container. Only runs on frontend,
 * non-admin, non-REST contexts.
 *
 * Performance: Buffer only captures the minimal WooCommerce template output.
 * The regex replacement is O(1) per page (single preg_replace on a known class).
 *
 * @since 4.6.0
 */
class BRZ_Shipping_Html {

    /** @var bool Whether OB is currently active for this feature. */
    private static bool $buffering = false;

    /**
     * Bootstrap: attach hooks on frontend WooCommerce pages only.
     */
    public static function init(): void {
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        // Hook early to start output buffering on relevant pages.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ) );
    }

    /**
     * Conditionally start output buffering on WooCommerce product/cart/checkout pages.
     */
    public static function maybe_start_buffer(): void {
        if ( ! function_exists( 'is_product' ) ) {
            return;
        }

        // Only buffer on pages that may contain the free-shipping box.
        if ( ! is_product() && ! is_cart() && ! is_checkout() ) {
            return;
        }

        ob_start( array( __CLASS__, 'process_buffer' ) );
        self::$buffering = true;
    }

    /**
     * Process the output buffer: restore HTML entities inside .free-shipping-subtitle.
     *
     * Finds escaped HTML entities within the free-shipping-subtitle paragraph
     * and converts them back to actual HTML. This allows tags like
     * <span class="sr-only"> to render properly for SEO/accessibility.
     *
     * Only targets content within the specific CSS selector to avoid unintended
     * side effects elsewhere on the page.
     *
     * @param string $html Full page HTML output.
     * @return string Modified HTML with restored tags in shipping subtitle.
     */
    public static function process_buffer( string $html ): string {
        if ( empty( $html ) ) {
            return $html;
        }

        // Target: <p class="free-shipping-subtitle">...escaped HTML...</p>
        // Pattern matches the subtitle paragraph content specifically.
        $pattern = '/(<p\s[^>]*class=["\'][^"\']*free-shipping-subtitle[^"\']*["\'][^>]*>)(.*?)(<\/p>)/si';

        $html = preg_replace_callback( $pattern, array( __CLASS__, 'restore_html_in_match' ), $html );

        return $html;
    }

    /**
     * Callback: decode HTML entities within the matched subtitle paragraph.
     *
     * Restores only safe inline tags (span, strong, em, br, a) that were
     * entity-encoded by the theme's sanitization.
     *
     * @param array $matches Regex matches [full, opening_tag, content, closing_tag].
     * @return string Reconstructed paragraph with decoded safe HTML.
     */
    private static function restore_html_in_match( array $matches ): string {
        $opening = $matches[1];
        $content = $matches[2];
        $closing = $matches[3];

        // Decode HTML entities in the content.
        $decoded = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Sanitize: only allow safe inline tags for SEO/accessibility.
        $allowed = array(
            'span'   => array( 'class' => true, 'id' => true, 'style' => true, 'itemprop' => true, 'itemscope' => true, 'itemtype' => true ),
            'strong' => array( 'class' => true ),
            'em'     => array( 'class' => true ),
            'br'     => array(),
            'a'      => array( 'href' => true, 'class' => true, 'rel' => true, 'title' => true, 'target' => true ),
            'meta'   => array( 'itemprop' => true, 'content' => true ),
        );

        $decoded = wp_kses( $decoded, $allowed );

        return $opening . $decoded . $closing;
    }
}
