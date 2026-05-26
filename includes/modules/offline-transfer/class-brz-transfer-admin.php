<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Transfer_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            'buyruz-dashboard',
            'انتقال آفلاین',
            'انتقال آفلاین',
            'manage_options',
            'buyruz-offline-transfer',
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap brz-wrap" dir="rtl">
            <h1 class="wp-heading-inline brz-section-header">انتقال آفلاین</h1>
            <button id="brz-refresh-transfer" class="button button-primary">بروزرسانی</button>
            <span id="brz-transfer-status" style="margin-right: 10px;"></span>

            <div class="brz-card" style="margin-top: 20px; padding: 20px;">
                <h2>╔═══ صندوق ورودی (دریافت از شیت) ═══╗</h2>
                <textarea id="brz-inbound-data" style="width: 100%; height: 100px; text-align: left;" dir="ltr" placeholder="متن کپی شده از گوگل شیت را اینجا پیست کنید..."></textarea>
                <p><button id="brz-process-inbound" class="button button-primary">پردازش</button></p>
                <div id="brz-inbound-result" style="margin-top: 10px; font-weight: bold;"></div>
            </div>

            <div class="brz-card" style="margin-top: 20px; padding: 20px;">
                <h2>╔═══ صندوق خروجی (ارسال به شیت) ═══╗</h2>
                <div id="brz-outbound-queue">
                    <p>در حال بارگذاری...</p>
                </div>
            </div>

            <div class="brz-card" style="margin-top: 20px; padding: 20px;">
                <h2>╔═══ تولید بسته جدید ═══╗</h2>
                <button class="button brz-generate-pkg" data-type="product.receive">دریافت محصولات</button>
                <button class="button brz-generate-pkg" data-type="taxonomy.sync_from_site">سینک تکسونومی</button>
                <button class="button brz-generate-pkg" data-type="price.receive">دریافت قیمت‌ها</button>
            </div>
        </div>
        <?php
    }
}
