<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Buyruz Price Queue - اعمال آفلاین قیمت محصولات از Google Sheet
 *
 * وقتی ارسال قیمت از شیت به سایت ناموفق باشه (مثلاً به دلیل VPN)،
 * تغییرات توی یه JSON ذخیره میشن و از طریق این ماژول اعمال میشن.
 */
class BRZ_Price_Queue {

    const NONCE_ACTION = 'brz_price_queue_apply';
    const CAPABILITY   = 'manage_woocommerce';

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 90 );
        add_action( 'wp_ajax_brz_price_queue_apply', array( __CLASS__, 'ajax_apply' ) );
    }

    /**
     * Register admin submenu page under Buyruz.
     */
    public static function register_menu() {
        add_submenu_page(
            'buyruz-dashboard',
            'صف قیمت آفلاین',
            '🔄 صف قیمت',
            self::CAPABILITY,
            'buyruz-price-queue',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Render the admin page.
     */
    public static function render_page() {
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap" style="max-width:860px;">
            <h1 style="margin-bottom:16px;">🔄 صف قیمت آفلاین</h1>
            <p class="description" style="font-size:14px;margin-bottom:20px;">
                JSON صف قیمت را از Google Sheet کپی کرده و در کادر زیر paste کنید، سپس دکمه «اعمال» را بزنید.
            </p>

            <div id="brz-pq-form">
                <textarea id="brz-pq-input" rows="12" style="width:100%;font-family:monospace;font-size:13px;direction:ltr;text-align:left;border-radius:8px;padding:12px;border:1px solid #c3c4c7;" placeholder='[{"id": 123, "regular_price": "500000"}, ...]'></textarea>

                <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
                    <button type="button" id="brz-pq-apply" class="button button-primary button-large">اعمال قیمت‌ها</button>
                    <span id="brz-pq-status" style="font-weight:600;"></span>
                </div>
            </div>

            <div id="brz-pq-results" style="margin-top:20px;display:none;">
                <h3>نتیجه:</h3>
                <table class="widefat striped" style="max-width:100%;">
                    <thead>
                        <tr>
                            <th>آیدی محصول</th>
                            <th>قیمت</th>
                            <th>وضعیت</th>
                            <th>جزئیات</th>
                        </tr>
                    </thead>
                    <tbody id="brz-pq-results-body"></tbody>
                </table>
                <div style="margin-top:12px;" id="brz-pq-summary"></div>
            </div>

            <script>
            (function(){
                var btn = document.getElementById('brz-pq-apply');
                var input = document.getElementById('brz-pq-input');
                var status = document.getElementById('brz-pq-status');
                var resultsDiv = document.getElementById('brz-pq-results');
                var resultsBody = document.getElementById('brz-pq-results-body');
                var summaryDiv = document.getElementById('brz-pq-summary');

                btn.addEventListener('click', function(){
                    var raw = input.value.trim();
                    if (!raw) {
                        status.textContent = '❌ کادر خالی است.';
                        status.style.color = '#d63638';
                        return;
                    }

                    var items;
                    try {
                        items = JSON.parse(raw);
                    } catch(e) {
                        status.textContent = '❌ JSON نامعتبر: ' + e.message;
                        status.style.color = '#d63638';
                        return;
                    }

                    if (!Array.isArray(items) || !items.length) {
                        status.textContent = '❌ آرایه خالی یا نامعتبر.';
                        status.style.color = '#d63638';
                        return;
                    }

                    btn.disabled = true;
                    status.textContent = '⏳ در حال اعمال...';
                    status.style.color = '#2271b1';

                    var fd = new FormData();
                    fd.append('action', 'brz_price_queue_apply');
                    fd.append('_nonce', '<?php echo esc_js( $nonce ); ?>');
                    fd.append('items', JSON.stringify(items));

                    fetch(ajaxurl, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            btn.disabled = false;
                            if (!res.success) {
                                status.textContent = '❌ ' + (res.data && res.data.message || 'خطا');
                                status.style.color = '#d63638';
                                return;
                            }
                            var data = res.data;
                            status.textContent = '✅ انجام شد: ' + data.success_count + ' موفق، ' + data.failed_count + ' ناموفق';
                            status.style.color = data.failed_count > 0 ? '#dba617' : '#00a32a';

                            // نمایش جدول نتایج
                            resultsBody.innerHTML = '';
                            (data.results || []).forEach(function(r){
                                var tr = document.createElement('tr');
                                tr.innerHTML = '<td>' + r.id + '</td>'
                                    + '<td style="direction:ltr">' + r.regular_price + '</td>'
                                    + '<td>' + (r.success ? '✅' : '❌') + '</td>'
                                    + '<td>' + (r.error || '-') + '</td>';
                                resultsBody.appendChild(tr);
                            });
                            resultsDiv.style.display = 'block';
                            summaryDiv.innerHTML = '<strong>مجموع: ' + data.total + ' | موفق: ' + data.success_count + ' | ناموفق: ' + data.failed_count + '</strong>';
                        })
                        .catch(function(err){
                            btn.disabled = false;
                            status.textContent = '❌ خطای شبکه: ' + err.message;
                            status.style.color = '#d63638';
                        });
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * AJAX handler: apply price queue items.
     */
    public static function ajax_apply() {
        check_ajax_referer( self::NONCE_ACTION, '_nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
        }

        $raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
        $items = json_decode( $raw, true );

        if ( ! is_array( $items ) || empty( $items ) ) {
            wp_send_json_error( array( 'message' => 'داده نامعتبر.' ), 400 );
        }

        $results       = array();
        $success_count = 0;
        $failed_count  = 0;

        foreach ( $items as $item ) {
            $product_id    = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
            $regular_price = isset( $item['regular_price'] ) ? sanitize_text_field( $item['regular_price'] ) : '';

            if ( ! $product_id || $regular_price === '' ) {
                $results[] = array(
                    'id'            => $product_id,
                    'regular_price' => $regular_price,
                    'success'       => false,
                    'error'         => 'آیدی یا قیمت خالی',
                );
                $failed_count++;
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $results[] = array(
                    'id'            => $product_id,
                    'regular_price' => $regular_price,
                    'success'       => false,
                    'error'         => 'محصول یافت نشد',
                );
                $failed_count++;
                continue;
            }

            try {
                $product->set_regular_price( $regular_price );
                $product->save();

                $results[] = array(
                    'id'            => $product_id,
                    'regular_price' => $regular_price,
                    'success'       => true,
                    'error'         => '',
                );
                $success_count++;
            } catch ( \Exception $e ) {
                $results[] = array(
                    'id'            => $product_id,
                    'regular_price' => $regular_price,
                    'success'       => false,
                    'error'         => $e->getMessage(),
                );
                $failed_count++;
            }
        }

        wp_send_json_success( array(
            'total'         => count( $items ),
            'success_count' => $success_count,
            'failed_count'  => $failed_count,
            'results'       => $results,
        ) );
    }
}
