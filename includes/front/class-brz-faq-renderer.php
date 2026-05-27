<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * رندر کردن FAQ از schema رنک‌مث با ساختار HTML مناسب برای استایل‌های بایروز.
 * 
 * شورت‌کد [rank_math_rich_snippet id="s-xxx"] برای FAQPage هیچ HTML تولید نمی‌کند.
 * این کلاس آن را override کرده و HTML آکاردئونی تولید می‌کند.
 */
class BRZ_FAQ_Renderer {

    /**
     * Initialize hooks.
     */
    public static function init() {
        // اولویت 999 تا بعد از رنک‌مث اجرا شود و shortcode را override کنیم
        add_action( 'init', array( __CLASS__, 'register_shortcode' ), 999 );
        
        // فیلتر برای شورت‌کدهای با پترن s- که توسط Google Apps Script ایجاد می‌شوند
        add_filter( 'the_content', array( __CLASS__, 'maybe_render_faq_in_content' ), 8 );

        // Asset loading (absorbed from BRZ_Enqueue)
        if ( ! is_admin() ) {
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
        }
    }

    /**
     * Enqueue FAQ frontend assets (CSS/JS) when FAQ content is detected.
     * Logic absorbed from the old BRZ_Enqueue::frontend() method.
     */
    public static function enqueue_frontend_assets(): void {
        if ( is_admin() ) {
            return;
        }

        $opts         = BRZ_Settings::get();
        $should_load  = BRZ_Detector::should_load();

        // Fallback: if RankMath active and singular page, load FAQ assets
        if ( ! $should_load && class_exists( '\RankMath' ) && is_singular() ) {
            $should_load = true;
        }

        if ( ! $should_load ) {
            return;
        }

        // JS
        if ( ! empty( $opts['enable_js'] ) ) {
            $data = array(
                'singleOpen' => ! empty( $opts['single_open'] ),
                'animate'    => ! empty( $opts['animate'] ),
                'selector'   => '.rank-math-faq',
            );
            wp_register_script( 'brz-faq', BRZ_URL . 'assets/js/faq.js', array(), BRZ_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
            wp_add_inline_script( 'brz-faq', 'window.BRZ=' . wp_json_encode( $data ) . ';', 'before' );
            wp_enqueue_script( 'brz-faq' );
        }

        // CSS
        if ( ! empty( $opts['enable_css'] ) ) {
            $brand    = $opts['brand_color'] ?? '#1a73e8';
            $css_vars = ':root{--brz-brand: ' . $brand . ';}';

            if ( ! empty( $opts['inline_css'] ) ) {
                $css = @file_get_contents( BRZ_PATH . 'assets/css/faq.css' );
                if ( $css ) {
                    wp_register_style( 'brz-faq', false, array(), BRZ_VERSION );
                    wp_enqueue_style( 'brz-faq' );
                    wp_add_inline_style( 'brz-faq', $css_vars . $css );
                } else {
                    wp_register_style( 'brz-faq', BRZ_URL . 'assets/css/faq.css', array(), BRZ_VERSION );
                    wp_enqueue_style( 'brz-faq' );
                    wp_add_inline_style( 'brz-faq', $css_vars );
                }
            } else {
                wp_register_style( 'brz-faq', BRZ_URL . 'assets/css/faq.css', array(), BRZ_VERSION );
                wp_enqueue_style( 'brz-faq' );
                wp_add_inline_style( 'brz-faq', $css_vars );
            }
        }
    }

    /**
     * Register our shortcode, overriding Rank Math's if exists.
     */
    public static function register_shortcode() {
        // حذف shortcode قبلی رنک‌مث و جایگزینی با مال خودمان
        remove_shortcode( 'rank_math_rich_snippet' );
        add_shortcode( 'rank_math_rich_snippet', array( __CLASS__, 'render_shortcode' ) );
    }

    /**
     * Handle [rank_math_rich_snippet id="..."] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => '' ), $atts, 'rank_math_rich_snippet' );
        $id   = sanitize_text_field( $atts['id'] ?? '' );

        if ( empty( $id ) ) {
            return '';
        }

        // بررسی اینکه آیا این یک FAQ schema است یا نه
        // ID های FAQ ما با 's-' شروع می‌شوند
        if ( ! str_starts_with( $id, 's-' ) ) {
            // اگر FAQ نیست، اجازه بده رنک‌مث خودش handle کند
            return self::fallback_to_rankmath( $id );
        }

        global $post;
        if ( ! $post ) {
            return '';
        }

        $faq_html = self::build_faq_html( $post->ID, $id );
        
        if ( empty( $faq_html ) ) {
            // اگر FAQ پیدا نشد، شاید نوع دیگری از schema باشد
            return self::fallback_to_rankmath( $id );
        }

        return $faq_html;
    }

    /**
     * Fallback to Rank Math's original shortcode handler.
     *
     * @param string $id Schema ID.
     * @return string
     */
    private static function fallback_to_rankmath( $id ) {
        // رنک‌مث ممکن است handler خودش را داشته باشد
        if ( class_exists( '\RankMath\Schema\Shortcode' ) && method_exists( '\RankMath\Schema\Shortcode', 'rich_snippet' ) ) {
            return \RankMath\Schema\Shortcode::rich_snippet( array( 'id' => $id ) );
        }
        return '';
    }

    /**
     * Build FAQ accordion HTML from schema data.
     *
     * @param int    $post_id Post ID.
     * @param string $schema_id Schema identifier (e.g., 's-123-timestamp').
     * @return string HTML output.
     */
    public static function build_faq_html( $post_id, $schema_id = '' ) {
        $faq_data = self::get_faq_schema( $post_id, $schema_id );

        if ( empty( $faq_data ) || empty( $faq_data['mainEntity'] ) ) {
            return '';
        }

        $items = $faq_data['mainEntity'];
        if ( ! is_array( $items ) || empty( $items ) ) {
            return '';
        }

        // ساخت HTML با ساختار مشابه بلاک FAQ رنک‌مث
        $unique_id = 'brz-faq-' . esc_attr( $schema_id ?: $post_id );
        
        $html  = '<div id="rank-math-faq" class="rank-math-block brz-faq-rendered" data-schema-id="' . esc_attr( $schema_id ) . '">';
        $html .= '<ul class="rank-math-list">';

        $index = 0;
        foreach ( $items as $item ) {
            if ( ! isset( $item['name'] ) || ! isset( $item['acceptedAnswer']['text'] ) ) {
                continue;
            }

            $question = $item['name'];
            $answer   = $item['acceptedAnswer']['text'];
            $item_id  = $unique_id . '-' . $index;
            $index++;

            $html .= '<li id="' . esc_attr( $item_id ) . '" class="rank-math-list-item">';
            $html .= '<h3 class="rank-math-question">' . esc_html( $question ) . '</h3>';
            $html .= '<div class="rank-math-answer">' . wp_kses_post( $answer ) . '</div>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get FAQ schema data from post meta.
     *
     * @param int    $post_id Post ID.
     * @param string $target_id Optional schema ID to match.
     * @return array|null FAQ schema data.
     */
    public static function get_faq_schema( $post_id, $target_id = '' ) {
        // ابتدا از متای rank_math_schema_FAQPage بخوان
        $faq_meta = get_post_meta( $post_id, 'rank_math_schema_FAQPage', true );

        if ( ! empty( $faq_meta ) && is_array( $faq_meta ) ) {
            // بررسی تطابق ID
            if ( ! empty( $target_id ) && isset( $faq_meta['metadata']['shortcode'] ) ) {
                $shortcode_id = $faq_meta['metadata']['shortcode'];
                // ID می‌تواند کامل باشد یا فقط بخش s-xxx
                if ( $shortcode_id === $target_id || str_contains( $target_id, $shortcode_id ) || str_contains( $shortcode_id, $target_id ) ) {
                    return $faq_meta;
                }
            } else {
                return $faq_meta;
            }
        }

        // روش دوم: از DB رنک‌مث
        if ( class_exists( '\RankMath\Schema\DB' ) ) {
            $schemas = \RankMath\Schema\DB::get_schemas( $post_id );
            if ( is_array( $schemas ) ) {
                foreach ( $schemas as $schema_key => $schema ) {
                    $type = isset( $schema['@type'] ) ? $schema['@type'] : '';
                    $is_faq = ( is_string( $type ) && strtolower( $type ) === 'faqpage' ) 
                           || ( is_array( $type ) && in_array( 'FAQPage', $type, true ) );
                    
                    if ( $is_faq ) {
                        // بررسی تطابق ID
                        if ( ! empty( $target_id ) ) {
                            $meta_shortcode = isset( $schema['metadata']['shortcode'] ) ? $schema['metadata']['shortcode'] : '';
                            if ( $meta_shortcode === $target_id || $schema_key === $target_id ) {
                                return $schema;
                            }
                        } else {
                            return $schema;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Auto-render FAQ in content if shortcode is present but not processed.
     *
     * @param string $content Post content.
     * @return string Modified content.
     */
    public static function maybe_render_faq_in_content( $content ) {
        $content = $content ?? '';

        // اگر شورت‌کد قبلاً process شده، کاری نکن
        if ( str_contains( $content, 'brz-faq-rendered' ) ) {
            return $content;
        }

        // بررسی وجود شورت‌کد rank_math_rich_snippet با پترن s-
        if ( preg_match( '/\[rank_math_rich_snippet\s+id=["\']?(s-[^"\'>\s]+)["\']?\s*\]/', $content, $matches ) ) {
            // شورت‌کد پیدا شد - do_shortcode باید آن را handle کند
            // ولی اگر رنک‌مث shortcode handler را register نکرده، ما باید کار کنیم
            // این فیلتر زودتر از do_shortcode اجرا می‌شود
        }

        return $content;
    }
}
