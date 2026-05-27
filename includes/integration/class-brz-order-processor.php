<?php
/**
 * 📦 Order Processor Class
 * کلاس پردازش سفارشات برای یکپارچگی با گوگل شیت و تاپین
 * 
 * REST Endpoint: POST /buyruz/v1/order/process
 * 
 * ویژگی‌های کلیدی:
 * - پشتیبانی از بارکد دستی (manual_barcode)
 * - منطق Retry با 3 تلاش برای اتصال به تاپین
 * - مدیریت شکست بدون خطای 500 (Graceful Failure)
 * 
 * @package Buyruz
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BRZ_Order_Processor {

    /**
     * API Namespace
     */
    const NAMESPACE = 'buyruz/v1';

    /**
     * Route
     */
    const ROUTE = '/order/process';

    /**
     * حداکثر تعداد تلاش مجدد برای تاپین
     */
    const MAX_TAPIN_RETRIES = 3;

    /**
     * Initialize
     */
    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * ثبت مسیر REST API
     */
    public static function register_routes() {
        register_rest_route( self::NAMESPACE, self::ROUTE, [
            'methods'             => WP_REST_Server::CREATABLE, // POST
            'callback'            => [ __CLASS__, 'process_order' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
            'args'                => self::get_endpoint_args(),
        ] );
        
        // Endpoint موجودی تاپین
        register_rest_route( 'buyruz/v1', '/tapin/balance', [
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [ __CLASS__, 'get_balance' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
        ] );
    }
    
    /**
     * دریافت موجودی تاپین (Endpoint)
     * @return WP_REST_Response
     */
    public static function get_balance(): WP_REST_Response {
        $balance = self::get_tapin_balance();
        
        return new WP_REST_Response( [
            'success' => $balance !== null,
            'balance' => $balance,
            'message' => $balance !== null ? 'موجودی تاپین دریافت شد.' : 'خطا در دریافت موجودی.',
        ], 200 );
    }

    /**
     * آرگومان‌های endpoint
     * @return array
     */
    public static function get_endpoint_args(): array {
        return [
            'order_id' => [
                'required'          => true,
                'type'              => 'integer',
                'description'       => 'شماره سفارش',
                'validate_callback' => function( $value ) {
                    return is_numeric( $value ) && $value > 0;
                },
            ],
            'weight' => [
                'required'    => false,
                'type'        => 'number',
                'default'     => 0,
                'description' => 'وزن سفارش به گرم',
            ],
            'shipment_type' => [
                'required'    => false,
                'type'        => 'integer',
                'default'     => 1,
                'description' => 'نوع مرسوله: 1=عادی, 2=شکستنی, 3=مایعات',
                'enum'        => [ 1, 2, 3 ],
            ],
            'box_size' => [
                'required'    => false,
                'type'        => 'integer',
                'default'     => 3,
                'description' => 'سایز جعبه: 1-10',
                'minimum'     => 1,
                'maximum'     => 10,
            ],
            'manual_barcode' => [
                'required'    => false,
                'type'        => 'string',
                'default'     => '',
                'description' => 'بارکد دستی (وارد شده توسط کاربر از باجه پست)',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'required'    => false,
                'type'        => 'string',
                'default'     => '',
                'description' => 'وضعیت جدید سفارش',
            ],
        ];
    }

    /**
     * بررسی مجوز دسترسی
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_permission( WP_REST_Request $request ) {
        // بررسی API Key اختصاصی Buyruz
        $api_key = $request->get_header( 'X-Buyruz-API-Key' );
        $stored_key = get_option( 'buyruz_api_key', '' );
        
        if ( ! empty( $stored_key ) && $api_key === $stored_key ) {
            return true;
        }
        
        // بررسی مجوز کاربر لاگین شده
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }
        
        // بررسی Basic Auth (WooCommerce credentials)
        $auth_header = $request->get_header( 'Authorization' );
        if ( $auth_header && strpos( $auth_header, 'Basic ' ) === 0 ) {
            $encoded = substr( $auth_header, 6 );
            $decoded = base64_decode( $encoded );
            
            if ( $decoded && strpos( $decoded, ':' ) !== false ) {
                list( $consumer_key, $consumer_secret ) = explode( ':', $decoded, 2 );
                
                // بررسی کلید WooCommerce API
                global $wpdb;
                $key = $wpdb->get_row( $wpdb->prepare(
                    "SELECT key_id, user_id, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                    wc_api_hash( $consumer_key )
                ) );
                
                if ( $key && in_array( $key->permissions, [ 'read_write', 'write' ] ) ) {
                    return true;
                }
            }
        }
        
        return new WP_Error(
            'unauthorized',
            'دسترسی غیرمجاز. لطفاً با API Key یا Basic Auth احراز هویت کنید.',
            [ 'status' => 401 ]
        );
    }

    /**
     * پردازش سفارش
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function process_order( WP_REST_Request $request ): WP_REST_Response {
        $order_id       = absint( $request->get_param( 'order_id' ) );
        $weight         = floatval( $request->get_param( 'weight' ) );
        $shipment_type  = absint( $request->get_param( 'shipment_type' ) );
        $box_size       = absint( $request->get_param( 'box_size' ) );
        $manual_barcode = sanitize_text_field( $request->get_param( 'manual_barcode' ) );
        $new_status     = sanitize_text_field( $request->get_param( 'status' ) );
        
        // دریافت سفارش
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'سفارش با شماره ' . $order_id . ' پیدا نشد.',
            ], 404 );
        }
        
        // ذخیره متاهای تاپین
        if ( $weight > 0 ) {
            $order->update_meta_data( 'tapin_weight', $weight );
        }
        
        if ( $shipment_type > 0 ) {
            $order->update_meta_data( 'tapin_content_type', $shipment_type );
        }
        
        if ( $box_size > 0 ) {
            $order->update_meta_data( 'tapin_box_size', $box_size );
        }
        
        $order->save_meta_data();
        
        // نتیجه اولیه
        $result = [
            'success'        => true,
            'order_id'       => $order_id,
            'weight'         => $weight,
            'shipment_type'  => $shipment_type,
            'box_size'       => $box_size,
            'tracking_code'  => null,
            'tapin_order_id' => null,
            'tapin_offline'  => false,
            'message'        => 'اطلاعات سفارش ذخیره شد.',
        ];
        
        // === منطق Hybrid Barcode ===
        
        // حالت A: بارکد دستی ورودی
        if ( ! empty( $manual_barcode ) ) {
            $order->update_meta_data( 'post_barcode', $manual_barcode );
            $order->save_meta_data();
            
            $result['tracking_code'] = $manual_barcode;
            $result['message']       = 'بارکد دستی ذخیره شد.';
            
            // تغییر وضعیت به completed برای ارسال پیامک
            if ( empty( $new_status ) ) {
                $new_status = 'completed';
            }
        }
        // حالت B: بررسی بارکد موجود یا دریافت از تاپین
        else {
            // بررسی بارکد قبلی
            $existing_barcode = $order->get_meta( 'post_barcode' );
            
            if ( ! empty( $existing_barcode ) ) {
                $result['tracking_code'] = $existing_barcode;
                $result['message']       = 'بارکد قبلی موجود است.';
            }
            // دریافت از تاپین
            elseif ( self::should_submit_to_tapin( $order, $new_status ) ) {
                $tapin_result = self::request_tapin_with_retry( $order );
                
                if ( $tapin_result['success'] && ! empty( $tapin_result['barcode'] ) ) {
                    $result['tracking_code']  = $tapin_result['barcode'];
                    $result['tapin_order_id'] = $tapin_result['order_id'] ?? null;
                    $result['message']        = 'سفارش در تاپین ثبت و بارکد دریافت شد.';
                } else {
                    // Graceful Failure - بدون خطای 500
                    $result['tracking_code'] = null;
                    $result['tapin_offline'] = true;
                    $result['message']       = $tapin_result['message'] ?? 'تاپین قطع است - نیاز به ارسال دستی';
                }
            }
        }
        
        // اضافه کردن اطلاعات مالی تاپین
        $result['real_shipping_cost'] = self::get_real_shipping_cost( $order );
        $result['tapin_balance']      = self::get_tapin_balance();
        $result['shipping_method']    = self::get_shipping_method_label( $order );
        
        // تغییر وضعیت (اگر درخواست شده)
        if ( ! empty( $new_status ) && $new_status !== $order->get_status() ) {
            $order->set_status( $new_status, 'تغییر وضعیت از گوگل شیت - ' );
            $order->save();
            $result['new_status'] = $new_status;
        }
        
        // لاگ
        do_action( 'buyruz_order_processed', $order_id, $result );
        
        return new WP_REST_Response( $result, 200 );
    }
    
    /**
     * دریافت هزینه واقعی ارسال (از تاپین)
     * @param WC_Order $order
     * @return int - هزینه به ریال
     */
    private static function get_real_shipping_cost( WC_Order $order ): int {
        $price = intval( $order->get_meta( 'tapin_send_price' ) ?: 0 );
        $tax   = intval( $order->get_meta( 'tapin_send_price_tax' ) ?: 0 );
        return $price + $tax;
    }
    
    /**
     * دریافت موجودی کیف پول تاپین
     * @return int|null - موجودی به ریال یا null در صورت خطا
     */
    private static function get_tapin_balance(): ?int {
        if ( ! class_exists( 'PWS_Tapin' ) || ! method_exists( 'PWS_Tapin', 'request' ) ) {
            return null;
        }
        
        // استفاده از کش
        $cached = get_transient( 'brz_tapin_balance' );
        if ( $cached !== false ) {
            return intval( $cached );
        }
        
        try {
            if ( method_exists( 'PWS_Tapin', 'set_gateway' ) && function_exists( 'PWS' ) ) {
                PWS_Tapin::set_gateway( PWS()->get_option( 'tapin.gateway' ) );
            }
            
            $response = PWS_Tapin::request( 'v2/public/transaction/credit/', [
                'shop_id' => function_exists( 'PWS' ) ? PWS()->get_option( 'tapin.shop_id' ) : '',
            ] );
            
            if ( is_wp_error( $response ) || ! isset( $response->returns->status ) || $response->returns->status !== 200 ) {
                return null;
            }
            
            $balance = intval( $response->entries->credit ?? 0 );
            set_transient( 'brz_tapin_balance', $balance, 5 * MINUTE_IN_SECONDS );
            
            return $balance;
        } catch ( \Exception $e ) {
            return null;
        }
    }
    
    /**
     * دریافت نام روش ارسال
     * @param WC_Order $order
     * @return string
     */
    private static function get_shipping_method_label( WC_Order $order ): string {
        if ( class_exists( 'PWS_Order' ) && method_exists( 'PWS_Order', 'get_shipping_method' ) ) {
            $label = PWS_Order::get_shipping_method( $order, true );
            return $label ?: 'نامشخص';
        }
        
        // Fallback: read from shipping items
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            return $shipping_item->get_method_title() ?: 'نامشخص';
        }
        
        return 'نامشخص';
    }

    /**
     * بررسی اینکه آیا باید به تاپین ارسال شود
     * @param WC_Order $order
     * @param string $new_status
     * @return bool
     */
    private static function should_submit_to_tapin( WC_Order $order, string $new_status ): bool {
        // اگر قبلاً ثبت شده، نه
        $tapin_uuid = $order->get_meta( 'tapin_order_uuid' );
        if ( ! empty( $tapin_uuid ) ) {
            return false;
        }
        
        // اگر وضعیت به pws-packaged تغییر میکنه، بله
        if ( $new_status === 'pws-packaged' ) {
            return true;
        }
        
        // بررسی کلاس PWS_Tapin
        if ( ! class_exists( 'PWS_Tapin' ) || ! method_exists( 'PWS_Tapin', 'is_enable' ) ) {
            return false;
        }
        
        // اگر تاپین فعال نیست، نه
        if ( ! PWS_Tapin::is_enable() ) {
            return false;
        }
        
        return false;
    }

    /**
     * درخواست به تاپین با منطق Retry
     * 3 بار تلاش با فاصله زمانی افزایشی
     * 
     * @param WC_Order $order
     * @return array ['success' => bool, 'barcode' => string|null, 'order_id' => string|null, 'message' => string]
     */
    private static function request_tapin_with_retry( WC_Order $order ): array {
        // بررسی وجود کلاس‌های PWS
        if ( ! class_exists( 'PWS_Tapin' ) || ! class_exists( 'PWS_Order' ) ) {
            return [
                'success' => false,
                'barcode' => null,
                'order_id' => null,
                'message' => 'افزونه حمل و نقل پیشرفته در دسترس نیست.',
            ];
        }
        
        $max_retries = self::MAX_TAPIN_RETRIES;
        $last_error  = '';
        
        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            try {
                // آماده‌سازی داده‌های تاپین
                $_POST['status']       = 'pws-packaged';
                $_POST['id']           = $order->get_id();
                $_POST['weight']       = $order->get_meta( 'tapin_weight' ) ?: 500;
                $_POST['content_type'] = $order->get_meta( 'tapin_content_type' ) ?: 1;
                $_POST['box_size']     = $order->get_meta( 'tapin_box_size' ) ?: 3;
                
                // Hook into PWS
                do_action( 'pws_tapin_pre_submit_order', $order );
                
                // رفرش سفارش برای دریافت متاهای جدید
                $order = wc_get_order( $order->get_id() );
                
                // بخوان بارکد
                $barcode  = $order->get_meta( 'post_barcode' );
                $tapin_id = $order->get_meta( 'tapin_order_uuid' );
                
                if ( ! empty( $barcode ) ) {
                    return [
                        'success'  => true,
                        'barcode'  => $barcode,
                        'order_id' => $tapin_id,
                        'message'  => 'بارکد از تاپین دریافت شد.',
                    ];
                }
                
                $last_error = 'بارکد از تاپین دریافت نشد.';
                
            } catch ( Exception $e ) {
                $last_error = $e->getMessage();
            }
            
            // تاخیر قبل از تلاش بعدی (افزایشی: 500ms, 1s, 1.5s)
            if ( $attempt < $max_retries ) {
                usleep( 500000 * $attempt );
            }
        }
        
        // همه تلاش‌ها شکست خورد
        return [
            'success'  => false,
            'barcode'  => null,
            'order_id' => null,
            'message'  => 'تاپین قطع است - نیاز به ارسال دستی. (' . $last_error . ')',
        ];
    }

    /**
     * ارسال سفارش به تاپین (Legacy - برای سازگاری)
     * @param WC_Order $order
     * @return array|WP_Error
     * @deprecated Use request_tapin_with_retry instead
     */
    private static function submit_to_tapin( WC_Order $order ) {
        $result = self::request_tapin_with_retry( $order );
        
        if ( $result['success'] ) {
            return [
                'success'  => true,
                'barcode'  => $result['barcode'],
                'order_id' => $result['order_id'],
            ];
        }
        
        return new WP_Error( 'tapin_error', $result['message'] );
    }

    /**
     * دریافت لیست وضعیت‌های سفارش
     * @return array
     */
    public static function get_order_statuses(): array {
        $statuses = wc_get_order_statuses();
        
        // اضافه کردن وضعیت‌های PWS اگر موجود باشند
        if ( class_exists( 'PWS_Status' ) && method_exists( 'PWS_Status', 'get_statues' ) ) {
            $pws_statuses = PWS_Status::get_statues();
            $statuses = array_merge( $statuses, $pws_statuses );
        }
        
        return $statuses;
    }
}
