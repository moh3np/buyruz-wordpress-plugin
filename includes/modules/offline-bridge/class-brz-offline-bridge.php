<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Buyruz Offline Bridge - اعمال آفلاین تغییرات محصولات از Google Sheet
 *
 * وقتی ارسال داده از شیت به سایت ناموفق باشه (مثلاً به دلیل VPN)،
 * تغییرات توی یه JSON ذخیره میشن و از طریق این ماژول اعمال میشن.
 * جایگزین ماژول قدیمی Price Queue با پشتیبانی از فیلدهای عمومی.
 */
class BRZ_Offline_Bridge {

    const NONCE_ACTION = 'brz_offline_bridge_apply';
    const CAPABILITY   = 'manage_woocommerce';
    const MENU_SLUG    = 'buyruz-offline-bridge';
    const MAX_ITEMS    = 5000;

    const SUPPORTED_FIELDS = array(
        'regular_price',
        'sale_price',
        'stock_quantity',
        'stock_status',
        'sku',
    );

    const STOCK_STATUS_VALUES = array( 'instock', 'outofstock', 'onbackorder' );

    /**
     * Stores old product field values before save for change detection.
     *
     * @var array
     */
    private static $old_values = array();

    /**
     * Flag to prevent double-logging when the plugin itself saves a product.
     *
     * @var bool
     */
    private static $skip_hook_logging = false;

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_legacy_redirect' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_log_menu' ), 91 );

        // New AJAX action
        add_action( 'wp_ajax_brz_offline_bridge_apply', array( __CLASS__, 'ajax_apply' ) );

        // Legacy AJAX action for backward compatibility
        add_action( 'wp_ajax_brz_price_queue_apply', array( __CLASS__, 'ajax_apply' ) );

        // WooCommerce product save hooks for change detection
        add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'capture_old_values' ) );
        add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'on_product_save' ) );
        add_action( BRZ_Change_Log::CRON_HOOK, array( 'BRZ_Change_Log', 'handle_cron' ) );

        // Schedule cron for log cleanup
        BRZ_Change_Log::schedule_cron();

        // Unschedule cron on plugin deactivation
        register_deactivation_hook( BRZ_PATH . 'buyruz-settings.php', array( 'BRZ_Change_Log', 'unschedule_cron' ) );
    }

    /**
     * Register admin submenu page under Buyruz.
     */
    public static function register_menu() {
        add_submenu_page(
            'buyruz-dashboard',
            'پل آفلاین',
            '🔗 پل آفلاین',
            self::CAPABILITY,
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Register the Change Log sub-menu page.
     */
    public static function register_log_menu() {
        add_submenu_page(
            'buyruz-dashboard',
            'لاگ تغییرات',
            '📋 لاگ تغییرات',
            self::CAPABILITY,
            'buyruz-module-offline_bridge_log',
            array( __CLASS__, 'render_log_page' )
        );
    }

    /**
     * Render the Change Log sub-page.
     */
    public static function render_log_page() {
        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page = 50;
        $total    = BRZ_Change_Log::count();
        $entries  = BRZ_Change_Log::query( array( 'page' => $page, 'per_page' => $per_page ) );
        $total_pages = ceil( $total / $per_page );
        ?>
        <div class="brz-admin-wrap" dir="rtl">
            <div class="brz-hero">
                <div class="brz-hero__content">
                    <div class="brz-hero__title-row">
                        <div class="brz-hero__breadcrumbs">
                            <span class="brz-hero__plugin-title">تنظیمات بایروز</span>
                            <span class="brz-hero__separator">/</span>
                            <h1>📋 لاگ تغییرات</h1>
                        </div>
                        <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                    </div>
                    <p class="brz-hero__desc" style="margin-top:var(--md-space-sm);">تاریخچه تغییرات فیلدهای محصولات از تمام منابع.</p>
                </div>
            </div>
            <div class="brz-layout brz-layout--single brz-ob-fullwidth">
                <div class="brz-content">
                    <div class="brz-card">
                        <div class="brz-card__header">
                            <h3>لاگ تغییرات (<?php echo esc_html( number_format_i18n( $total ) ); ?> مورد)</h3>
                        </div>
                        <div class="brz-card__body">
                            <?php if ( empty( $entries ) ) : ?>
                                <p style="text-align:center;color:var(--md-on-surface-variant);">هنوز لاگی ثبت نشده.</p>
                            <?php else : ?>
                                <div class="brz-table-responsive">
                                    <table class="widefat">
                                        <thead>
                                            <tr>
                                                <th>اس‌کا‌یو</th>
                                                <th>نام محصول</th>
                                                <th>تغییرات</th>
                                                <th>مبدأ</th>
                                                <th>زمان</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $entries as $entry ) :
                                                $product = wc_get_product( (int) $entry->product_id );
                                                $product_name = $product ? $product->get_name() : '-';
                                                $product_sku  = $product && $product->get_sku() ? $product->get_sku() : '-';
                                                $product_url  = $product ? get_permalink( $product->get_id() ) : '';

                                                // Convert comma-separated field names to Persian labels
                                                $fields = explode( ',', $entry->field_names );
                                                $labels = array();
                                                foreach ( $fields as $f ) {
                                                    $labels[] = BRZ_Change_Log::get_field_label( trim( $f ) );
                                                }
                                                $labels = array_unique( $labels );
                                                $changes_text = implode( '، ', $labels );
                                            ?>
                                            <tr>
                                                <td>
                                                    <?php if ( $product_url ) : ?>
                                                        <a class="brz-ob-clean-link" href="<?php echo esc_url( $product_url ); ?>" target="_blank"><?php echo esc_html( $product_sku ); ?></a>
                                                    <?php else : ?>
                                                        <?php echo esc_html( $product_sku ); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( $product_url ) : ?>
                                                        <a class="brz-ob-clean-link" href="<?php echo esc_url( $product_url ); ?>" target="_blank"><?php echo esc_html( $product_name ); ?></a>
                                                    <?php else : ?>
                                                        <?php echo esc_html( $product_name ); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html( $changes_text ); ?></td>
                                                <td><?php echo esc_html( $entry->source ); ?></td>
                                                <td dir="ltr"><?php echo esc_html( $entry->created_at ); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ( $total_pages > 1 ) : ?>
                                    <div style="margin-top:var(--md-space-lg);display:flex;justify-content:center;gap:var(--md-space-sm);">
                                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
                                            $url = admin_url( 'admin.php?page=buyruz-module-offline_bridge_log&paged=' . $i );
                                            $is_current = ( $i === $page );
                                        ?>
                                            <?php if ( $is_current ) : ?>
                                                <span class="brz-button" style="opacity:0.5;pointer-events:none;"><?php echo $i; ?></span>
                                            <?php else : ?>
                                                <a class="brz-button brz-button--outlined" href="<?php echo esc_url( $url ); ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Conditionally enqueue CSS/JS only on the module page.
     *
     * @param string $hook The current admin page hook suffix.
     */
    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'buyruz-module-offline_bridge' ) === false && strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style(
            'brz-offline-bridge',
            BRZ_URL . 'assets/admin/offline-bridge.css',
            array( 'brz-settings-admin' ),
            BRZ_VERSION
        );

        wp_enqueue_script(
            'brz-offline-bridge',
            BRZ_URL . 'assets/admin/offline-bridge.js',
            array(),
            BRZ_VERSION,
            true
        );

        wp_localize_script(
            'brz-offline-bridge',
            'brzOfflineBridge',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
                'maxItems' => self::MAX_ITEMS,
                'i18n'     => array(
                    'emptyInput'    => 'کادر خالی است.',
                    'invalidJson'   => 'JSON نامعتبر',
                    'invalidArray'  => 'آرایه خالی یا نامعتبر.',
                    'maxExceeded'   => 'حداکثر ۵۰۰ آیتم مجاز است.',
                    'networkError'  => 'خطای شبکه',
                    'processing'    => 'در حال پردازش %d از %d...',
                    'success'       => '%d مورد با موفقیت اعمال شد.',
                    'partial'       => '%d موفق، %d ناموفق.',
                    'statusSuccess' => 'موفق',
                    'statusFailed'  => 'ناموفق',
                ),
            )
        );
    }

    /**
     * Handle legacy redirect from old price-queue slug to new offline-bridge slug.
     * Issues a 301 redirect preserving query parameters.
     */
    public static function handle_legacy_redirect() {
        if ( ! isset( $_GET['page'] ) ) {
            return;
        }

        $page = $_GET['page'];

        // Redirect old price-queue slug and old offline-bridge slug to the module settings page
        if ( $page !== 'buyruz-price-queue' && $page !== 'buyruz-offline-bridge' ) {
            return;
        }

        $params = $_GET;
        $params['page'] = 'buyruz-module-offline_bridge';

        $redirect_url = admin_url( 'admin.php?' . http_build_query( $params ) );

        wp_redirect( $redirect_url, 301 );
        exit;
    }

    /**
     * Render the admin page.
     */
    public static function render_page() {
        ?>
        <div id="brz-snackbar" class="brz-snackbar" aria-live="polite"></div>
        <div class="brz-layout brz-layout--single brz-ob-fullwidth">
            <div class="brz-content">
                <?php self::render_input_card(); ?>
                <div id="brz-ob-stats" class="brz-ob-stats" style="display:none;"></div>
                <div id="brz-ob-results" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: apply offline bridge items.
     */
    public static function ajax_apply() {
        // Accept both new and legacy nonces for backward compatibility.
        $nonce = isset( $_REQUEST['_nonce'] ) ? sanitize_text_field( $_REQUEST['_nonce'] ) : '';
        $valid = wp_verify_nonce( $nonce, self::NONCE_ACTION )
              || wp_verify_nonce( $nonce, 'brz_price_queue_apply' );

        if ( ! $valid ) {
            wp_send_json_error( array( 'message' => 'نانس نامعتبر' ), 403 );
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
        $items     = json_decode( $raw_items, true );

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( array( 'message' => 'آرایه خالی یا نامعتبر.' ), 400 );
        }

        if ( count( $items ) > self::MAX_ITEMS ) {
            wp_send_json_error( array( 'message' => 'حداکثر ۵۰۰ آیتم مجاز است.' ), 400 );
        }

        $results       = array();
        $success_count = 0;
        $failed_count  = 0;

        foreach ( $items as $item ) {
            $result = self::apply_item( $item );
            $results[] = $result;

            if ( ! empty( $result['success'] ) ) {
                $success_count++;
            } else {
                $failed_count++;
            }
        }

        // Collect session log entries for the log preview
        $log_entries = array();
        foreach ( $results as $result ) {
            if ( ! empty( $result['success'] ) && ! empty( $result['fields_applied'] ) ) {
                foreach ( $result['fields_applied'] as $label ) {
                    $log_entries[] = array(
                        'product_id'   => $result['id'],
                        'sku'          => $result['sku'],
                        'url'          => $result['url'],
                        'product_name' => $result['product_name'],
                        'field_name'   => $label,
                        'source'       => BRZ_Change_Log::SOURCE_PLUGIN,
                        'created_at'   => current_time( 'mysql', true ),
                    );
                }
            }
        }

        wp_send_json_success( array(
            'total'         => count( $results ),
            'success_count' => $success_count,
            'failed_count'  => $failed_count,
            'results'       => $results,
            'log_entries'   => $log_entries,
        ) );
    }

    /**
     * Apply a single queue item to its product.
     *
     * @param array $item The queue item with id and field values.
     * @return array Result array with id, product_name, fields_applied, success, warnings, error.
     */
    private static function apply_item( array $item ): array {
        // Validate ID field: must exist, be numeric, and > 0.
        if ( ! isset( $item['id'] ) || ! is_numeric( $item['id'] ) || (int) $item['id'] <= 0 ) {
            return array(
                'id'             => isset( $item['id'] ) ? $item['id'] : null,
                'sku'            => '-',
                'url'            => '',
                'product_name'   => '-',
                'fields_applied' => array(),
                'success'        => false,
                'warnings'       => array(),
                'error'          => 'آیدی محصول نامعتبر',
            );
        }

        $product_id   = (int) $item['id'];
        $product      = wc_get_product( $product_id );
        $product_name = $product ? $product->get_name() : '-';
        $product_sku  = $product && $product->get_sku() ? $product->get_sku() : '-';
        $product_url  = $product ? get_permalink( $product_id ) : '';

        if ( ! $product ) {
            return array(
                'id'             => $product_id,
                'sku'            => $product_sku,
                'url'            => $product_url,
                'product_name'   => $product_name,
                'fields_applied' => array(),
                'success'        => false,
                'warnings'       => array(),
                'error'          => 'محصول یافت نشد',
            );
        }

        $fields_applied = array();
        $warnings       = array();

        foreach ( $item as $field => $value ) {
            if ( 'id' === $field ) {
                continue;
            }

            if ( in_array( $field, self::SUPPORTED_FIELDS, true ) ) {
                $warning = self::apply_field( $product, $field, $value );

                if ( null === $warning ) {
                    $fields_applied[] = BRZ_Change_Log::get_field_label( $field );
                    // Record to change log
                    BRZ_Change_Log::insert( $product_id, $field, $value, BRZ_Change_Log::SOURCE_PLUGIN );
                } else {
                    $warnings[] = $warning;
                }
            } else {
                $warnings[] = "فیلد '{$field}' پشتیبانی نمی‌شود و نادیده گرفته شد";
            }
        }

        // Save product only if at least one field was applied.
        if ( ! empty( $fields_applied ) ) {
            try {
                self::$skip_hook_logging = true;
                $product->save();
                self::$skip_hook_logging = false;
            } catch ( \Exception $e ) {
                self::$skip_hook_logging = false;
                return array(
                    'id'             => $product_id,
                    'sku'            => $product_sku,
                    'url'            => $product_url,
                    'product_name'   => $product_name,
                    'fields_applied' => array(),
                    'success'        => false,
                    'warnings'       => $warnings,
                    'error'          => $e->getMessage(),
                );
            }
        }

        return array(
            'id'             => $product_id,
            'sku'            => $product_sku,
            'url'            => $product_url,
            'product_name'   => $product_name,
            'fields_applied' => $fields_applied,
            'success'        => true,
            'warnings'       => $warnings,
            'error'          => '',
        );
    }

    /**
     * Apply a single field value to a WooCommerce product.
     *
     * @param \WC_Product $product The WooCommerce product instance.
     * @param string      $field   The field name to apply.
     * @param mixed       $value   The value to set.
     * @return string|null Warning message if field is invalid, null on success.
     */
    private static function apply_field( \WC_Product $product, string $field, $value ): ?string {
        switch ( $field ) {
            case 'regular_price':
                $product->set_regular_price( sanitize_text_field( $value ) );
                return null;

            case 'sale_price':
                $product->set_sale_price( sanitize_text_field( $value ) );
                return null;

            case 'stock_quantity':
                $product->set_stock_quantity( (int) $value );
                return null;

            case 'stock_status':
                if ( ! in_array( $value, self::STOCK_STATUS_VALUES, true ) ) {
                    return "مقدار stock_status نامعتبر: {$value}";
                }
                $product->set_stock_status( $value );
                return null;

            case 'sku':
                $product->set_sku( sanitize_text_field( $value ) );
                return null;

            default:
                return "فیلد '{$field}' پشتیبانی نمی‌شود و نادیده گرفته شد";
        }
    }



    /**
     * Render the JSON input card.
     */
    private static function render_input_card(): void {
        ?>
        <div class="brz-card">
            <div class="brz-card__header">
                <h3>ورودی JSON</h3>
                <p>داده‌های صف را از Google Sheet کپی کرده و در کادر زیر paste کنید، سپس دکمه «اعمال» را بزنید.</p>
            </div>
            <div class="brz-card__body">
                <textarea
                    id="brz-ob-input"
                    class="brz-ob-textarea"
                    rows="10"
                    placeholder='[{"id": 123, "regular_price": "500000", "stock_quantity": 10}, {"id": 456, "sku": "BRZ-001", "sale_price": "450000"}]'
                ></textarea>
                <div id="brz-ob-error" class="brz-ob-inline-error" style="display:none;"></div>
                <div id="brz-ob-progress-container" class="brz-ob-progress-container" style="display:none;">
                    <div class="brz-ob-progress-info">
                        <span id="brz-ob-progress-text" class="brz-ob-progress-text">در حال پردازش...</span>
                        <span id="brz-ob-progress-percent" class="brz-ob-progress-percent">0%</span>
                    </div>
                    <div class="brz-ob-progress-bar-bg">
                        <div id="brz-ob-progress-bar" class="brz-ob-progress-bar" style="width: 0%;"></div>
                    </div>
                </div>
                <div class="brz-ob-actions">
                    <button type="button" id="brz-ob-apply" class="brz-button brz-button--primary">🔗 اعمال تغییرات</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Capture old product field values before save for change detection.
     *
     * @param \WC_Product $product The product being saved.
     */
    public static function capture_old_values( $product ) {
        if ( self::$skip_hook_logging ) {
            return;
        }
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        $id = $product->get_id();
        if ( ! $id ) {
            return;
        }
        self::$old_values[ $id ] = array(
            'regular_price'  => $product->get_regular_price( 'edit' ),
            'sale_price'     => $product->get_sale_price( 'edit' ),
            'stock_quantity' => $product->get_stock_quantity( 'edit' ),
            'stock_status'   => $product->get_stock_status( 'edit' ),
            'sku'            => $product->get_sku( 'edit' ),
        );
    }

    /**
     * Detect and log product field changes after save.
     *
     * @param \WC_Product $product The product that was saved.
     */
    public static function on_product_save( $product ) {
        if ( self::$skip_hook_logging ) {
            return;
        }
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        $id = $product->get_id();
        if ( ! $id || ! isset( self::$old_values[ $id ] ) ) {
            return;
        }

        // Detect source
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $source = BRZ_Change_Log::SOURCE_API;
        } else {
            $source = BRZ_Change_Log::SOURCE_ADMIN;
        }

        $old = self::$old_values[ $id ];
        $new_values = array(
            'regular_price'  => $product->get_regular_price( 'edit' ),
            'sale_price'     => $product->get_sale_price( 'edit' ),
            'stock_quantity' => $product->get_stock_quantity( 'edit' ),
            'stock_status'   => $product->get_stock_status( 'edit' ),
            'sku'            => $product->get_sku( 'edit' ),
        );

        foreach ( $new_values as $field => $new_val ) {
            $old_val = isset( $old[ $field ] ) ? $old[ $field ] : null;
            if ( (string) $old_val !== (string) $new_val ) {
                BRZ_Change_Log::insert( $id, $field, $new_val, $source );
            }
        }

        unset( self::$old_values[ $id ] );
    }
}
