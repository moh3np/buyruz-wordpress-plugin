<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table {
    const META_KEY = '_buyruz_compare_table';
    const META_ID_KEY = '_buyruz_compare_table_id';
    const MIN_COLUMNS = 1;
    const MAX_COLUMNS = 6;
    private static $cache = array();
    private static $rendered = array();

    public static function init() {
        add_shortcode( 'buyruz_compare_table', array( __CLASS__, 'shortcode' ) );
        add_shortcode( 'brz_compare_table', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        if ( ! class_exists( 'BRZ_Settings' ) || ! class_exists( 'BRZ_Detector' ) ) {
            return;
        }

        $opts = BRZ_Settings::get();
        
        $table_targets = array();
        if ( isset( $opts['table_styles_targets'] ) && is_array( $opts['table_styles_targets'] ) ) {
            $table_targets = array_values( array_intersect( $opts['table_styles_targets'], array( 'product', 'page', 'category' ) ) );
        }
        
        $should_load = ! empty( $opts['table_styles_enabled'] ) && BRZ_Detector::should_load_table_styles( $table_targets );

        // Check if the post has a compare table (via shortcode or meta)
        if ( ! $should_load ) {
            global $post;
            if ( $post ) {
                $content = $post->post_content ?? '';
                if ( has_shortcode( $content, 'buyruz_compare_table' ) || has_shortcode( $content, 'brz_compare_table' ) ) {
                    $should_load = true;
                } elseif ( is_singular( 'product' ) && self::has_table( $post->ID ) ) {
                    $should_load = true;
                }
            }
        }

        if ( ! $should_load ) {
            return;
        }

        $handle        = 'brz-table-style';
        $css_file      = BRZ_PATH . 'assets/css/table.css';
        $css_url       = BRZ_URL . 'assets/css/table.css';
        $inline_loaded = false;

        if ( ! empty( $opts['inline_css'] ) ) {
            $css = @file_get_contents( $css_file );
            if ( $css ) {
                $inline_loaded = true;
                wp_register_style( $handle, false, array(), BRZ_VERSION );
                wp_enqueue_style( $handle );
                wp_add_inline_style( $handle, $css );
            }
        }

        if ( ! $inline_loaded ) {
            wp_register_style( $handle, $css_url, array(), BRZ_VERSION );
            wp_enqueue_style( $handle );
        }
    }

    public static function has_table( $post_id ) {
        $data = self::get_table_data( $post_id );
        return ! empty( $data['rows'] );
    }

    public static function get_table_id( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return '';
        }

        $existing   = get_post_meta( $post_id, self::META_ID_KEY, true );
        $normalized = self::normalize_table_id( $existing, $post_id );

        if ( $normalized !== $existing ) {
            update_post_meta( $post_id, self::META_ID_KEY, $normalized );
        }

        return $normalized;
    }

    private static function normalize_table_id( $value, $post_id ) {
        $value = is_string( $value ) ? $value : '';
        $value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
        if ( empty( $value ) ) {
            $value = 'brz-ct-' . absint( $post_id );
        }
        return $value;
    }

    private static function product_id_from_table_id( $value ) {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }

        if ( is_string( $value ) && preg_match( '/(\\d+)/', $value, $m ) ) {
            return absint( $m[1] );
        }

        return 0;
    }

    private static function normalize_cell( $value ) {
        if ( null === $value ) {
            return '';
        }

        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = (string) $value;

        // Decode escaped \uXXXX sequences
        if ( str_contains( $value, '\\u' ) ) {
            $decoded = json_decode( '"' . str_replace( array( "\r", "\n" ), '', addslashes( $value ) ) . '"', true );
            if ( is_string( $decoded ) ) {
                $value = $decoded;
            }
        }

        // Decode bare uXXXX sequences (when backslash was stripped earlier)
        if ( preg_match( '/u[0-9a-fA-F]{4}/', $value ) ) {
            $value = preg_replace_callback(
                '/u([0-9a-fA-F]{4})/',
                function( $m ) {
                    return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
                },
                $value
            );
        }

        return $value;
    }

    private static function get_table_data( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) { return array(); }

        $table_id = self::get_table_id( $post_id );

        if ( isset( self::$cache[ $post_id ] ) ) {
            return self::$cache[ $post_id ];
        }

        $raw = get_post_meta( $post_id, self::META_KEY, true ) ?: '';
        if ( empty( $raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $decoded = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE && ! is_array( $raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        if ( ! is_array( $decoded ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $rows_raw = isset( $decoded['rows'] ) && is_array( $decoded['rows'] ) ? $decoded['rows'] : array();
        if ( empty( $rows_raw ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $enabled = array_key_exists( 'enabled', $decoded ) ? (bool) $decoded['enabled'] : true;
        if ( ! $enabled ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $columns = array();
        if ( isset( $decoded['columns'] ) && is_array( $decoded['columns'] ) ) {
            foreach ( $decoded['columns'] as $col ) {
                $columns[] = is_string( $col ) ? self::normalize_cell( $col ) : '';
            }
        }
        $columns      = array_values( array_slice( $columns, 0, self::MAX_COLUMNS ) );
        $column_count = min( max( count( $columns ), self::MIN_COLUMNS ), self::MAX_COLUMNS );
        $first_row    = reset( $rows_raw );
        $row_width    = is_array( $first_row ) ? count( $first_row ) : 0;
        if ( $row_width > $column_count ) {
            $column_count = min( $row_width, self::MAX_COLUMNS );
        }
        if ( $column_count > count( $columns ) ) {
            $columns = array_pad( $columns, $column_count, '' );
        }

        $rows = array();
        foreach ( $rows_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean = array();
            for ( $i = 0; $i < $column_count; $i++ ) {
                $cell = isset( $row[ $i ] ) ? $row[ $i ] : '';
                $clean[] = is_string( $cell ) ? self::normalize_cell( $cell ) : '';
            }
            if ( array_filter( $clean, 'strlen' ) ) {
                $rows[] = $clean;
            }
        }

        if ( empty( $rows ) ) {
            self::$cache[ $post_id ] = array();
            return self::$cache[ $post_id ];
        }

        $title = isset( $decoded['title'] ) ? self::normalize_cell( $decoded['title'] ) : '';

        self::$cache[ $post_id ] = array(
            'id'      => $table_id,
            'title'   => $title,
            'columns' => $columns,
            'rows'    => $rows,
        );

        return self::$cache[ $post_id ];
    }

    public static function inject_into_wc_description( $content, $product ) {
        if ( ! is_singular( 'product' ) ) {
            return $content;
        }
        $post_id = $product ? $product->get_id() : 0;
        return self::maybe_inject( $content, $post_id );
    }

    public static function inject_into_content( $content ) {
        if ( ! is_singular( 'product' ) ) {
            return $content;
        }

        $post_id = get_the_ID();
        return self::maybe_inject( $content, $post_id );
    }

    private static function maybe_inject( $content, $post_id ) {
        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return $content;
        }

        $html = self::render_table( $data );
        if ( empty( $html ) ) {
            return $content;
        }

        self::$rendered[ $post_id ] = true;

        if ( str_contains( $content ?? '', '[[COMPARE_TABLE]]' ) ) {
            $content = str_replace( '[[COMPARE_TABLE]]', $html, $content );
        } else {
            $content .= $html;
        }

        return $content;
    }

    private static function render_table( $data ) {
        if ( empty( $data['rows'] ) || empty( $data['columns'] ) ) {
            return '';
        }

        $title          = isset( $data['title'] ) ? $data['title'] : '';
        $table_label    = ! empty( $title ) ? $title : 'جدول مقایسه';
        $caption_id_raw = ! empty( $title ) ? ( ! empty( $data['id'] ) ? $data['id'] : uniqid( 'brz-ct-' ) ) : '';
        $caption_id     = $caption_id_raw ? 'brz-ct-caption-' . sanitize_title( $caption_id_raw ) : '';

        ob_start();
        ?>
        <div class="buyruz-table-container">
            <div class="buyruz-table-wrap">
                <table class="buyruz-table" aria-label="<?php echo esc_attr( $table_label ); ?>"<?php echo $caption_id ? ' aria-describedby="' . esc_attr( $caption_id ) . '"' : ''; ?>>
                    <?php if ( ! empty( $data['title'] ) ) : ?>
                        <caption id="<?php echo esc_attr( $caption_id ); ?>" class="buyruz-table-title"><?php echo esc_html( $data['title'] ); ?></caption>
                    <?php endif; ?>
                    <thead>
                        <tr>
                            <?php foreach ( $data['columns'] as $col ) : ?>
                                <th scope="col"><?php echo esc_html( $col ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $data['rows'] as $row ) : ?>
                            <tr>
                                <?php foreach ( $data['columns'] as $index => $col ) : ?>
                                    <?php 
                                    $cell_content = isset( $row[ $index ] ) ? $row[ $index ] : '';
                                    if ( $index === 0 ) : ?>
                                        <th scope="row" data-label="<?php echo esc_attr( $data['columns'][ $index ] ); ?>"><?php echo esc_html( $cell_content ); ?></th>
                                    <?php else : ?>
                                        <td data-label="<?php echo esc_attr( $data['columns'][ $index ] ); ?>"><?php echo esc_html( $cell_content ); ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'id'         => '',
                'product_id' => '',
            ),
            $atts,
            'buyruz_compare_table'
        );

        $post_id = 0;
        if ( ! empty( $atts['product_id'] ) ) {
            $post_id = absint( $atts['product_id'] );
        }

        if ( ! $post_id && ! empty( $atts['id'] ) ) {
            $post_id = self::product_id_from_table_id( $atts['id'] );
        }

        if ( ! $post_id ) {
            return '';
        }

        $expected_id = self::get_table_id( $post_id );
        if ( ! empty( $atts['id'] ) ) {
            $input_id = self::normalize_table_id( $atts['id'], $post_id );
            if ( $expected_id && $input_id && $input_id !== $expected_id ) {
                return '';
            }
        }

        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return '';
        }

        return self::render_table( $data );
    }

    public static function render_after_summary() {
        if ( ! is_singular( 'product' ) ) {
            return;
        }

        $post_id = get_the_ID();
        if ( isset( self::$rendered[ $post_id ] ) ) {
            return;
        }

        $data = self::get_table_data( $post_id );
        if ( empty( $data ) ) {
            return;
        }

        $html = self::render_table( $data );
        if ( empty( $html ) ) {
            return;
        }

        self::$rendered[ $post_id ] = true;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
