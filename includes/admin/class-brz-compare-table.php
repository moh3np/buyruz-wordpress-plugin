<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Compare_Table_Admin {
    const META_KEY = '_buyruz_compare_table';
    const META_ID_KEY = '_buyruz_compare_table_id';
    const MIN_COLUMNS = 1;
    const MAX_COLUMNS = 6;
    const ADMIN_PAGE = 'buyruz-compare-editor';
    private static $processed = array();
    private static $blocked_new_editor = false;
    private static $panel_rendered = false;

    public static function init() {
        add_filter( 'woocommerce_admin_features', array( __CLASS__, 'guard_product_editor_features' ), 5 );
        add_filter( 'woocommerce_new_product_management_experience_enabled', '__return_false', 5 );
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_product' ), 20, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'maybe_show_editor_notice' ) );
        add_action( 'add_meta_boxes_product', array( __CLASS__, 'register_fallback_metabox' ), 5 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_object' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_footer', array( __CLASS__, 'maybe_hide_duplicate_fallback' ) );
    }

    public static function add_product_tab( $tabs ) {
        $tabs['brz_compare'] = array(
            'label'    => 'جدول مقایسه',
            'target'   => 'brz_compare_table_panel',
            'class'    => array(),
            'priority' => 62,
        );

        return $tabs;
    }

    public static function guard_product_editor_features( $features ) {
        if ( ! is_array( $features ) ) {
            return $features;
        }

        $blocked = array();
        $needles = array( 'product_block_editor', 'product-block-editor', 'new-product-management-experience' );

        foreach ( $features as $feature ) {
            $keep = true;
            if ( is_string( $feature ) ) {
                foreach ( $needles as $needle ) {
                    if ( '' !== $needle && strpos( $feature, $needle ) !== false ) {
                        $keep = false;
                        break;
                    }
                }
            }
            if ( $keep ) {
                $blocked[] = $feature;
            } else {
                self::$blocked_new_editor = true;
            }
        }

        return array_values( $blocked );
    }

    public static function disable_block_editor_for_product( $use_block_editor, $post_type ) {
        if ( 'product' !== $post_type ) {
            return $use_block_editor;
        }

        if ( $use_block_editor ) {
            self::$blocked_new_editor = true;
        }

        return false;
    }

    public static function maybe_show_editor_notice() {
        if ( ! self::$blocked_new_editor ) {
            return;
        }

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( empty( $screen ) || 'product' !== $screen->post_type ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html( 'برای نمایش تب «جدول مقایسه»، ویرایشگر جدید محصول ووکامرس غیرفعال و حالت کلاسیک فعال شد.' );
        echo '</p></div>';
    }

    public static function register_fallback_metabox() {
        add_meta_box(
            'brz-compare-table-fallback',
            'جدول مقایسه محصول',
            array( __CLASS__, 'render_fallback_metabox' ),
            'product',
            'normal',
            'high'
        );
    }

    public static function enqueue( $hook ) {
        $screen = get_current_screen();
        $is_editor_page = ( ! empty( $screen ) && self::ADMIN_PAGE === $screen->id );

        if ( 'post.php' !== $hook && 'post-new.php' !== $hook && ! $is_editor_page ) {
            return;
        }
        if ( empty( $screen ) || ( 'product' !== $screen->post_type && ! $is_editor_page ) ) {
            return;
        }

        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
        wp_enqueue_script(
            'brz-compare-table-admin',
            BRZ_URL . 'assets/admin/product-compare-lite.js',
            array(),
            BRZ_VERSION,
            true
        );
    }

    public static function render_product_tab() {
        global $post;

        if ( empty( $post ) || 'product' !== $post->post_type ) {
            return;
        }

        self::$panel_rendered = true;
        ?>
        <div id="brz_compare_table_panel" class="panel woocommerce_options_panel">
            <?php self::render_editor_inner( $post ); ?>
        </div>
        <?php
    }

    public static function render_fallback_metabox( $post ) {
        self::render_editor_inner( $post );
    }

    public static function save_product_object( $product ) {
        if ( empty( $product ) ) {
            return;
        }
        $post_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
        if ( $post_id ) {
            self::save( $post_id );
        }
    }

    public static function save( $post_id, $post = null ) {
        try {
            if ( isset( self::$processed[ $post_id ] ) ) {
                return;
            }
            if ( ! self::should_process_request( $post_id ) ) {
                return;
            }

            self::$processed[ $post_id ] = true;
            $payload = self::sanitize_payload( self::collect_from_request() );
            $table_id = self::get_table_id( $post_id );
            if ( ! empty( $payload ) ) {
                $payload['id'] = $table_id;
            }
            self::persist_payload( $post_id, $payload, $table_id );
        } catch ( \Throwable $e ) {
            $log_file = WP_CONTENT_DIR . '/uploads/buyruz-debug.log';
            $message = sprintf(
                "[%s] COMPARE TABLE SAVE EXCEPTION: %s in %s on line %d\n",
                date( 'Y-m-d H:i:s' ),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
            @file_put_contents( $log_file, $message, FILE_APPEND );
        }
    }

    public static function get_meta( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        if ( empty( $raw ) ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
            return array();
        }
        return $decoded;
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

    public static function build_editor_url( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) {
            return '';
        }

        return add_query_arg(
            array(
                'page'     => self::ADMIN_PAGE,
                'product'  => $product_id,
                '_wpnonce' => wp_create_nonce( 'brz_compare_editor_' . $product_id ),
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function delete_table( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }
        delete_post_meta( $post_id, self::META_KEY );
        delete_post_meta( $post_id, self::META_ID_KEY );
    }

    public static function get_tables_index( array $args = array() ) {
        $defaults = array(
            'posts_per_page' => 200,
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'meta_key'       => self::META_KEY,
            'fields'         => 'all',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        $query = new WP_Query( wp_parse_args( $args, $defaults ) );
        $items = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $product_id = get_the_ID();
                $meta       = self::get_meta( $product_id );
                if ( empty( $meta ) ) {
                    continue;
                }
                $columns   = isset( $meta['columns'] ) && is_array( $meta['columns'] ) ? $meta['columns'] : array();
                $rows      = isset( $meta['rows'] ) && is_array( $meta['rows'] ) ? $meta['rows'] : array();
                $table_id  = self::get_table_id( $product_id );
                $items[]   = array(
                    'product_id' => $product_id,
                    'product_title' => get_the_title( $product_id ),
                    'table_id'  => $table_id,
                    'title'     => isset( $meta['title'] ) ? $meta['title'] : '',
                    'columns'   => $columns,
                    'rows'      => $rows,
                    'edit_url'  => self::build_editor_url( $product_id ),
                );
            }
            wp_reset_postdata();
        }

        return $items;
    }

    private static function editor_data( $post_id ) {
        $meta    = self::get_meta( $post_id );
        $columns = array();
        $table_id = self::get_table_id( $post_id );

        if ( isset( $meta['columns'] ) && is_array( $meta['columns'] ) ) {
            foreach ( $meta['columns'] as $col ) {
                $columns[] = sanitize_text_field( self::normalize_cell( $col ) );
            }
        }

        $columns       = array_slice( $columns, 0, self::MAX_COLUMNS );
        $columns_count = count( $columns );

        if ( 0 === $columns_count ) {
            // شروع خالی: یک ستون بدون مقدار برای ویرایش
            $columns       = array( '' );
            $columns_count = 1;
        }

        $rows_raw = isset( $meta['rows'] ) && is_array( $meta['rows'] ) ? $meta['rows'] : array();
        $rows     = array();
        if ( ! empty( $rows_raw ) ) {
            foreach ( $rows_raw as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $clean_row = array();
                for ( $i = 0; $i < $columns_count; $i++ ) {
                    $clean_row[] = isset( $row[ $i ] ) ? sanitize_text_field( self::normalize_cell( $row[ $i ] ) ) : '';
                }
                $rows[] = $clean_row;
            }
        }

        // حداقل یک ردیف بدنه برای شروع ویرایش
        if ( empty( $rows ) ) {
            $rows[] = array_fill( 0, $columns_count, '' );
        }

        return array(
            'title'    => isset( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : '',
            'columns'  => $columns,
            'rows'     => $rows,
            'table_id' => $table_id,
        );
    }

    private static function collect_from_request() {
        $title   = isset( $_POST['brz_compare_title'] ) ? wp_unslash( $_POST['brz_compare_title'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $columns = isset( $_POST['brz_compare_columns'] ) ? (array) wp_unslash( $_POST['brz_compare_columns'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rows    = isset( $_POST['brz_compare_rows'] ) ? (array) wp_unslash( $_POST['brz_compare_rows'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return array(
            'title'   => $title,
            'columns' => $columns,
            'rows'    => $rows,
        );
    }

    private static function sanitize_payload( array $raw ) {
        $title  = isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '';
        $cols   = isset( $raw['columns'] ) && is_array( $raw['columns'] ) ? $raw['columns'] : array();
        $rows   = isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array();

        $clean_columns = array();
        foreach ( $cols as $col ) {
            $clean_columns[] = sanitize_text_field( self::normalize_cell( $col ) );
        }
        // تعیین تعداد ستون بر اساس بیشترین مقدار بین هدر و پهن‌ترین ردیف
        $max_row_width = 0;
        foreach ( $rows as $row ) {
            if ( is_array( $row ) ) {
                $max_row_width = max( $max_row_width, count( $row ) );
            }
        }
        $column_count = min( max( max( count( $clean_columns ), $max_row_width ), self::MIN_COLUMNS ), self::MAX_COLUMNS );
        $clean_columns = array_slice( array_pad( $clean_columns, $column_count, '' ), 0, self::MAX_COLUMNS );

        $clean_rows = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean_row = array();
            for ( $i = 0; $i < $column_count; $i++ ) {
                $cell = isset( $row[ $i ] ) ? $row[ $i ] : '';
                $clean_row[] = sanitize_text_field( self::normalize_cell( $cell ) );
            }
            $clean_rows[] = $clean_row;
        }

        if ( empty( $clean_rows ) ) {
            return array();
        }

        return array(
            'title'   => $title,
            'columns' => $clean_columns,
            'rows'    => $clean_rows,
        );
    }

    private static function persist_payload( $post_id, array $payload, $table_id = '' ) {
        $post_id = absint( $post_id );

        if ( $table_id ) {
            update_post_meta( $post_id, self::META_ID_KEY, $table_id );
        }

        if ( empty( $payload ) ) {
            delete_post_meta( $post_id, self::META_KEY );
            return;
        }

        if ( $table_id ) {
            $payload['id'] = $table_id;
        }

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $payload ) );
    }

    private static function should_process_request( $post_id ) {
        if ( ! isset( $_POST['brz_compare_table_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['brz_compare_table_nonce'] ), 'brz_compare_table_save' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }

        if ( ! current_user_can( 'edit_product', $post_id ) ) {
            return false;
        }

        return true;
    }

    private static function default_columns() {
        $saved = class_exists( 'BRZ_Settings' ) ? BRZ_Settings::get( 'compare_table_columns', array() ) : array();
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return array_slice( array_values( array_filter( $saved, 'strlen' ) ), 0, self::MAX_COLUMNS );
    }

    private static function normalize_cell( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = (string) $value;

        // Decode escaped \uXXXX sequences
        if ( strpos( $value, '\\u' ) !== false ) {
            $decoded = json_decode( '"' . str_replace( array( "\r", "\n" ), '', addslashes( $value ) ) . '"', true );
            if ( is_string( $decoded ) ) {
                $value = $decoded;
            }
        }

        // Decode bare uXXXX sequences that ممکن است قبلاً بک‌اسلش‌شان حذف شده باشد.
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

    public static function register_admin_page() {
        add_submenu_page(
            '',
            'جدول مقایسه',
            'جدول مقایسه',
            'edit_products',
            self::ADMIN_PAGE,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function add_row_action( $actions, $post ) {
        if ( empty( $post ) || 'product' !== $post->post_type ) {
            return $actions;
        }
        if ( ! current_user_can( 'edit_product', $post->ID ) ) {
            return $actions;
        }

        $url = self::build_editor_url( $post->ID );
        $actions['brz_compare'] = '<a href="' . esc_url( $url ) . '">جدول مقایسه</a>';
        return $actions;
    }

    public static function render_admin_page() {
        $product_id = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $product_id ) {
            echo '<div class="notice notice-error"><p>محصولی انتخاب نشده است.</p></div>';
            return;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'brz_compare_editor_' . $product_id ) ) {
            echo '<div class="notice notice-error"><p>دسترسی مجاز نیست.</p></div>';
            return;
        }

        if ( ! current_user_can( 'edit_product', $product_id ) ) {
            echo '<div class="notice notice-error"><p>دسترسی کافی برای ویرایش این محصول ندارید.</p></div>';
            return;
        }

        $product = get_post( $product_id );
        if ( ! $product || 'product' !== $product->post_type ) {
            echo '<div class="notice notice-error"><p>محصول یافت نشد.</p></div>';
            return;
        }

        echo '<div class="wrap" dir="rtl">';
        echo '<h1 class="wp-heading-inline">جدول مقایسه محصول</h1>';
        echo ' <a class="page-title-action" href="' . esc_url( get_edit_post_link( $product_id, '' ) ) . '">بازگشت به ویرایش محصول</a>';
        echo '<hr class="wp-header-end" />';
        echo '<div style="max-width:1200px;">';
        self::render_editor_inner( $product );
        echo '</div>';
        echo '</div>';
    }

    private static function render_editor_inner( $post ) {
        $post_id       = is_object( $post ) ? $post->ID : (int) $post;
        $data          = self::editor_data( $post_id );
        $defaults      = self::default_columns();
        $max_columns   = self::MAX_COLUMNS;
        $nonce         = wp_create_nonce( 'brz_compare_table_save' );
        $columns_count = count( $data['columns'] );
        ?>
        <div class="brz-compare-box brz-compare-modern" data-default-columns="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>" data-product-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-max-cols="<?php echo esc_attr( $max_columns ); ?>">
            <?php wp_nonce_field( 'brz_compare_table_save', 'brz_compare_table_nonce' ); ?>

            <div class="brz-compare-top">
                <div>
                    <h3>جدول مقایسه محصول</h3>
                    <p class="description">عنوان جدول اختیاری است. سطر اول هدر جدول است و سایر سطرها داده‌ها هستند.</p>
                </div>
                <div class="brz-compare-top__title">
                    <label for="brz-compare-title"><strong>عنوان جدول (اختیاری)</strong></label>
                    <input id="brz-compare-title" type="text" name="brz_compare_title" class="widefat" value="<?php echo esc_attr( $data['title'] ); ?>" placeholder="مثلاً جدول سایزبندی" />
                </div>
            </div>

            <hr class="brz-compare-divider" />

            <div class="brz-compare-sheet">
                <div class="brz-compare-sheet__actions brz-compare-sheet__actions--stacked">
                    <div class="brz-compare-sheet__hint">برای افزودن/حذف ستون از دکمه‌های بالای سطر هدر و برای مدیریت سطرها از کنترل سمت چپ هر سطر استفاده کنید.</div>
                    <div class="brz-compare-id">
                        <div class="brz-compare-id__label">شناسه جدول</div>
                        <div class="brz-compare-id__value" data-compare-id><?php echo esc_html( $data['table_id'] ); ?></div>
                        <div class="brz-compare-id__shortcode">شورت‌کد: <code>[buyruz_compare_table id="<?php echo esc_attr( $data['table_id'] ); ?>"]</code></div>
                        <input type="hidden" name="brz_compare_table_id" value="<?php echo esc_attr( $data['table_id'] ); ?>" />
                    </div>
                </div>

                <div class="brz-compare-table-wrapper">
                    <table class="brz-compare-table" id="brz-compare-table">
                        <thead>
                            <tr class="brz-compare-row--header" data-row="header">
                                <th class="brz-compare-actions-head">
                                    <button type="button" class="brz-compare-btn brz-compare-btn--success" data-add-row="header" aria-label="افزودن ردیف">+</button>
                                </th>
                                <?php foreach ( $data['columns'] as $col_index => $col_value ) : ?>
                                    <th class="brz-compare-th" data-col="<?php echo esc_attr( $col_index ); ?>">
                                        <div class="brz-compare-th-content">
                                            <div class="brz-compare-col-actions">
                                                <button type="button" class="brz-compare-mini-btn" data-add-col="<?php echo esc_attr( $col_index ); ?>" aria-label="افزودن ستون">+</button>
                                                <button type="button" class="brz-compare-mini-btn brz-compare-danger" data-remove-col="<?php echo esc_attr( $col_index ); ?>" aria-label="حذف ستون">&times;</button>
                                            </div>
                                            <input type="text" name="brz_compare_columns[]" value="<?php echo esc_attr( $col_value ); ?>" placeholder="هدر <?php echo esc_attr( $col_index + 1 ); ?>" />
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['rows'] as $r_index => $row ) : ?>
                                <tr class="brz-compare-row" data-row="<?php echo esc_attr( $r_index ); ?>">
                                    <td class="brz-compare-row-actions-cell">
                                        <div class="brz-compare-row-actions">
                                            <button type="button" class="brz-compare-btn brz-compare-btn--success" data-add-row="<?php echo esc_attr( $r_index ); ?>" aria-label="افزودن ردیف">+</button>
                                            <button type="button" class="brz-compare-btn brz-compare-btn--danger" data-remove-row="<?php echo esc_attr( $r_index ); ?>" aria-label="حذف ردیف">&minus;</button>
                                        </div>
                                    </td>
                                    <?php for ( $c = 0; $c < $columns_count; $c++ ) : ?>
                                        <?php $cell = isset( $row[ $c ] ) ? $row[ $c ] : ''; ?>
                                        <td class="brz-compare-td">
                                            <input type="text" name="brz_compare_rows[<?php echo esc_attr( $r_index ); ?>][<?php echo esc_attr( $c ); ?>]" value="<?php echo esc_attr( $cell ); ?>" />
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public static function maybe_hide_duplicate_fallback() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        $is_product_screen = ( $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true ) );
        if ( ! $is_product_screen ) {
            return;
        }

        ?>
        <script>
        (function() {
            // Remove fallback metabox only when the WooCommerce tab (link + panel) is present to avoid duplicate UIs.
            var tabPanel = document.getElementById('brz_compare_table_panel');
            var tabLink = document.querySelector('.wc-tabs a[href="#brz_compare_table_panel"]');
            var fallback = document.getElementById('brz-compare-table-fallback');
            var tabVisible = tabLink && tabLink.offsetParent !== null;
            var panelVisible = tabPanel && tabPanel.offsetParent !== null;
            if (tabVisible && panelVisible && fallback) { fallback.remove(); }
        })();
        </script>
        <?php
    }
}
