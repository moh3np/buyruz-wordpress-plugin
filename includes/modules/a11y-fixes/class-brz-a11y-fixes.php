<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Accessibility Fixes module.
 *
 * Applies HTML/ARIA corrections to product pages via output buffering.
 * Uses DOMDocument to safely transform the HTML without regex.
 * All transforms are applied only on desktop product pages and are
 * fully reversible by disabling the module.
 */
class BRZ_A11y_Fixes {

    /** @var bool Whether output buffering was started by this module. */
    private static bool $buffering = false;

    /** @var array<string, string> Map of social network keys to Persian names. */
    private static array $social_map = [
        'instagram'  => 'اینستاگرام',
        'telegram'   => 'تلگرام',
        'twitter'    => 'توییتر',
        'facebook'   => 'فیسبوک',
        'youtube'    => 'یوتیوب',
        'linkedin'   => 'لینکدین',
        'whatsapp'   => 'واتساپ',
        'aparat'     => 'آپارات',
        'pinterest'  => 'پینترست',
    ];

    /**
     * Bootstrap the module.
     *
     * Checks for DOM extension availability, then registers the
     * template_redirect hook to conditionally start output buffering.
     */
    public static function init(): void {
        // DISABLED: Module causing layout issues on product page.
        // TODO: Fix DOMDocument output breaking page layout before re-enabling.
        return;

        // Guard: DOM extension is required for HTML transforms.
        if ( ! extension_loaded( 'dom' ) ) {
            return;
        }

        add_action( 'template_redirect', array( __CLASS__, 'maybe_start_buffer' ), 1 );
    }

    /**
     * Conditionally start output buffering on product pages (desktop only).
     *
     * Hooked to `template_redirect` at priority 1.
     */
    public static function maybe_start_buffer(): void {
        if ( ! is_product() || wp_is_mobile() ) {
            return;
        }

        ob_start();
        self::$buffering = true;

        add_action( 'shutdown', array( __CLASS__, 'flush_buffer' ), 0 );
    }

