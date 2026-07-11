<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Sidebar_Filters {

    public const TABLE_SUFFIX = 'buyruz_filters_lookup';
    private static array $synced_products = array();

    /**
     * Get the full table name with WordPress prefix.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create or update the custom lookup table using dbDelta.
     */
    public static function ensure_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id  BIGINT(20) UNSIGNED NOT NULL,
            meta_key    VARCHAR(100)        NOT NULL,
            value_num   DECIMAL(15,4)       NULL,
            value_char  VARCHAR(191)        NULL,
            PRIMARY KEY (id),
            KEY idx_product_id (product_id),
            KEY idx_key_num (meta_key, value_num),
            KEY idx_key_char (meta_key, value_char)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        if ( ! BRZ_Modules::is_enabled( 'sidebar_filters' ) ) {
            return;
        }

        // Product save & sync hooks
        add_action( 'woocommerce_update_product', array( __CLASS__, 'sync_product_filters' ), 10, 1 );
        add_action( 'woocommerce_new_product', array( __CLASS__, 'sync_product_filters' ), 10, 1 );
        add_action( 'save_post_product', array( __CLASS__, 'sync_product_filters' ), 10, 1 );
        add_action( 'delete_post', array( __CLASS__, 'delete_product_filters' ), 10, 1 );

        // Query clauses interceptor
        add_filter( 'posts_clauses', array( __CLASS__, 'filter_product_query_clauses' ), 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_brz_rebuild_filters_lookup', array( __CLASS__, 'ajax_rebuild_lookup_table' ) );
        add_action( 'wp_ajax_brz_save_filters_settings', array( __CLASS__, 'ajax_save_filters_settings' ) );

        // Register widget
        add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );

        // Front-end assets
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
    }

    /**
     * Enqueue CSS/JS on product archives.
     */
    public static function enqueue_frontend_assets(): void {
        if ( is_admin() ) {
            return;
        }

        // Enqueue only on product archives/category pages or search pages
        if ( is_post_type_archive( 'product' ) || is_product_category() || is_product_tag() || is_product_taxonomy() || is_search() ) {
            wp_enqueue_style( 'brz-sidebar-filters', BRZ_URL . 'assets/css/sidebar-filters.css', array(), BRZ_VERSION );
            wp_enqueue_script( 'brz-sidebar-filters', BRZ_URL . 'assets/js/sidebar-filters.js', array(), BRZ_VERSION, true );

            // Pass configurations to JS
            $opts = get_option( BRZ_OPTION, array() );
            $filters_opts = isset( $opts['sidebar_filters'] ) ? $opts['sidebar_filters'] : array();
            
            wp_localize_script( 'brz-sidebar-filters', 'brzFiltersConfig', array(
                'container_selector' => ! empty( $filters_opts['container_selector'] ) ? $filters_opts['container_selector'] : '.products-box',
                'pagination_selector'=> ! empty( $filters_opts['pagination_selector'] ) ? $filters_opts['pagination_selector'] : '.woocommerce-pagination',
                'count_selector'     => ! empty( $filters_opts['count_selector'] ) ? $filters_opts['count_selector'] : '.woocommerce-result-count',
                'ajax_enabled'       => isset( $filters_opts['ajax_enabled'] ) ? (bool) $filters_opts['ajax_enabled'] : true,
                'push_state'         => isset( $filters_opts['push_state'] ) ? (bool) $filters_opts['push_state'] : true,
            ) );
        }
    }

    /**
     * Register the Widget.
     */
    public static function register_widget(): void {
        register_widget( 'BRZ_Widget_Advanced_Filters' );
    }

    /**
     * Sync wrapper to prevent double runs on same request.
     */
    public static function sync_product_filters( $product_id ): void {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 ) {
            return;
        }

        if ( in_array( $product_id, self::$synced_products, true ) ) {
            return;
        }
        self::$synced_products[] = $product_id;

        self::update_lookup_table( $product_id );
    }

    /**
     * Sync specific product to lookup table.
     */
    public static function update_lookup_table( int $product_id ): void {
        global $wpdb;
        $table = self::table_name();

        // 1. Clear old records
        $wpdb->delete( $table, array( 'product_id' => $product_id ), array( '%d' ) );

        if ( ! class_exists( 'BRZ_Product_Specs' ) ) {
            return;
        }

        $fields = BRZ_Product_Specs::get_fields();
        if ( empty( $fields ) ) {
            return;
        }

        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( 'range' === $type ) {
                $keys = BRZ_Product_Specs::get_range_meta_keys( $key );
                $min_val = get_post_meta( $product_id, $keys[0], true );
                $max_val = get_post_meta( $product_id, $keys[1], true );

                if ( $min_val === '' && $max_val === '' ) {
                    if ( strpos( $key, 'age' ) !== false || strpos( $key, 'سن' ) !== false || ( isset( $field['label'] ) && ( strpos( $field['label'], 'سن' ) !== false || strpos( $field['label'], 'age' ) !== false ) ) ) {
                        $fallback = BRZ_Product_Specs::get_audience_fallback_range( $product_id );
                        if ( $fallback ) {
                            $min_val = $fallback['min'];
                            $max_val = $fallback['max'];
                        }
                    }
                }

                if ( $min_val !== '' && $min_val !== null ) {
                    $wpdb->insert(
                        $table,
                        array(
                            'product_id' => $product_id,
                            'meta_key'   => $key . '_min',
                            'value_num'  => floatval( $min_val ),
                        ),
                        array( '%d', '%s', '%f' )
                    );
                }
                if ( $max_val !== '' && $max_val !== null ) {
                    $wpdb->insert(
                        $table,
                        array(
                            'product_id' => $product_id,
                            'meta_key'   => $key . '_max',
                            'value_num'  => floatval( $max_val ),
                        ),
                        array( '%d', '%s', '%f' )
                    );
                }
            } elseif ( 'array' === $type ) {
                $val = get_post_meta( $product_id, '_brz_spec_' . $key, true );
                if ( ! empty( $val ) ) {
                    $decoded = json_decode( $val, true );
                    if ( ! is_array( $decoded ) ) {
                        $decoded = maybe_unserialize( $val );
                    }
                    if ( is_array( $decoded ) ) {
                        foreach ( $decoded as $item ) {
                            $item = trim( $item );
                            if ( '' !== $item ) {
                                $wpdb->insert(
                                    $table,
                                    array(
                                        'product_id' => $product_id,
                                        'meta_key'   => $key,
                                        'value_char' => $item,
                                    ),
                                    array( '%d', '%s', '%s' )
                                );
                            }
                        }
                    }
                }
            } elseif ( 'boolean' === $type ) {
                $val = get_post_meta( $product_id, '_brz_spec_' . $key, true );
                if ( $val !== '' && $val !== null ) {
                    $bool_val = ( $val === '1' || $val === 'true' || $val === true ) ? 1 : 0;
                    $wpdb->insert(
                        $table,
                        array(
                            'product_id' => $product_id,
                            'meta_key'   => $key,
                            'value_num'  => $bool_val,
                            'value_char' => strval( $bool_val ),
                        ),
                        array( '%d', '%s', '%f', '%s' )
                    );
                }
            } elseif ( 'integer' === $type || 'decimal' === $type ) {
                $val = get_post_meta( $product_id, '_brz_spec_' . $key, true );
                if ( $val !== '' && $val !== null ) {
                    $wpdb->insert(
                        $table,
                        array(
                            'product_id' => $product_id,
                            'meta_key'   => $key,
                            'value_num'  => floatval( $val ),
                        ),
                        array( '%d', '%s', '%f' )
                    );
                }
            }
        }
    }

    /**
     * Delete product records from lookup table.
     */
    public static function delete_product_filters( $product_id ): void {
        global $wpdb;
        $wpdb->delete( self::table_name(), array( 'product_id' => intval( $product_id ) ), array( '%d' ) );
    }

    /**
     * Check if there are any products in the current category query that have the specified spec key.
     * Prevents displaying irrelevant filters in specific categories (like displaying board game players count in book categories).
     */
    public static function has_products_with_spec_in_query( string $field_key ): bool {
        global $wpdb;

        if ( ! is_product_category() ) {
            return true; // Only filter on category pages
        }

        $term_id = get_queried_object_id();
        if ( $term_id <= 0 ) {
            return true;
        }

        // Get subcategories recursively
        $term_ids = get_term_children( $term_id, 'product_cat' );
        $term_ids[] = $term_id;
        $term_ids = array_filter( array_map( 'intval', $term_ids ) );

        if ( empty( $term_ids ) ) {
            return true;
        }

        $table_lookup = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

        $sql = $wpdb->prepare(
            "SELECT COUNT(DISTINCT l.product_id) 
             FROM {$table_lookup} l
             INNER JOIN {$wpdb->term_relationships} r ON l.product_id = r.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON r.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.taxonomy = 'product_cat' 
               AND tt.term_id IN ($placeholders)
               AND (l.meta_key = %s OR l.meta_key = %s OR l.meta_key = %s)",
            array_merge( $term_ids, array( $field_key, $field_key . '_min', $field_key . '_max' ) )
        );

        $count = intval( $wpdb->get_var( $sql ) );
        return $count > 0;
    }

    /**
     * Filter SQL clauses to inject search logic.
     */
    public static function filter_product_query_clauses( array $clauses, WP_Query $query ): array {
        global $wpdb;

        // Run only on frontend main query for product loops
        if ( is_admin() || ! $query->is_main_query() ) {
            return $clauses;
        }

        if ( ! is_post_type_archive( 'product' ) && ! is_product_category() && ! is_product_tag() && ! is_product_taxonomy() && ! $query->get( 'brz_filter_force' ) && ! is_search() ) {
            return $clauses;
        }

        if ( ! class_exists( 'BRZ_Product_Specs' ) ) {
            return $clauses;
        }

        $fields = BRZ_Product_Specs::get_fields();
        if ( empty( $fields ) ) {
            return $clauses;
        }

        $table = self::table_name();
        $has_filter = false;

        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( 'range' === $type ) {
                $min_val = isset( $_GET[ $key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_max' ] ) ) : '';
                $exact_val = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

                if ( $min_val !== '' || $max_val !== '' || $exact_val !== '' ) {
                    $has_filter = true;
                    $alias      = 'brz_f_' . sanitize_key( $key );

                    // If exact search value is specified (e.g. age = 10, players = 4): Overlap logic
                    if ( $exact_val !== '' ) {
                        $alias_min = $alias . '_min';
                        $alias_max = $alias . '_max';

                        $clauses['join']  .= " INNER JOIN {$table} AS {$alias_min} ON {$wpdb->posts}.ID = {$alias_min}.product_id ";
                        $clauses['where'] .= $wpdb->prepare( " AND {$alias_min}.meta_key = %s AND {$alias_min}.value_num <= %f ", $key . '_min', floatval( $exact_val ) );

                        $clauses['join']  .= " INNER JOIN {$table} AS {$alias_max} ON {$wpdb->posts}.ID = {$alias_max}.product_id ";
                        $clauses['where'] .= $wpdb->prepare( " AND {$alias_max}.meta_key = %s AND {$alias_max}.value_num >= %f ", $key . '_max', floatval( $exact_val ) );
                    } else {
                        // Bounds logic
                        if ( $min_val !== '' ) {
                            $alias_min = $alias . '_min';
                            $clauses['join']  .= " INNER JOIN {$table} AS {$alias_min} ON {$wpdb->posts}.ID = {$alias_min}.product_id ";
                            $clauses['where'] .= $wpdb->prepare( " AND {$alias_min}.meta_key = %s AND {$alias_min}.value_num >= %f ", $key . '_min', floatval( $min_val ) );
                        }
                        if ( $max_val !== '' ) {
                            $alias_max = $alias . '_max';
                            $clauses['join']  .= " INNER JOIN {$table} AS {$alias_max} ON {$wpdb->posts}.ID = {$alias_max}.product_id ";
                            $clauses['where'] .= $wpdb->prepare( " AND {$alias_max}.meta_key = %s AND {$alias_max}.value_num <= %f ", $key . '_max', floatval( $max_val ) );
                        }
                    }
                }
            } elseif ( 'array' === $type ) {
                $val = isset( $_GET[ $key ] ) ? $_GET[ $key ] : '';
                if ( ! empty( $val ) ) {
                    if ( is_string( $val ) ) {
                        $vals = array_map( 'trim', explode( ',', $val ) );
                    } else {
                        $vals = array_map( 'sanitize_text_field', (array) $val );
                    }
                    $vals = array_filter( $vals );

                    if ( ! empty( $vals ) ) {
                        $has_filter = true;
                        $alias      = 'brz_f_' . sanitize_key( $key );

                        $clauses['join'] .= " INNER JOIN {$table} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.product_id ";
                        $placeholders     = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                        $in_clause        = $wpdb->prepare( "{$alias}.value_char IN ($placeholders)", $vals );
                        $clauses['where'] .= $wpdb->prepare( " AND {$alias}.meta_key = %s AND ({$in_clause}) ", $key );
                    }
                }
            } elseif ( 'boolean' === $type ) {
                $val = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
                if ( $val !== '' && $val !== 'all' ) {
                    $has_filter = true;
                    $alias      = 'brz_f_' . sanitize_key( $key );
                    $bool_val   = ( $val === '1' || $val === 'true' || $val === 'yes' ) ? 1 : 0;

                    $clauses['join']  .= " INNER JOIN {$table} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.product_id ";
                    $clauses['where'] .= $wpdb->prepare( " AND {$alias}.meta_key = %s AND {$alias}.value_num = %d ", $key, $bool_val );
                }
            } elseif ( 'integer' === $type || 'decimal' === $type ) {
                $min_val = isset( $_GET[ $key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_max' ] ) ) : '';
                $exact_val = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

                if ( $min_val !== '' || $max_val !== '' || $exact_val !== '' ) {
                    $has_filter = true;
                    $alias      = 'brz_f_' . sanitize_key( $key );

                    $clauses['join']  .= " INNER JOIN {$table} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.product_id ";
                    $clauses['where'] .= $wpdb->prepare( " AND {$alias}.meta_key = %s ", $key );

                    if ( $exact_val !== '' ) {
                        $clauses['where'] .= $wpdb->prepare( " AND {$alias}.value_num = %f ", floatval( $exact_val ) );
                    } else {
                        if ( $min_val !== '' ) {
                            $clauses['where'] .= $wpdb->prepare( " AND {$alias}.value_num >= %f ", floatval( $min_val ) );
                        }
                        if ( $max_val !== '' ) {
                            $clauses['where'] .= $wpdb->prepare( " AND {$alias}.value_num <= %f ", floatval( $max_val ) );
                        }
                    }
                }
            }
        }

        // Add DISTINCT to avoid duplicates if matches are made
        if ( $has_filter ) {
            $clauses['distinct'] = 'DISTINCT';
        }

        return $clauses;
    }

    /**
     * AJAX action to save filters configurations.
     */
    public static function ajax_save_filters_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        if ( ! check_ajax_referer( 'brz_save_filters_settings_nonce', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست معتبر نیست.' ), 403 );
        }

        $opts = get_option( BRZ_OPTION, array() );
        
        $filters_opts = array(
            'container_selector' => sanitize_text_field( isset( $_POST['container_selector'] ) ? $_POST['container_selector'] : '.products-box' ),
            'pagination_selector'=> sanitize_text_field( isset( $_POST['pagination_selector'] ) ? $_POST['pagination_selector'] : '.woocommerce-pagination' ),
            'count_selector'     => sanitize_text_field( isset( $_POST['count_selector'] ) ? $_POST['count_selector'] : '.woocommerce-result-count' ),
            'ajax_enabled'       => isset( $_POST['ajax_enabled'] ) && $_POST['ajax_enabled'] === '1' ? 1 : 0,
            'push_state'         => isset( $_POST['push_state'] ) && $_POST['push_state'] === '1' ? 1 : 0,
        );

        $opts['sidebar_filters'] = $filters_opts;
        update_option( BRZ_OPTION, $opts, false );

        wp_send_json_success( array( 'message' => 'تنظیمات فیلترها با موفقیت ذخیره شد.' ) );
    }

    /**
     * AJAX action to rebuild the lookup table in batches.
     */
    public static function ajax_rebuild_lookup_table(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        if ( ! check_ajax_referer( 'brz_rebuild_filters_lookup_nonce', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست معتبر نیست.' ), 403 );
        }

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $limit  = 50;

        if ( 0 === $offset ) {
            // First run: Clear table
            global $wpdb;
            $wpdb->query( "TRUNCATE TABLE " . self::table_name() );
        }

        // Query products
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
        );

        $products = get_posts( $args );
        $count    = count( $products );

        // Total products count
        $total_query = new WP_Query( array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        $total = $total_query->post_count;

        foreach ( $products as $product_id ) {
            self::update_lookup_table( intval( $product_id ) );
        }

        $new_offset = $offset + $count;
        $finished   = ( $new_offset >= $total || 0 === $count );

        wp_send_json_success( array(
            'offset'   => $new_offset,
            'total'    => $total,
            'finished' => $finished,
            'message'  => sprintf( 'پردازش محصولات: %d از %d', min( $new_offset, $total ), $total ),
        ) );
    }

    /**
     * Render the admin page under Buyruz Settings.
     */
    public static function render_admin_page(): void {
        $opts = get_option( BRZ_OPTION, array() );
        $filters_opts = isset( $opts['sidebar_filters'] ) ? $opts['sidebar_filters'] : array();

        $container_selector  = ! empty( $filters_opts['container_selector'] ) ? $filters_opts['container_selector'] : '.products-box';
        $pagination_selector = ! empty( $filters_opts['pagination_selector'] ) ? $filters_opts['pagination_selector'] : '.woocommerce-pagination';
        $count_selector      = ! empty( $filters_opts['count_selector'] ) ? $filters_opts['count_selector'] : '.woocommerce-result-count';
        $ajax_enabled        = isset( $filters_opts['ajax_enabled'] ) ? (bool) $filters_opts['ajax_enabled'] : true;
        $push_state          = isset( $filters_opts['push_state'] ) ? (bool) $filters_opts['push_state'] : true;
        ?>
        <div class="brz-single-column">
            <!-- Settings form -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3 class="brz-card__title">تنظیمات فیلترهای سایدبار</h3>
                </div>
                <div class="brz-card__body">
                    <form id="brz-filters-settings-form" method="post">
                        <?php wp_nonce_field( 'brz_save_filters_settings_nonce', '_wpnonce' ); ?>
                        
                        <div class="brz-form-group">
                            <label class="brz-label">سلکتور محفظه محصولات (CSS Selector)</label>
                            <input type="text" name="container_selector" value="<?php echo esc_attr( $container_selector ); ?>" class="brz-input" placeholder=".products-box" required />
                            <p class="brz-desc">سلکتور CSS بخش نگهدارنده محصولات در قالب (در قالب باکالا: <code>.products-box</code>).</p>
                        </div>

                        <div class="brz-form-group">
                            <label class="brz-label">سلکتور تعداد نتایج (CSS Selector)</label>
                            <input type="text" name="count_selector" value="<?php echo esc_attr( $count_selector ); ?>" class="brz-input" placeholder=".woocommerce-result-count" required />
                            <p class="brz-desc">سلکتور CSS بخش نمایش تعداد محصولات (در قالب باکالا: <code>.woocommerce-result-count</code>).</p>
                        </div>

                        <div class="brz-form-group">
                            <label class="brz-label">سلکتور صفحه‌بندی (CSS Selector)</label>
                            <input type="text" name="pagination_selector" value="<?php echo esc_attr( $pagination_selector ); ?>" class="brz-input" placeholder=".woocommerce-pagination" required />
                            <p class="brz-desc">سلکتور CSS بخش صفحه‌بندی محصولات (در قالب باکالا: <code>.woocommerce-pagination</code>).</p>
                        </div>

                        <div class="brz-form-group">
                            <label class="brz-checkbox-label">
                                <input type="checkbox" name="ajax_enabled" value="1" <?php checked( $ajax_enabled ); ?> />
                                فعال‌سازی فیلترینگ با AJAX (بدون رفرش صفحه)
                            </label>
                        </div>

                        <div class="brz-form-group">
                            <label class="brz-checkbox-label">
                                <input type="checkbox" name="push_state" value="1" <?php checked( $push_state ); ?> />
                                بروزرسانی آدرس مرورگر (History API) هنگام فیلتر
                            </label>
                        </div>

                        <button type="submit" class="brz-button brz-button--primary">ذخیره تنظیمات فیلتر</button>
                    </form>
                </div>
            </div>

            <!-- Rebuild tool card -->
            <div class="brz-card">
                <div class="brz-card__header">
                    <h3 class="brz-card__title">بازسازی جدول جستجوی فیلترها (Lookup Table)</h3>
                </div>
                <div class="brz-card__body">
                    <p>در صورتی که فیلترها به درستی کار نمی‌کنند یا فیلد جدیدی به Specs محصولات اضافه کرده‌اید، باید کل دیتابیس فیلترها را بازسازی کنید. این کار به صورت بسته‌های ۵۰ تایی و بدون فشار به سرور انجام می‌شود.</p>
                    
                    <div id="brz-rebuild-progress-wrapper" style="display: none; margin: 15px 0;">
                        <div style="background: #f1f1f1; border-radius: 5px; height: 20px; overflow: hidden; position: relative;">
                            <div id="brz-rebuild-progress-bar" style="background: var(--brz-brand-color, #1a73e8); width: 0%; height: 100%; transition: width 0.3s;"></div>
                            <span id="brz-rebuild-progress-text" style="position: absolute; width: 100%; text-align: center; font-size: 11px; font-weight: bold; line-height: 20px; color: #000; left: 0; top: 0;">0%</span>
                        </div>
                        <p id="brz-rebuild-status-message" style="margin-top: 5px; font-size: 12px; color: #555;"></p>
                    </div>

                    <button type="button" id="brz-btn-rebuild-lookup" class="brz-button brz-button--ghost">شروع بازسازی جدول فیلترها</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Save Settings
            $('#brz-filters-settings-form').on('submit', function(e) {
                e.preventDefault();
                const form = $(this);
                const btn = form.find('button[type="submit"]');
                btn.prop('disabled', true).text('در حال ذخیره...');

                const data = form.serialize() + '&action=brz_save_filters_settings';

                $.post(ajaxurl, data, function(res) {
                    btn.prop('disabled', false).text('ذخیره تنظیمات فیلتر');
                    if (res.success) {
                        alert(res.data.message);
                    } else {
                        alert('خطا: ' + (res.data.message || 'مشکلی رخ داده است.'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('ذخیره تنظیمات فیلتر');
                    alert('خطای شبکه.');
                });
            });

            // Rebuild Lookup Table
            $('#brz-btn-rebuild-lookup').on('click', function() {
                const btn = $(this);
                if (!confirm('آیا مایل به بازسازی جدول جستجوی فیلترها هستید؟ این کار ممکن است چند لحظه طول بکشد.')) {
                    return;
                }

                btn.prop('disabled', true).text('در حال آماده‌سازی...');
                const progressWrapper = $('#brz-rebuild-progress-wrapper');
                const progressBar = $('#brz-rebuild-progress-bar');
                const progressText = $('#brz-rebuild-progress-text');
                const statusMsg = $('#brz-rebuild-status-message');

                progressWrapper.show();
                progressBar.css('width', '0%');
                progressText.text('0%');
                statusMsg.text('در حال شروع...');

                function runBatch(offset) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'brz_rebuild_filters_lookup',
                            offset: offset,
                            _wpnonce: '<?php echo esc_js( wp_create_nonce( "brz_rebuild_filters_lookup_nonce" ) ); ?>'
                        },
                        success: function(res) {
                            if (res.success) {
                                const data = res.data;
                                const pct = data.total > 0 ? Math.round((data.offset / data.total) * 100) : 100;
                                
                                progressBar.css('width', pct + '%');
                                progressText.text(pct + '%');
                                statusMsg.text(data.message);

                                if (!data.finished) {
                                    runBatch(data.offset);
                                } else {
                                    btn.prop('disabled', false).text('شروع بازسازی جدول فیلترها');
                                    statusMsg.text('عملیات بازسازی با موفقیت پایان یافت.');
                                    alert('جدول جستجوی فیلترها با موفقیت بازسازی شد.');
                                }
                            } else {
                                btn.prop('disabled', false).text('شروع بازسازی جدول فیلترها');
                                statusMsg.text('خطا: ' + res.data.message);
                                alert('خطا در بازسازی: ' + res.data.message);
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('شروع بازسازی جدول فیلترها');
                            statusMsg.text('خطای ارتباط با سرور.');
                            alert('خطای شبکه.');
                        }
                    });
                }

                runBatch(0);
            });
        });
        </script>
        <?php
    }
}

/**
 * Widget Class definition inside the same file for encapsulation.
 * Configured as a single-spec filter widget instance for maximum integration with classic themes.
 */
class BRZ_Widget_Advanced_Filters extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'brz_advanced_filters',
            'فیلتر پیشرفته بایروز (Specs)',
            array( 
                'classname'   => 'woocommerce widget_layered_nav widget_brz_advanced_filter',
                'description' => 'نمایش و فیلتر مشخصات فنی محصولات (Specs) به صورت جداگانه در سایدبار.' 
            )
        );
    }

    /**
     * Frontend display.
     */
    public function widget( $args, $instance ): void {
        if ( ! is_post_type_archive( 'product' ) && ! is_product_category() && ! is_product_tag() && ! is_product_taxonomy() && ! is_search() ) {
            return;
        }

        if ( ! class_exists( 'BRZ_Product_Specs' ) ) {
            return;
        }

        $field_key = isset( $instance['field_key'] ) ? sanitize_key( $instance['field_key'] ) : '';
        if ( empty( $field_key ) ) {
            return;
        }

        $fields = BRZ_Product_Specs::get_fields();
        $field  = null;
        foreach ( $fields as $f ) {
            if ( $f['key'] === $field_key ) {
                $field = $f;
                break;
            }
        }

        if ( ! $field ) {
            return;
        }

        // Hide filter widget if there are no products in the current category matching this spec
        if ( is_product_category() && ! BRZ_Sidebar_Filters::has_products_with_spec_in_query( $field_key ) ) {
            return;
        }

        $label  = ! empty( $field['label'] ) ? $field['label'] : $field_key;
        $title  = apply_filters( 'widget_title', empty( $instance['title'] ) ? $label : $instance['title'], $instance, $this->id_base );
        $type   = $field['type'];
        $prefix = ! empty( $field['prefix'] ) ? $field['prefix'] . ' ' : '';
        $suffix = ! empty( $field['suffix'] ) ? ' ' . $field['suffix'] : '';

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( ! empty( $title ) ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        $filter_mode = ! empty( $instance['filter_mode'] ) ? $instance['filter_mode'] : 'slider';
        $filter_presets = ! empty( $instance['filter_presets'] ) ? $instance['filter_presets'] : '';
        $filter_step = ! empty( $instance['filter_step'] ) ? intval( $instance['filter_step'] ) : 1;

        echo '<div class="brz-filter-widget-control brz-filter-type-' . esc_attr( $type ) . '" data-key="' . esc_attr( $field_key ) . '" data-filter-mode="' . esc_attr( $filter_mode ) . '">';

        if ( 'range' === $type ) {
            global $wpdb;
            $table = BRZ_Sidebar_Filters::table_name();
            $limits = $wpdb->get_row( $wpdb->prepare(
                "SELECT MIN(value_num) as min_limit, MAX(value_num) as max_limit FROM {$table} WHERE meta_key IN (%s, %s)",
                $field_key . '_min', $field_key . '_max'
            ) );

            $min_limit = ( $limits && $limits->min_limit !== null ) ? intval( $limits->min_limit ) : 0;
            $max_limit = ( $limits && $limits->max_limit !== null ) ? intval( $limits->max_limit ) : 100;
            
            if ( $min_limit === $max_limit ) {
                $max_limit += 10;
            }

            if ( 'slider' === $filter_mode ) {
                $min_val = isset( $_GET[ $field_key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $field_key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_max' ] ) ) : '';
                $curr_min = ( $min_val !== '' ) ? intval( $min_val ) : $min_limit;
                $curr_max = ( $max_val !== '' ) ? intval( $max_val ) : $max_limit;
                ?>
                <div class="brz-range-slider-wrapper" data-min-limit="<?php echo esc_attr( $min_limit ); ?>" data-max-limit="<?php echo esc_attr( $max_limit ); ?>" data-step="<?php echo esc_attr( $filter_step ); ?>" data-prefix="<?php echo esc_attr( trim( $prefix ) ); ?>" data-suffix="<?php echo esc_attr( trim( $suffix ) ); ?>">
                    <div class="brz-range-values">
                        <span class="brz-range-value-min"><?php echo esc_html( $prefix . $curr_min . $suffix ); ?></span>
                        <span class="brz-range-value-separator">تا</span>
                        <span class="brz-range-value-max"><?php echo esc_html( $prefix . $curr_max . $suffix ); ?></span>
                    </div>
                    <div class="brz-range-slider-track-container">
                        <div class="brz-range-slider-track"></div>
                        <input type="range" class="brz-range-input-min" min="<?php echo esc_attr( $min_limit ); ?>" max="<?php echo esc_attr( $max_limit ); ?>" value="<?php echo esc_attr( $curr_min ); ?>" step="<?php echo esc_attr( $filter_step ); ?>" />
                        <input type="range" class="brz-range-input-max" min="<?php echo esc_attr( $min_limit ); ?>" max="<?php echo esc_attr( $max_limit ); ?>" value="<?php echo esc_attr( $curr_max ); ?>" step="<?php echo esc_attr( $filter_step ); ?>" />
                    </div>
                </div>
                <?php
            } elseif ( 'single_value' === $filter_mode ) {
                $curr_val = isset( $_GET[ $field_key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key ] ) ) : '';
                $curr_val_num = ( $curr_val !== '' ) ? intval( $curr_val ) : $min_limit;
                $display_val = ( $curr_val !== '' ) ? $prefix . $curr_val_num . $suffix : 'نمایش همه';
                ?>
                <div class="brz-single-slider-wrapper" data-min-limit="<?php echo esc_attr( $min_limit ); ?>" data-max-limit="<?php echo esc_attr( $max_limit ); ?>" data-step="<?php echo esc_attr( $filter_step ); ?>" data-prefix="<?php echo esc_attr( trim( $prefix ) ); ?>" data-suffix="<?php echo esc_attr( trim( $suffix ) ); ?>">
                    <div class="brz-range-values" style="display: flex; justify-content: space-between; align-items: center;">
                        <span class="brz-range-value-exact"><?php echo esc_html( $display_val ); ?></span>
                        <button type="button" class="brz-range-reset" title="پاک کردن فیلتر" style="background: none; border: none; color: var(--brz-filters-accent, #ff4757); cursor: pointer; font-size: 11px; padding: 2px 5px; <?php echo ( $curr_val !== '' ) ? '' : 'display: none;'; ?>">✕ پاک‌کردن</button>
                    </div>
                    <div class="brz-range-slider-track-container">
                        <div class="brz-range-slider-track"></div>
                        <input type="range" class="brz-range-input-exact" min="<?php echo esc_attr( $min_limit ); ?>" max="<?php echo esc_attr( $max_limit ); ?>" value="<?php echo esc_attr( $curr_val_num ); ?>" step="<?php echo esc_attr( $filter_step ); ?>" data-active="<?php echo ( $curr_val !== '' ) ? '1' : '0'; ?>" />
                    </div>
                </div>
                <?php
            } elseif ( 'chips' === $filter_mode ) {
                $presets = array();
                if ( ! empty( $filter_presets ) ) {
                    $lines = explode( "\n", str_replace( "\r", "", $filter_presets ) );
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( empty( $line ) ) continue;
                        
                        $parts = explode( ':', $line, 2 );
                        if ( count( $parts ) === 2 ) {
                            $label_preset = trim( $parts[0] );
                            $range_preset = trim( $parts[1] );
                        } else {
                            $range_preset = trim( $parts[0] );
                            $label_preset = '';
                        }
                        
                        $min = '';
                        $max = '';
                        if ( strpos( $range_preset, '-' ) !== false ) {
                            $r_parts = explode( '-', $range_preset );
                            $min = isset( $r_parts[0] ) && $r_parts[0] !== '' ? intval( $r_parts[0] ) : '';
                            $max = isset( $r_parts[1] ) && $r_parts[1] !== '' ? intval( $r_parts[1] ) : '';
                        } elseif ( strpos( $range_preset, '+' ) !== false ) {
                            $min = intval( str_replace( '+', '', $range_preset ) );
                            $max = '';
                        } else {
                            $min = intval( $range_preset );
                            $max = intval( $range_preset );
                        }
                        
                        if ( empty( $label_preset ) ) {
                            if ( $min !== '' && $max !== '' ) {
                                $label_preset = $prefix . $min . ' تا ' . $max . $suffix;
                            } elseif ( $min !== '' ) {
                                $label_preset = 'بالای ' . $min . $suffix;
                            } else {
                                $label_preset = 'زیر ' . $max . $suffix;
                            }
                        }
                        
                        $presets[] = array(
                            'label' => $label_preset,
                            'min'   => $min,
                            'max'   => $max,
                        );
                    }
                }

                if ( ! empty( $presets ) ) {
                    echo '<div class="brz-range-chips-list">';
                    foreach ( $presets as $preset ) {
                        $p_min = $preset['min'];
                        $p_max = $preset['max'];
                        
                        $is_active = false;
                        $url_min = isset( $_GET[ $field_key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_min' ] ) ) : '';
                        $url_max = isset( $_GET[ $field_key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_max' ] ) ) : '';
                        
                        if ( $p_min !== '' && $p_max !== '' ) {
                            if ( $url_min !== '' && $url_max !== '' && intval( $url_min ) === intval( $p_min ) && intval( $url_max ) === intval( $p_max ) ) {
                                $is_active = true;
                            }
                        } elseif ( $p_min !== '' ) {
                            if ( $url_min !== '' && intval( $url_min ) === intval( $p_min ) && $url_max === '' ) {
                                $is_active = true;
                            }
                        } elseif ( $p_max !== '' ) {
                            if ( $url_min === '' && $url_max !== '' && intval( $url_max ) === intval( $p_max ) ) {
                                $is_active = true;
                            }
                        }
                        
                        $active_class = $is_active ? 'active' : '';
                        ?>
                        <button type="button" class="brz-range-chip <?php echo $active_class; ?>" data-min="<?php echo esc_attr( $p_min ); ?>" data-max="<?php echo esc_attr( $p_max ); ?>">
                            <?php echo esc_html( $preset['label'] ); ?>
                        </button>
                        <?php
                    }
                    echo '</div>';
                } else {
                    echo '<p class="brz-no-options">بازه پیش‌فرضی تعریف نشده است.</p>';
                }
            } elseif ( 'inputs' === $filter_mode ) {
                $min_val = isset( $_GET[ $field_key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $field_key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_max' ] ) ) : '';
                ?>
                <div class="brz-number-range-inputs">
                    <div class="brz-num-input-wrap">
                        <span class="brz-num-label">از</span>
                        <input type="number" data-suffix="_min" value="<?php echo esc_attr( $min_val ); ?>" step="<?php echo esc_attr( $filter_step ); ?>" placeholder="<?php echo esc_attr( $min_limit ); ?>" class="brz-number-input" />
                        <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                    </div>
                    <div class="brz-num-input-wrap">
                        <span class="brz-num-label">تا</span>
                        <input type="number" data-suffix="_max" value="<?php echo esc_attr( $max_val ); ?>" step="<?php echo esc_attr( $filter_step ); ?>" placeholder="<?php echo esc_attr( $max_limit ); ?>" class="brz-number-input" />
                        <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                    </div>
                </div>
                <?php
            } elseif ( 'dropdown' === $filter_mode ) {
                $min_val = isset( $_GET[ $field_key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $field_key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_max' ] ) ) : '';
                $curr_min = ( $min_val !== '' ) ? intval( $min_val ) : '';
                $curr_max = ( $max_val !== '' ) ? intval( $max_val ) : '';
                ?>
                <div class="brz-range-dropdowns">
                    <div class="brz-dropdown-wrap" style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                        <span class="brz-dropdown-label" style="font-size: 13px; color: var(--brz-filters-text); min-width: 25px;">از:</span>
                        <select class="brz-range-select-min" style="flex-grow: 1; padding: 6px; border: 1px solid var(--brz-filters-border); border-radius: 6px; font-size: 13px; background: none; color: var(--brz-filters-text);">
                            <option value="">همه</option>
                            <?php for ( $i = $min_limit; $i <= $max_limit; $i += $filter_step ) : ?>
                                <option value="<?php echo $i; ?>" <?php selected( $curr_min, $i ); ?>><?php echo $prefix . $i . $suffix; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="brz-dropdown-wrap" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px;">
                        <span class="brz-dropdown-label" style="font-size: 13px; color: var(--brz-filters-text); min-width: 25px;">تا:</span>
                        <select class="brz-range-select-max" style="flex-grow: 1; padding: 6px; border: 1px solid var(--brz-filters-border); border-radius: 6px; font-size: 13px; background: none; color: var(--brz-filters-text);">
                            <option value="">همه</option>
                            <?php for ( $i = $min_limit; $i <= $max_limit; $i += $filter_step ) : ?>
                                <option value="<?php echo $i; ?>" <?php selected( $curr_max, $i ); ?>><?php echo $prefix . $i . $suffix; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <?php
            }
        } elseif ( 'array' === $type ) {
            // Array choices list (chips/checkboxes)
            $options_str = isset( $field['options'] ) ? $field['options'] : '';
            $options = array_map( 'trim', explode( ',', $options_str ) );
            $options = array_filter( $options );

            $selected_options = isset( $_GET[ $field_key ] ) ? $_GET[ $field_key ] : '';
            if ( is_string( $selected_options ) ) {
                $selected_options = array_map( 'trim', explode( ',', $selected_options ) );
            } else {
                $selected_options = array_map( 'sanitize_text_field', (array) $selected_options );
            }

            if ( ! empty( $options ) ) {
                echo '<div class="brz-checkbox-list">';
                foreach ( $options as $option ) {
                    $checked = in_array( $option, $selected_options, true ) ? 'checked' : '';
                    $opt_id = 'brz_filter_' . esc_attr( $field_key ) . '_' . sanitize_title( $option );
                    ?>
                    <label class="brz-checkbox-chip" for="<?php echo esc_attr( $opt_id ); ?>">
                        <input type="checkbox" id="<?php echo esc_attr( $opt_id ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php echo $checked; ?> />
                        <span><?php echo esc_html( $option ); ?></span>
                    </label>
                    <?php
                }
                echo '</div>';
            } else {
                echo '<p class="brz-no-options">گزینه‌ای تعریف نشده است.</p>';
            }
        } elseif ( 'boolean' === $type ) {
            // Boolean switch toggle
            $curr_val = isset( $_GET[ $field_key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key ] ) ) : '';
            $checked = ( $curr_val === '1' || $curr_val === 'true' ) ? 'checked' : '';
            $switch_id = 'brz_filter_' . esc_attr( $field_key );
            ?>
            <div class="brz-switch-wrapper">
                <label class="brz-switch" for="<?php echo esc_attr( $switch_id ); ?>">
                    <input type="checkbox" id="<?php echo esc_attr( $switch_id ); ?>" value="1" <?php echo $checked; ?> />
                    <span class="brz-switch-slider"></span>
                </label>
                <span class="brz-switch-label-text"><?php echo esc_html( $label ); ?></span>
            </div>
            <?php
        } elseif ( 'integer' === $type || 'decimal' === $type ) {
            // Numeric min/max inputs
            $min_val = isset( $_GET[ $field_key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_min' ] ) ) : '';
            $max_val = isset( $_GET[ $field_key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $field_key . '_max' ] ) ) : '';
            $step = ( 'decimal' === $type ) ? '0.1' : '1';
            ?>
            <div class="brz-number-range-inputs">
                <div class="brz-num-input-wrap">
                    <span class="brz-num-label">از</span>
                    <input type="number" data-suffix="_min" value="<?php echo esc_attr( $min_val ); ?>" step="<?php echo esc_attr( $step ); ?>" placeholder="کمترین" class="brz-number-input" />
                    <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                </div>
                <div class="brz-num-input-wrap">
                    <span class="brz-num-label">تا</span>
                    <input type="number" data-suffix="_max" value="<?php echo esc_attr( $max_val ); ?>" step="<?php echo esc_attr( $step ); ?>" placeholder="بیشترین" class="brz-number-input" />
                    <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                </div>
            </div>
            <?php
        }

        echo '</div>'; // brz-filter-widget-control
        echo $args['after_widget'];
    }

    /**
     * Widget options form in admin.
     */
    public function form( $instance ): void {
        if ( ! class_exists( 'BRZ_Product_Specs' ) ) {
            echo '<p>ماژول مشخصات فنی محصول فعال نیست.</p>';
            return;
        }

        $fields = BRZ_Product_Specs::get_fields();
        if ( empty( $fields ) ) {
            echo '<p>هیچ مشخصه فنی تعریف نشده است.</p>';
            return;
        }

        $title = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
        $selected_field = isset( $instance['field_key'] ) ? sanitize_key( $instance['field_key'] ) : '';
        $filter_mode = isset( $instance['filter_mode'] ) ? sanitize_key( $instance['filter_mode'] ) : 'slider';
        $filter_presets = isset( $instance['filter_presets'] ) ? esc_textarea( $instance['filter_presets'] ) : '';
        $filter_step = isset( $instance['filter_step'] ) ? intval( $instance['filter_step'] ) : 1;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">عنوان ویجت (در صورت خالی بودن، برچسب مشخصه استفاده می‌شود):</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'field_key' ) ); ?>">مشخصه فنی مورد فیلتر:</label>
            <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'field_key' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'field_key' ) ); ?>">
                <option value="">-- انتخاب مشخصه --</option>
                <?php foreach ( $fields as $field ) : ?>
                    <option value="<?php echo esc_attr( base64_encode( $field['key'] ) ); ?>" <?php selected( $selected_field, $field['key'] ); ?>>
                        <?php echo esc_html( ! empty( $field['label'] ) ? $field['label'] : $field['key'] ); ?> (<?php echo esc_html( $field['type'] ); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'filter_mode' ) ); ?>">نوع نمایش فیلتر (فقط برای فیلدهای بازه‌ای):</label>
            <select class="widefat brz-widget-filter-mode-select" id="<?php echo esc_attr( $this->get_field_id( 'filter_mode' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'filter_mode' ) ); ?>">
                <option value="slider" <?php selected( $filter_mode, 'slider' ); ?>>اسلایدر محدوده دو زبانه (Dual Slider)</option>
                <option value="single_value" <?php selected( $filter_mode, 'single_value' ); ?>>ارزش تک عددی / انطباق هوشمند (Smart Overlap)</option>
                <option value="chips" <?php selected( $filter_mode, 'chips' ); ?>>دکمه‌های انتخاب سریع بازه (Preset Chips)</option>
                <option value="inputs" <?php selected( $filter_mode, 'inputs' ); ?>>کادرهای عددی «از» و «تا» (Numeric Inputs)</option>
                <option value="dropdown" <?php selected( $filter_mode, 'dropdown' ); ?>>منوهای کشویی «از» و «تا» (Dropdowns)</option>
            </select>
        </p>
        <p class="brz-presets-field-p" style="<?php echo ( $filter_mode === 'chips' ) ? '' : 'display:none;'; ?>">
            <label for="<?php echo esc_attr( $this->get_field_id( 'filter_presets' ) ); ?>">بازه‌های چیپس‌ها (هر بازه در یک خط به فرمت <code>عنوان:بازه</code> مثلاً <code>کودک:0-8</code> یا <code>3-4</code>):</label>
            <textarea class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'filter_presets' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'filter_presets' ) ); ?>" rows="4"><?php echo $filter_presets; ?></textarea>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'filter_step' ) ); ?>">گام تغییرات فیلتر (اسلایدر/دراپ‌دان):</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'filter_step' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'filter_step' ) ); ?>" type="number" step="1" min="1" value="<?php echo $filter_step; ?>" />
        </p>
        <script>
            jQuery(document).ready(function($) {
                $('body').on('change', '.brz-widget-filter-mode-select', function() {
                    var $select = $(this);
                    var $presetsField = $select.closest('p').next('.brz-presets-field-p');
                    if ($select.val() === 'chips') {
                        $presetsField.show();
                    } else {
                        $presetsField.hide();
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Sanitize options.
     */
    public function update( $new_instance, $old_instance ): array {
        $instance = $old_instance;
        $instance['title']          = sanitize_text_field( $new_instance['title'] );
        $instance['filter_mode']    = isset( $new_instance['filter_mode'] ) ? sanitize_key( $new_instance['filter_mode'] ) : 'slider';
        $instance['filter_presets'] = isset( $new_instance['filter_presets'] ) ? sanitize_textarea_field( $new_instance['filter_presets'] ) : '';
        $instance['filter_step']    = isset( $new_instance['filter_step'] ) ? max( 1, intval( $new_instance['filter_step'] ) ) : 1;
        
        $field_key = isset( $new_instance['field_key'] ) ? sanitize_text_field( $new_instance['field_key'] ) : '';
        if ( ! empty( $field_key ) ) {
            $decoded = base64_decode( $field_key, true );
            if ( false !== $decoded && base64_encode( $decoded ) === $field_key ) {
                $instance['field_key'] = sanitize_key( $decoded );
            } else {
                $instance['field_key'] = sanitize_key( $field_key );
            }
        } else {
            $instance['field_key'] = '';
        }

        return $instance;
    }
}
