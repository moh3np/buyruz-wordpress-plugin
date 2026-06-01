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
    const MAX_ITEMS    = 500;

    const SUPPORTED_FIELDS = array(
        'regular_price',
        'sale_price',
        'stock_quantity',
        'stock_status',
        'sku',
    );

    const STOCK_STATUS_VALUES = array( 'instock', 'outofstock', 'onbackorder' );

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_legacy_redirect' ) );

        // New AJAX action
        add_action( 'wp_ajax_brz_offline_bridge_apply', array( __CLASS__, 'ajax_apply' ) );

        // Legacy AJAX action for backward compatibility
        add_action( 'wp_ajax_brz_price_queue_apply', array( __CLASS__, 'ajax_apply' ) );
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
        <div class="brz-admin-wrap" dir="rtl">
            <div id="brz-snackbar" class="brz-snackbar" aria-live="polite"></div>
            <?php self::render_hero(); ?>
            <div class="brz-layout brz-layout--single">
                <div class="brz-content">
                    <?php self::render_input_card(); ?>
                    <div id="brz-ob-stats" class="brz-ob-stats" style="display:none;"></div>
                    <div id="brz-ob-results" style="display:none;"></div>
                </div>
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

        wp_send_json_success( array(
            'total'         => count( $results ),
            'success_count' => $success_count,
            'failed_count'  => $failed_count,
            'results'       => $results,
        ) );
    }

    /**
     * Apply a single queue item to its product.
     *
     * @param array $item The queue item with id and field values.
     * @return array Result array with id, fields_applied, success, warnings, error.
     */
    private static function apply_item( array $item ): array {
        // Validate ID field: must exist, be numeric, and > 0.
        if ( ! isset( $item['id'] ) || ! is_numeric( $item['id'] ) || (int) $item['id'] <= 0 ) {
            return array(
                'id'             => isset( $item['id'] ) ? $item['id'] : null,
                'fields_applied' => array(),
                'success'        => false,
                'warnings'       => array(),
                'error'          => 'آیدی محصول نامعتبر',
            );
        }

        $product_id = (int) $item['id'];
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            return array(
                'id'             => $product_id,
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
                    $fields_applied[] = $field;
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
                $product->save();
            } catch ( \Exception $e ) {
                return array(
                    'id'             => $product_id,
                    'fields_applied' => array(),
                    'success'        => false,
                    'warnings'       => $warnings,
                    'error'          => $e->getMessage(),
                );
            }
        }

        return array(
            'id'             => $product_id,
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
     * Render the hero section of the page.
     */
    private static function render_hero(): void {
        ?>
        <div class="brz-hero">
            <div class="brz-hero__content">
                <div class="brz-hero__title-row">
                    <h1>🔗 پل آفلاین</h1>
                    <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                </div>
                <p>اعمال تغییرات محصولات از Google Sheet — وقتی ارسال مستقیم ممکن نیست.</p>
            </div>
        </div>
        <?php
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
                <div id="brz-ob-progress" class="brz-ob-progress" style="display:none;"></div>
                <div class="brz-ob-actions">
                    <button type="button" id="brz-ob-apply" class="brz-button brz-button--primary">🔗 اعمال تغییرات</button>
                </div>
            </div>
        </div>
        <?php
    }
}
