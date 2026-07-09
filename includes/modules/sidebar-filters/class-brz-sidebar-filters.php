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

            // Pass localizations and configurations to JS
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
                    // product_min_val <= exact_val AND product_max_val >= exact_val
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
 */
class BRZ_Widget_Advanced_Filters extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'brz_advanced_filters',
            'فیلتر پیشرفته بایروز (Specs)',
            array( 'description' => 'نمایش فیلترهای مشخصات فنی داینامیک در سایدبار محصولات آرشیو.' )
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

        $fields = BRZ_Product_Specs::get_fields();
        if ( empty( $fields ) ) {
            return;
        }

        $selected_fields = isset( $instance['fields'] ) && is_array( $instance['fields'] ) ? $instance['fields'] : array();
        if ( empty( $selected_fields ) ) {
            return;
        }

        $title = apply_filters( 'widget_title', empty( $instance['title'] ) ? 'فیلتر محصولات' : $instance['title'], $instance, $this->id_base );

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( ! empty( $title ) ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        echo '<form class="brz-sidebar-filters-form" method="get" action="">';

        // Keep existing URL parameters (e.g. orderby, search query) except our filters
        foreach ( $_GET as $qkey => $qval ) {
            if ( is_array( $qval ) ) {
                foreach ( $qval as $inner_val ) {
                    echo '<input type="hidden" name="' . esc_attr( $qkey ) . '[]" value="' . esc_attr( $inner_val ) . '" />';
                }
            } else {
                // Skip our specific filter fields
                $is_our_filter = false;
                foreach ( $fields as $f ) {
                    if ( $qkey === $f['key'] || $qkey === $f['key'] . '_min' || $qkey === $f['key'] . '_max' ) {
                        $is_our_filter = true;
                        break;
                    }
                }
                if ( ! $is_our_filter && $qkey !== 'paged' ) {
                    echo '<input type="hidden" name="' . esc_attr( $qkey ) . '" value="' . esc_attr( $qval ) . '" />';
                }
            }
        }

        echo '<div class="brz-filters-container">';

        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( ! in_array( $key, $selected_fields, true ) ) {
                continue;
            }

            $label  = ! empty( $field['label'] ) ? $field['label'] : $key;
            $prefix = ! empty( $field['prefix'] ) ? $field['prefix'] . ' ' : '';
            $suffix = ! empty( $field['suffix'] ) ? ' ' . $field['suffix'] : '';

            echo '<div class="brz-filter-group brz-filter-type-' . esc_attr( $type ) . '" data-key="' . esc_attr( $key ) . '">';
            echo '<h4 class="brz-filter-group-title">' . esc_html( $label ) . '</h4>';
            echo '<div class="brz-filter-group-content">';

            if ( 'range' === $type ) {
                // Range dual slider
                $min_val = isset( $_GET[ $key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_max' ] ) ) : '';

                // Get global range limits from lookup table to set min/max attributes
                global $wpdb;
                $table = BRZ_Sidebar_Filters::table_name();
                $limits = $wpdb->get_row( $wpdb->prepare(
                    "SELECT MIN(value_num) as min_limit, MAX(value_num) as max_limit FROM {$table} WHERE meta_key IN (%s, %s)",
                    $key . '_min', $key . '_max'
                ) );

                $min_limit = ( $limits && $limits->min_limit !== null ) ? intval( $limits->min_limit ) : 0;
                $max_limit = ( $limits && $limits->max_limit !== null ) ? intval( $limits->max_limit ) : 100;
                
                // Fallbacks if limits empty
                if ( $min_limit === $max_limit ) {
                    $max_limit += 10;
                }

                $curr_min = ( $min_val !== '' ) ? intval( $min_val ) : $min_limit;
                $curr_max = ( $max_val !== '' ) ? intval( $max_val ) : $max_limit;

                ?>
                <div class="brz-range-slider-wrapper" data-min-limit="<?php echo esc_attr( $min_limit ); ?>" data-max-limit="<?php echo esc_attr( $max_limit ); ?>">
                    <div class="brz-range-values">
                        <span class="brz-range-value-min"><?php echo esc_html( $prefix . $curr_min . $suffix ); ?></span>
                        <span class="brz-range-value-separator">تا</span>
                        <span class="brz-range-value-max"><?php echo esc_html( $prefix . $curr_max . $suffix ); ?></span>
                    </div>
                    <div class="brz-range-slider-track-container">
                        <div class="brz-range-slider-track"></div>
                        <input type="range" name="<?php echo esc_attr( $key ); ?>_min" class="brz-range-input-min" min="<?php echo esc_attr( $min_limit ); ?>" max="<?php echo esc_attr( $max_limit ); ?>" value="<?php echo esc_attr( $curr_min ); ?>" step="1" />
                        <input type="range" name="<?php echo esc_attr( $key ); ?>_max" class="brz-range-input-max" min="<?php echo esc_attr( $min_limit ); ?>" max="<?php echo esc_attr( $max_limit ); ?>" value="<?php echo esc_attr( $curr_max ); ?>" step="1" />
                    </div>
                </div>
                <?php
            } elseif ( 'array' === $type ) {
                // Array choices list
                $options_str = isset( $field['options'] ) ? $field['options'] : '';
                $options = array_map( 'trim', explode( ',', $options_str ) );
                $options = array_filter( $options );

                $selected_options = isset( $_GET[ $key ] ) ? $_GET[ $key ] : '';
                if ( is_string( $selected_options ) ) {
                    $selected_options = array_map( 'trim', explode( ',', $selected_options ) );
                } else {
                    $selected_options = array_map( 'sanitize_text_field', (array) $selected_options );
                }

                if ( ! empty( $options ) ) {
                    echo '<div class="brz-checkbox-list">';
                    foreach ( $options as $option ) {
                        $checked = in_array( $option, $selected_options, true ) ? 'checked' : '';
                        $opt_id = 'brz_filter_' . esc_attr( $key ) . '_' . sanitize_title( $option );
                        ?>
                        <label class="brz-checkbox-chip" for="<?php echo esc_attr( $opt_id ); ?>">
                            <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[]" id="<?php echo esc_attr( $opt_id ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php echo $checked; ?> />
                            <span><?php echo esc_html( $option ); ?></span>
                        </label>
                        <?php
                    }
                    echo '</div>';
                } else {
                    echo '<p class="brz-no-options">گزینه‌ای تعریف نشده است.</p>';
                }
            } elseif ( 'boolean' === $type ) {
                // Boolean switch
                $curr_val = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
                $checked = ( $curr_val === '1' || $curr_val === 'true' ) ? 'checked' : '';
                $switch_id = 'brz_filter_' . esc_attr( $key );
                ?>
                <div class="brz-switch-wrapper">
                    <label class="brz-switch" for="<?php echo esc_attr( $switch_id ); ?>">
                        <input type="checkbox" name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $switch_id ); ?>" value="1" <?php echo $checked; ?> />
                        <span class="brz-switch-slider"></span>
                    </label>
                    <span class="brz-switch-label-text"><?php echo esc_html( $label ); ?></span>
                </div>
                <?php
            } elseif ( 'integer' === $type || 'decimal' === $type ) {
                // Min/Max Inputs
                $min_val = isset( $_GET[ $key . '_min' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_min' ] ) ) : '';
                $max_val = isset( $_GET[ $key . '_max' ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key . '_max' ] ) ) : '';
                $step = ( 'decimal' === $type ) ? '0.1' : '1';
                ?>
                <div class="brz-number-range-inputs">
                    <div class="brz-num-input-wrap">
                        <span class="brz-num-label">از</span>
                        <input type="number" name="<?php echo esc_attr( $key ); ?>_min" value="<?php echo esc_attr( $min_val ); ?>" step="<?php echo esc_attr( $step ); ?>" placeholder="کمترین" class="brz-number-input" />
                        <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                    </div>
                    <div class="brz-num-input-wrap">
                        <span class="brz-num-label">تا</span>
                        <input type="number" name="<?php echo esc_attr( $key ); ?>_max" value="<?php echo esc_attr( $max_val ); ?>" step="<?php echo esc_attr( $step ); ?>" placeholder="بیشترین" class="brz-number-input" />
                        <span class="brz-num-suffix"><?php echo esc_html( $suffix ); ?></span>
                    </div>
                </div>
                <?php
            }

            echo '</div>';
            echo '</div>';
        }

        echo '</div>'; // brz-filters-container

        // Submit/Reset buttons
        echo '<div class="brz-filter-actions">';
        echo '<button type="submit" class="brz-filter-submit-btn">اعمال فیلتر</button>';
        
        // Remove filters link
        $archive_url = '';
        if ( is_product_category() ) {
            $archive_url = get_term_link( get_queried_object_id(), 'product_cat' );
        } elseif ( is_product_tag() ) {
            $archive_url = get_term_link( get_queried_object_id(), 'product_tag' );
        } else {
            $archive_url = get_post_type_archive_link( 'product' );
        }
        if ( is_wp_error( $archive_url ) || ! is_string( $archive_url ) ) {
            $archive_url = home_url( $_SERVER['REQUEST_URI'] );
            $archive_url = strtok( $archive_url, '?' );
        }

        echo '<a href="' . esc_url( $archive_url ) . '" class="brz-filter-reset-btn">پاک کردن فیلترها</a>';
        echo '</div>';

        echo '</form>';
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

        $title = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : 'فیلتر محصولات';
        $selected_fields = isset( $instance['fields'] ) && is_array( $instance['fields'] ) ? $instance['fields'] : array();
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">عنوان ویجت:</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <p><strong>فیلترهای مورد نمایش:</strong></p>
        <?php foreach ( $fields as $field ) : ?>
            <p>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'fields' ) ); ?>[]" value="<?php echo esc_attr( $field['key'] ); ?>" <?php checked( in_array( $field['key'], $selected_fields, true ) ); ?> />
                    <?php echo esc_html( ! empty( $field['label'] ) ? $field['label'] : $field['key'] ); ?> 
                    <small style="color: #888;">(<?php echo esc_html( $field['type'] ); ?>)</small>
                </label>
            </p>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Sanitize options.
     */
    public function update( $new_instance, $old_instance ): array {
        $instance = $old_instance;
        $instance['title']  = sanitize_text_field( $new_instance['title'] );
        $instance['fields'] = isset( $new_instance['fields'] ) && is_array( $new_instance['fields'] ) ? array_map( 'sanitize_key', $new_instance['fields'] ) : array();
        return $instance;
    }
}