    /**
     * Flush the output buffer, applying transforms.
     *
     * Hooked to `shutdown` at priority 0.
     */
    public static function flush_buffer(): void {
        if ( ! self::$buffering ) {
            return;
        }

        $buffer = ob_get_clean();
        if ( false === $buffer ) {
            return;
        }

        echo self::output_callback( $buffer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Output callback with fail-safe error handling.
     *
     * Wraps transform() in try/catch. On any failure, the original
     * HTML is returned unchanged — the page never breaks.
     *
     * @param string $buffer Raw HTML output buffer.
     * @return string Transformed or original HTML.
     */
    public static function output_callback( string $buffer ): string {
        if ( empty( $buffer ) ) {
            return $buffer;
        }

        try {
            return self::transform( $buffer );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[BRZ_A11y_Fixes] Transform failed: ' . $e->getMessage() );
            }
            return $buffer;
        }
    }

    /**
     * Apply all accessibility transforms to the HTML string.
     *
     * Creates a DOMDocument from the input HTML, runs all fix methods
     * in sequence, and returns the transformed markup. If saveHTML()
     * fails, the original input is returned unchanged (fail-safe).
     *
     * @param string $html Raw HTML string.
     * @return string Transformed HTML string.
     */
    public static function transform( string $html ): string {
        // Suppress libxml warnings for malformed HTML (common in real pages).
        $internal_errors = libxml_use_internal_errors( true );

        $dom = new \DOMDocument();
        $dom->encoding = 'UTF-8';

        // Prepend a meta charset tag so DOMDocument correctly interprets UTF-8 (Persian text).
        // This avoids the deprecated mb_convert_encoding and the unreliable <?xml PI approach.
        $has_charset = ( false !== stripos( $html, '<meta charset' ) || false !== stripos( $html, 'content="text/html' ) );
        if ( ! $has_charset ) {
            // Insert charset meta before any content if not already present.
            $html_to_load = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $html;
        } else {
            $html_to_load = $html;
        }

        $dom->loadHTML( $html_to_load, LIBXML_HTML_NODEFDTD );

        // Apply all transforms in order.
        self::fix_progressbar_aria( $dom );
        self::fix_heading_levels( $dom );
        self::fix_viewed_images_alt( $dom );
        self::fix_social_links_aria( $dom );
        self::fix_icon_links_aria( $dom );
        self::fix_spec_list_nesting( $dom );
        self::fix_cart_sidebar_list( $dom );
        self::fix_duplicate_ids( $dom );

        // Save the full document HTML back.
        $output = $dom->saveHTML();

        // Restore error handling.
        libxml_clear_errors();
        libxml_use_internal_errors( $internal_errors );

        // If saveHTML failed, return original (fail-safe).
        if ( false === $output || empty( $output ) ) {
            return $html;
        }

        // DOMDocument::saveHTML() encodes non-ASCII characters as HTML entities.
        // Decode them back to UTF-8 to preserve Persian/Arabic text.
        $output = html_entity_decode( $output, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // If we injected a charset meta, strip it from the output.
        if ( ! $has_charset ) {
            $output = str_replace( '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">', '', $output );
        }

        return $output;
    }

    /**
     * Fix progressbar ARIA label.
     *
     * Adds aria-label to div.bakala-progress-bar[role="progressbar"] elements
     * that lack one.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_progressbar_aria( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find div elements with class containing "bakala-progress-bar",
        // role="progressbar", and no aria-label attribute.
        $nodes = $xpath->query(
            '//div[contains(@class, "bakala-progress-bar")][@role="progressbar"][not(@aria-label)]'
        );

        if ( false === $nodes || 0 === $nodes->length ) {
            return;
        }

        $label = 'پیشرفت سبد خرید تا ارسال رایگان';

        /** @var string $label Filterable progressbar aria-label value. */
        $label = apply_filters( 'brz/a11y/progressbar_label', $label );

        foreach ( $nodes as $node ) {
            /** @var \DOMElement $node */
            $node->setAttribute( 'aria-label', $label );
        }
    }

    /**
     * Fix heading levels in product carousels.
     *
     * Changes h3 to h2 inside .section-products-carousel header
     * to maintain proper heading hierarchy.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_heading_levels( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find h3 elements inside header elements that are descendants of .section-products-carousel.
        $h3_nodes = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " section-products-carousel ")]//header//h3' );

        if ( false === $h3_nodes || 0 === $h3_nodes->length ) {
            return;
        }

        // Collect nodes first to avoid modifying the DOM while iterating.
        $nodes_to_replace = array();
        foreach ( $h3_nodes as $h3 ) {
            $nodes_to_replace[] = $h3;
        }

        foreach ( $nodes_to_replace as $h3 ) {
            // Create a new h2 element.
            $h2 = $dom->createElement( 'h2' );

            // Copy all attributes from h3 to h2.
            if ( $h3->hasAttributes() ) {
                foreach ( $h3->attributes as $attr ) {
                    $h2->setAttribute( $attr->nodeName, $attr->nodeValue );
                }
            }

            // Move all child nodes from h3 to h2.
            while ( $h3->firstChild ) {
                $h2->appendChild( $h3->firstChild );
            }

            // Replace h3 with h2 in the DOM.
            $h3->parentNode->replaceChild( $h2, $h3 );
        }
    }

    /**
     * Fix missing alt attributes on viewed product images.
     *
     * Adds alt text to images in .smart-similar-products .viewed-list
     * that lack the alt attribute. Extracts product name from:
     * 1. The img's own `title` attribute
     * 2. The parent link's `title` attribute or text content
     * 3. Fallback: «تصویر محصول»
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_viewed_images_alt( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find img elements without alt inside .smart-similar-products .viewed-list
        $imgs = $xpath->query(
            '//*[contains(@class, "smart-similar-products")]//*[contains(@class, "viewed-list")]//img[not(@alt)]'
        );

        if ( false === $imgs || 0 === $imgs->length ) {
            return;
        }

        foreach ( $imgs as $img ) {
            $alt_value = '';

            // 1. Try to get title from img itself.
            if ( $img->hasAttribute( 'title' ) ) {
                $alt_value = trim( $img->getAttribute( 'title' ) );
            }

            // 2. If no title on img, check parent <a> element.
            if ( '' === $alt_value ) {
                $parent = $img->parentNode;
                if ( $parent && 'a' === strtolower( $parent->nodeName ) ) {
                    // Try parent link's title attribute.
                    if ( $parent->hasAttribute( 'title' ) ) {
                        $alt_value = trim( $parent->getAttribute( 'title' ) );
                    }
                    // If still empty, try parent link's text content.
                    if ( '' === $alt_value ) {
                        $alt_value = trim( $parent->textContent );
                    }
                }
            }

            // 3. Fallback value.
            if ( '' === $alt_value ) {
                $alt_value = 'تصویر محصول';
            }

            $img->setAttribute( 'alt', $alt_value );
        }
    }

    /**
     * Fix social links ARIA labels.
     *
     * Adds aria-label with Persian social network names to .socials li a
     * elements that lack accessible names.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_social_links_aria( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find links inside .socials list items that lack aria-label.
        $links = $xpath->query( "//*[contains(@class, 'socials')]//li//a[not(@aria-label)]" );

        if ( false === $links ) {
            return;
        }

        foreach ( $links as $link ) {
            /** @var \DOMElement $link */
            $network = self::detect_social_network( $link );

            if ( null === $network ) {
                continue;
            }

            $link->setAttribute( 'aria-label', $network );
        }
    }

    /**
     * Detect the social network name from a link element.
     *
     * Checks classes of the link and its child elements first,
     * then falls back to href domain matching.
     *
     * @param \DOMElement $link The anchor element to inspect.
     * @return string|null Persian name of the social network, or null if not detected.
     */
    private static function detect_social_network( \DOMElement $link ): ?string {
        // 1. Check class of the <a> element itself.
        $match = self::match_class_to_social( $link->getAttribute( 'class' ) );
        if ( null !== $match ) {
            return $match;
        }

        // 2. Check class of child <i> or <span> elements (icon elements).
        foreach ( $link->childNodes as $child ) {
            if ( ! $child instanceof \DOMElement ) {
                continue;
            }
            if ( 'i' === $child->nodeName || 'span' === $child->nodeName ) {
                $match = self::match_class_to_social( $child->getAttribute( 'class' ) );
                if ( null !== $match ) {
                    return $match;
                }
            }
        }

        // 3. Fall back to href domain matching.
        $href = $link->getAttribute( 'href' );
        if ( ! empty( $href ) ) {
            return self::match_href_to_social( $href );
        }

        return null;
    }

    /**
     * Match a class string against known social network keywords.
     *
     * @param string $class_attr The class attribute value.
     * @return string|null Persian social network name, or null.
     */
    private static function match_class_to_social( string $class_attr ): ?string {
        if ( empty( $class_attr ) ) {
            return null;
        }

        $class_lower = mb_strtolower( $class_attr );

        foreach ( self::$social_map as $key => $persian_name ) {
            if ( false !== strpos( $class_lower, $key ) ) {
                return $persian_name;
            }
        }

        return null;
    }

    /**
     * Match an href value against known social network domains.
     *
     * @param string $href The href attribute value.
     * @return string|null Persian social network name, or null.
     */
    private static function match_href_to_social( string $href ): ?string {
        $href_lower = mb_strtolower( $href );

        // Map of domain patterns to social_map keys.
        $domain_patterns = [
            'instagram.com' => 'instagram',
            'telegram.org'  => 'telegram',
            't.me'          => 'telegram',
            'twitter.com'   => 'twitter',
            'x.com'         => 'twitter',
            'facebook.com'  => 'facebook',
            'youtube.com'   => 'youtube',
            'youtu.be'      => 'youtube',
            'linkedin.com'  => 'linkedin',
            'whatsapp.com'  => 'whatsapp',
            'wa.me'         => 'whatsapp',
            'aparat.com'    => 'aparat',
            'pinterest.com' => 'pinterest',
        ];

        foreach ( $domain_patterns as $domain => $social_key ) {
            if ( false !== strpos( $href_lower, $domain ) ) {
                return self::$social_map[ $social_key ];
            }
        }

        return null;
    }

    /**
     * @var array<string, string> Map of class patterns to Persian aria-label values for icon links.
     */
    private static array $icon_link_map = [
        'notify'       => 'اطلاع‌رسانی موجودی',
        'notification' => 'اطلاع‌رسانی موجودی',
        'bell'         => 'اطلاع‌رسانی موجودی',
        'stock-alert'  => 'اطلاع‌رسانی موجودی',
        'compare'      => 'مقایسه محصول',
        'comparison'   => 'مقایسه محصول',
        'wishlist'     => 'افزودن به علاقه‌مندی‌ها',
        'heart'        => 'افزودن به علاقه‌مندی‌ها',
        'favorite'     => 'افزودن به علاقه‌مندی‌ها',
        'share'        => 'اشتراک‌گذاری',
        'cart'         => 'سبد خرید',
        'basket'       => 'سبد خرید',
        'search'       => 'جستجو',
        'close'        => 'بستن',
        'dismiss'      => 'بستن',
        'menu'         => 'منو',
        'hamburger'    => 'منو',
        'nav'          => 'منو',
    ];

    /**
     * Fix icon-only links ARIA labels.
     *
     * Adds aria-label to links that have no text content and lack
     * aria-label or aria-labelledby attributes. Links that already
     * have text content, aria-label, or aria-labelledby are left unchanged.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_icon_links_aria( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find all <a> elements without aria-label and without aria-labelledby.
        $links = $xpath->query( '//a[not(@aria-label)][not(@aria-labelledby)]' );

        if ( false === $links || 0 === $links->length ) {
            return;
        }

        foreach ( $links as $link ) {
            /** @var \DOMElement $link */

            // Check if the link has meaningful text content.
            $text_content = trim( $link->textContent );
            if ( '' !== $text_content ) {
                // Link already has an accessible name via text content — skip.
                continue;
            }

            // Icon-only link: detect purpose from classes.
            $label = self::detect_icon_link_purpose( $link );

            $link->setAttribute( 'aria-label', $label );
        }
    }

    /**
     * Detect the purpose of an icon-only link from its classes or child element classes.
     *
     * Checks the <a> element's own class, then child <i>, <span>, and <svg> classes
     * against known patterns. Returns a generic fallback if no pattern matches.
     *
     * @param \DOMElement $link The anchor element to inspect.
     * @return string Persian aria-label value.
     */
    private static function detect_icon_link_purpose( \DOMElement $link ): string {
        // 1. Check class of the <a> element itself.
        $match = self::match_class_to_icon_purpose( $link->getAttribute( 'class' ) );
        if ( null !== $match ) {
            return $match;
        }

        // 2. Check class of child <i>, <span>, and <svg> elements.
        foreach ( $link->childNodes as $child ) {
            if ( ! $child instanceof \DOMElement ) {
                continue;
            }

            $tag = strtolower( $child->nodeName );
            if ( 'i' === $tag || 'span' === $tag || 'svg' === $tag ) {
                $match = self::match_class_to_icon_purpose( $child->getAttribute( 'class' ) );
                if ( null !== $match ) {
                    return $match;
                }
            }
        }

        // 3. Generic fallback.
        return 'لینک';
    }

    /**
     * Match a class string against known icon link purpose keywords.
     *
     * @param string $class_attr The class attribute value.
     * @return string|null Persian label, or null if no match.
     */
    private static function match_class_to_icon_purpose( string $class_attr ): ?string {
        if ( empty( $class_attr ) ) {
            return null;
        }

        $class_lower = mb_strtolower( $class_attr );

        foreach ( self::$icon_link_map as $keyword => $label ) {
            if ( false !== strpos( $class_lower, $keyword ) ) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Fix nested list structure in spec list.
     *
     * Wraps direct ul children of ul.spec-list inside li elements
     * to produce valid ul > li > ul nesting.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_spec_list_nesting( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find ul elements that are direct children of ul.spec-list.
        $nested_uls = $xpath->query( '//ul[contains(concat(" ", normalize-space(@class), " "), " spec-list ")]/ul' );

        if ( false === $nested_uls || 0 === $nested_uls->length ) {
            return;
        }

        // Collect nodes first to avoid modifying DOM while iterating.
        $nodes_to_wrap = array();
        foreach ( $nested_uls as $ul ) {
            /** @var \DOMElement $ul */
            // Confirm the parent is indeed a ul (not already inside an li).
            $parent = $ul->parentNode;
            if ( $parent instanceof \DOMElement && 'ul' === strtolower( $parent->nodeName ) ) {
                $nodes_to_wrap[] = $ul;
            }
        }

        foreach ( $nodes_to_wrap as $ul ) {
            // Create a new li element.
            $li = $dom->createElement( 'li' );

            // Insert the li before the nested ul in its parent.
            $ul->parentNode->insertBefore( $li, $ul );

            // Move the nested ul inside the new li.
            $li->appendChild( $ul );
        }
    }

    /**
     * Fix cart sidebar list structure.
     *
     * Converts or wraps direct div children of ul elements in the
     * cart sidebar into li elements for valid list markup.
     * Any div that is a direct child of a ul is invalid HTML — this
     * method converts them to li elements while preserving attributes
     * and child nodes.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_cart_sidebar_list( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Target only cart sidebar/mini-cart containers — NOT all ul>div in the page.
        // The cart sidebar uses classes like 'mini-cart-dropdown', 'ar-product', 'mini_cart_item'.
        $divs = $xpath->query( '//*[contains(@class, "mini-cart-dropdown") or contains(@class, "ar-panel-content")]//ul/div' );

        if ( false === $divs || 0 === $divs->length ) {
            return;
        }

        // Collect nodes first to avoid modifying the DOM while iterating.
        $nodes_to_replace = array();
        foreach ( $divs as $div ) {
            $nodes_to_replace[] = $div;
        }

        foreach ( $nodes_to_replace as $div ) {
            /** @var \DOMElement $div */
            // Create a new li element.
            $li = $dom->createElement( 'li' );

            // Copy all attributes from div to li (preserving classes for visual consistency).
            if ( $div->hasAttributes() ) {
                foreach ( $div->attributes as $attr ) {
                    $li->setAttribute( $attr->nodeName, $attr->nodeValue );
                }
            }

            // Move all child nodes from div to li.
            while ( $div->firstChild ) {
                $li->appendChild( $div->firstChild );
            }

            // Replace the div with li in the parent ul.
            $div->parentNode->replaceChild( $li, $div );
        }
    }

    /**
     * Fix duplicate IDs (notify_by_sms).
     *
     * Makes duplicate IDs unique and updates associated label[for]
     * attributes to maintain proper label-input associations.
     *
     * @param \DOMDocument $dom The DOM document to modify.
     */
    public static function fix_duplicate_ids( \DOMDocument $dom ): void {
        $xpath = new \DOMXPath( $dom );

        // Find all elements with id="notify_by_sms".
        $elements = $xpath->query( '//*[@id="notify_by_sms"]' );

        if ( false === $elements || $elements->length < 2 ) {
            return; // No duplicates — nothing to do.
        }

        // Find all labels with for="notify_by_sms".
        $labels = $xpath->query( '//label[@for="notify_by_sms"]' );

        // Collect into arrays for indexed access.
        $element_list = array();
        foreach ( $elements as $el ) {
            $element_list[] = $el;
        }

        $label_list = array();
        if ( false !== $labels ) {
            foreach ( $labels as $lbl ) {
                $label_list[] = $lbl;
            }
        }

        // Keep first element (index 0) and first label unchanged.
        // For index 1+: rename element ID and update corresponding label.
        for ( $i = 1, $count = count( $element_list ); $i < $count; $i++ ) {
            $new_id = 'notify_by_sms_' . ( $i + 1 );

            /** @var \DOMElement $element_list[$i] */
            $element_list[ $i ]->setAttribute( 'id', $new_id );

            // Update the corresponding label if one exists at the same index.
            if ( isset( $label_list[ $i ] ) ) {
                /** @var \DOMElement $label_list[$i] */
                $label_list[ $i ]->setAttribute( 'for', $new_id );
            }
        }
    }
}
