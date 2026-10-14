<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Connections {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 12 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_brz_regenerate_api_key', array( __CLASS__, 'ajax_regenerate_api_key' ) );
        add_action( 'wp_ajax_brz_save_updater_settings', array( __CLASS__, 'ajax_save_updater_settings' ) );
        add_action( 'wp_ajax_brz_check_updater_now', array( __CLASS__, 'ajax_check_updater_now' ) );
    }

    /**
     * Save updater settings via AJAX.
     */
    public static function ajax_save_updater_settings() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $enabled      = ! empty( $_POST['updater_enabled'] ) ? 1 : 0;
        $source_mode  = sanitize_text_field( $_POST['source_mode'] ?? 'auto' );
        $arvan_url    = esc_url_raw( trim( $_POST['arvan_url'] ?? '' ) );
        $github_token = sanitize_text_field( trim( $_POST['github_token'] ?? '' ) );

        if ( ! in_array( $source_mode, array( 'auto', 'arvan', 'github' ), true ) ) {
            $source_mode = 'auto';
        }

        $current = BRZ_Updater::get_settings();
        // If token input was sent masked/empty but already exists, preserve it unless explicitly overwritten
        if ( empty( $github_token ) && ! empty( $current['github_token'] ) && isset( $_POST['github_token_preserved'] ) ) {
            $github_token = $current['github_token'];
        }

        $to_save = array(
            'enabled'      => $enabled,
            'source_mode'  => $source_mode,
            'arvan_url'    => ! empty( $arvan_url ) ? $arvan_url : BRZ_Updater::DEFAULT_ARVAN_URL,
            'github_token' => $github_token,
        );

        BRZ_Updater::update_settings( $to_save );
        wp_send_json_success( array( 'message' => 'تنظیمات به‌روزرسانی با موفقیت ذخیره شد.' ) );
    }

    /**
     * Force check for updates via AJAX.
     */
    public static function ajax_check_updater_now() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $remote = BRZ_Updater::check_for_updates( true );
        $settings = BRZ_Updater::get_settings();

        if ( empty( $remote ) || empty( $remote['new_version'] ) ) {
            wp_send_json_error( array(
                'message' => 'عدم امکان دریافت اطلاعات نسخه از منابع انتخابی. لطفاً اتصال سرور و توکن را بررسی کنید.',
                'settings' => $settings,
            ) );
        }

        $has_update = version_compare( $remote['new_version'], BRZ_VERSION, '>' );
        $source_title = 'arvan' === ( $remote['source'] ?? '' ) ? 'استوریج ابری آروان‌کلاد (CDN ایران)' : 'مخزن پرایوت گیت‌هاب (GitHub Releases)';

        wp_send_json_success( array(
            'has_update'     => $has_update,
            'current_version'=> BRZ_VERSION,
            'latest_version' => $remote['new_version'],
            'source'         => $source_title,
            'changelog'      => $remote['changelog'] ?? '',
            'message'        => $has_update 
                ? "نسخه جدید {$remote['new_version']} در دسترس است (دریافت شده از {$source_title})." 
                : "افزونه شما به آخرین نسخه ({$remote['new_version']}) به‌روز است (تایید شده از {$source_title}).",
            'settings'       => $settings,
        ) );
    }

    /**
     * Regenerate local API key via AJAX.
     */
    public static function ajax_regenerate_api_key() {
        check_ajax_referer( 'brz_smart_linker_save' );
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
        }

        $settings = BRZ_Smart_Linker::get_settings();
        $new_key  = wp_generate_password( 32, false );
        $settings['local_api_key'] = $new_key;
        update_option( BRZ_Smart_Linker::OPTION_KEY, $settings, false );

        wp_send_json_success( array( 'new_key' => $new_key, 'message' => 'کلید جدید ساخته شد.' ) );
    }

    public static function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'buyruz-connections' ) ) {
            return;
        }
        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
    }

    public static function add_menu() {
        add_submenu_page(
            BRZ_Settings::PARENT_SLUG,
            'اتصالات',
            'اتصالات',
            BRZ_Settings::CAPABILITY,
            'buyruz-connections',
            array( __CLASS__, 'render_page' ),
            3
        );
    }

    public static function render_page() {
        if ( ! current_user_can( BRZ_Settings::CAPABILITY ) ) {
            return;
        }
        $settings = BRZ_Smart_Linker::get_settings();
        $brand = esc_attr( BRZ_Settings::get( 'brand_color', '#1a73e8' ) );
        ?>
        <div class="brz-admin-wrap" dir="rtl" style="--brz-brand: <?php echo $brand; ?>;">
            <div class="brz-hero">
                <div class="brz-hero__content">
                    <div class="brz-hero__title-row">
                        <h1>اتصالات بایروز</h1>
                        <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                    </div>
                    <p>یکپارچه‌سازی بلاگ ↔ فروشگاه، Google Sheet و API هوش مصنوعی با یک مسیر تنظیماتی.</p>
                </div>
            </div>

            <div class="brz-card">
                <h2 class="nav-tab-wrapper">
                    <a class="nav-tab nav-tab-active" data-brz-tab="peer">فروشگاه / بلاگ</a>
                    <a class="nav-tab" data-brz-tab="gsheet">گوگل شیت</a>
                    <a class="nav-tab" data-brz-tab="ai">API هوش مصنوعی</a>
                    <a class="nav-tab" data-brz-tab="bi">تحلیل سایت</a>
                    <a class="nav-tab" data-brz-tab="updater">به‌روزرسانی خودکار</a>
                </h2>
                <div class="brz-card__body">
                    <div class="brz-tab-pane" data-pane="peer">
                        <?php self::render_peer( $settings ); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="gsheet" style="display:none;">
                        <?php self::render_gsheet( $settings ); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="ai" style="display:none;">
                        <?php self::render_ai(); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="bi" style="display:none;">
                        <?php self::render_bi_settings(); ?>
                    </div>
                    <div class="brz-tab-pane" data-pane="updater" style="display:none;">
                        <?php self::render_updater_settings(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php self::inline_js(); ?>
        <?php
    }

    private static function render_gsheet( $settings ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-sheet-id">Google Sheet ID <span class="brz-help-tip" data-tip="شناسه شیت در URL بین /d/ و /edit قرار دارد.">?</span></label></th>
                        <td><input type="text" id="brz-sl-sheet-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[sheet_id]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-id">OAuth Client ID <span class="brz-help-tip" data-tip="کلاینت OAuth 2.0 (Web application) از Google Cloud Console.">?</span></label></th>
                        <td><input type="text" id="brz-sl-client-id" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_id]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-client-secret">OAuth Client Secret</label></th>
                        <td><input type="password" id="brz-sl-client-secret" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_client_secret]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['google_client_secret'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-refresh-token">Refresh Token <span class="brz-help-tip" data-tip="پس از احراز هویت، رفرش توکن را ذخیره کنید.">?</span></label></th>
                        <td><input type="text" id="brz-sl-refresh-token" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[google_refresh_token]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( isset( $settings['google_refresh_token'] ) ? $settings['google_refresh_token'] : '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>احراز هویت</label></th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=brz_gsheet_oauth_start' ), 'brz_gsheet_oauth' ) ); ?>">اتصال / نوسازی توکن</a>
                                <button type="button" class="button" id="brz-sl-test-gsheet">تست اتصال</button>
                                <span class="description" id="brz-sl-gsheet-status"></span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button( 'ذخیره اتصال گوگل', 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_peer( $settings ) {
        // Get local endpoint and API key for this site
        $local_endpoint = rest_url( 'brz/v1/inventory' );
        $local_api_key  = $settings['local_api_key'] ?? '';
        ?>
        <!-- اطلاعات API این سایت -->
        <div class="brz-card brz-card--sub" style="margin-bottom: 24px; background: linear-gradient(135deg, #e0f2fe, #f0fdf4); border: 1px solid #bae6fd;">
            <div class="brz-card__header" style="border-bottom: 1px solid #bae6fd;">
                <h3 style="display: flex; align-items: center; gap: 8px;">
                    <span style="width: 24px; height: 24px; background: #22c55e; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px;">📡</span>
                    اطلاعات API این سایت (برای اشتراک با سایت دیگر)
                </h3>
            </div>
            <div class="brz-card__body">
                <p class="description" style="margin-bottom: 16px;">این اطلاعات را کپی کنید و در سایت مقصد (که می‌خواهد از این سایت داده دریافت کند) در بخش Remote وارد کنید.</p>
                
                <table class="form-table" role="presentation" style="margin: 0;">
                    <tbody>
                        <tr>
                            <th scope="row" style="width: 140px;"><label>Endpoint این سایت</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="brz-local-endpoint" class="regular-text code" dir="ltr" value="<?php echo esc_url( $local_endpoint ); ?>" readonly style="background: #fff;" />
                                    <button type="button" class="button brz-copy-btn" data-target="brz-local-endpoint" title="کپی">📋</button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>API Key این سایت</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="brz-local-apikey" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $local_api_key ); ?>" readonly style="background: #fff;" />
                                    <button type="button" class="button brz-copy-btn" data-target="brz-local-apikey" title="کپی">📋</button>
                                    <button type="button" class="button" id="brz-regenerate-key" title="ساخت کلید جدید">🔄</button>
                                </div>
                                <p class="description" style="margin-top: 8px;">این کلید خودکار ساخته شده. برای امنیت بیشتر می‌توانید آن را رژنریت کنید.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- اتصال به سایت ریموت -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-settings-form" data-ajax="1">
            <?php wp_nonce_field( 'brz_smart_linker_save' ); ?>
            <input type="hidden" name="action" value="brz_smart_linker_save" />
            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-connections' ) ); ?>" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-endpoint">آدرس API سایت مقابل <span class="brz-help-tip" data-tip="آدرس endpoint سایت مقابل برای دریافت داده.">؟</span></label></th>
                        <td><input type="url" id="brz-sl-remote-endpoint" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-remote-key">کلید API سایت مقابل <span class="brz-help-tip" data-tip="کلید API که از سایت مقابل کپی کردید.">؟</span></label></th>
                        <td><input type="text" id="brz-sl-remote-key" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[remote_api_key]" class="regular-text" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>تست اتصال</label></th>
                        <td>
                            <button type="button" class="button" id="brz-sl-test-peer">تست اتصال ریموت</button>
                            <span class="description" id="brz-sl-peer-status"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="brz-sl-role">نقش این سایت</label></th>
                        <td>
                            <select id="brz-sl-role" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[site_role]">
                                <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>فروشگاه (ووکامرس)</option>
                                <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>بلاگ (وردپرس)</option>
                            </select>
                            <p class="description">بر اساس نقش، نوع داده‌هایی که به اشتراک گذاشته می‌شود تعیین می‌شود.</p>
                        </td>
                    </tr>
                    <input type="hidden" name="<?php echo esc_attr( BRZ_Smart_Linker::OPTION_KEY ); ?>[mode]" value="api" />
                </tbody>
            </table>
            <p class="submit" style="display:flex;gap:8px;align-items:center;margin-top:16px;">
                <?php submit_button( 'ذخیره اتصال ریموت', 'primary', 'submit', false ); ?>
                <button type="button" class="button" id="brz-sl-sync-btn">همگام‌سازی داده</button>
                <span id="brz-sl-sync-status" class="description"></span>
            </p>
        </form>
        <?php
    }

    private static function render_ai() {
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>API هوش مصنوعی</h3></div>
            <div class="brz-card__body">
                <p class="description">در اینجا می‌توانید اتصال به APIهای هوش مصنوعی (OpenAI و ...) را مدیریت کنید. (Placeholder)</p>
            </div>
        </div>
        <?php
    }

    private static function render_bi_settings() {
        if ( ! class_exists( 'BRZ_BI_Exporter' ) ) {
            echo '<p class="description">ماژول تحلیل سایت فعال نیست.</p>';
            return;
        }
        $settings = BRZ_BI_Exporter::get_settings();
        $save_nonce = wp_create_nonce( 'brz_bi_exporter_save' );
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header"><h3>تنظیمات ارتباط تحلیل سایت</h3></div>
            <div class="brz-card__body">
                <form id="brz-bi-settings-form" class="brz-settings-form">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="brz-bi-api-key">کلید API <span class="dashicons dashicons-editor-help" data-tip="کلید محافظت از endpoint full-dump؛ در query یا هدر X-Buyruz-Key استفاده می‌شود."></span></label></th>
                                <td>
                                    <input type="text" id="brz-bi-api-key" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[api_key]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-remote-endpoint">Remote Endpoint <span class="dashicons dashicons-editor-help" data-tip="آدرس کامل endpoint سایت مقابل، مثلا https://peer-site.com/wp-json/buyruz/v1/full-dump"></span></label></th>
                                <td>
                                    <input type="url" id="brz-bi-remote-endpoint" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[remote_endpoint]" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['remote_endpoint'] ); ?>" placeholder="https://peer-site.com/wp-json/buyruz/v1/full-dump" />
                                    <p class="description">درخواست با scope=local ارسال می‌شود تا از لوپ Shop ↔ Blog جلوگیری شود.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-remote-key">Remote API Key <span class="dashicons dashicons-editor-help" data-tip="همان کلیدی که روی سایت مقابل برای full-dump تنظیم شده است."></span></label></th>
                                <td>
                                    <input type="text" id="brz-bi-remote-key" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[remote_api_key]" class="regular-text code" dir="ltr" value="<?php echo esc_attr( $settings['remote_api_key'] ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-bi-role">نقش این سایت <span class="dashicons dashicons-editor-help" data-tip="Shop برای ووکامرس، Blog برای وردپرس معمولی؛ در ساخت master JSON گره shop/blog تعیین می‌شود."></span></label></th>
                                <td>
                                    <select id="brz-bi-role" name="<?php echo esc_attr( BRZ_BI_Exporter::OPTION_KEY ); ?>[site_role]">
                                        <option value="shop" <?php selected( $settings['site_role'], 'shop' ); ?>>Shop (WooCommerce)</option>
                                        <option value="blog" <?php selected( $settings['site_role'], 'blog' ); ?>>Blog (WordPress)</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;">
                        <button type="submit" class="button button-primary">ذخیره تنظیمات</button>
                        <span id="brz-bi-save-status" class="description"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function render_updater_settings() {
        $settings = BRZ_Updater::get_settings();
        $save_nonce = wp_create_nonce( 'brz_smart_linker_save' );
        $last_check_str = ! empty( $settings['last_check'] ) ? wp_date( 'Y-m-d H:i:s', $settings['last_check'] ) : 'تاکنون بررسی نشده است';
        $has_gh_token = ! empty( $settings['github_token'] );
        ?>
        <div class="brz-card brz-card--sub">
            <div class="brz-card__header">
                <h3>سیستم به‌روزرسانی هوشمند و دوگانه (Dual-Source Resilient Updater)</h3>
            </div>
            <div class="brz-card__body">
                <p class="description" style="margin-bottom: 20px;">
                    این سیستم امکان دریافت خودکار و بدون فیلتر آخرین نسخه افزونه را از دو منبع موازی (سرور ابری پرسرعت آروان‌کلاد ایران و مخزن اختصاصی پرایوت گیت‌هاب) فراهم می‌سازد. در صورت اختلال در یک منبع، سیستم به طور خودکار به منبع جایگزین سوئیچ می‌کند.
                </p>

                <!-- Status Card -->
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;">
                    <div>
                        <div style="font-weight:600;font-size:15px;color:#03432E;margin-bottom:4px;">
                            وضعیت نسخه: نگارش نصب‌شده <?php echo esc_html( BRZ_VERSION ); ?>
                        </div>
                        <div style="font-size:13px;color:#64748b;">
                            آخرین بررسی: <span id="brz-updater-last-check"><?php echo esc_html( $last_check_str ); ?></span>
                            <?php if ( ! empty( $settings['last_source'] ) ) : ?>
                                | منبع متصل: <span id="brz-updater-last-source"><?php echo esc_html( 'arvan' === $settings['last_source'] ? 'آروان‌کلاد (CDN)' : 'گیت‌هاب' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="button" id="brz-check-updater-btn" class="button button-secondary" style="height:38px;padding:0 16px;border-color:#05593D;color:#05593D;font-weight:600;">
                            🔄 بررسی اکنون نگارش جدید
                        </button>
                    </div>
                </div>

                <div id="brz-updater-result-box" style="display:none;margin-bottom:20px;padding:12px 16px;border-radius:8px;font-size:14px;"></div>

                <form id="brz-updater-settings-form" class="brz-settings-form">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_nonce ); ?>" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">به‌روزرسانی خودکار</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="updater_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                                        فعال‌سازی بررسی و دریافت خودکار به‌روزرسانی‌های افزونه در وردپرس
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="brz-updater-source-mode">منبع دریافت به‌روزرسانی</label></th>
                                <td>
                                    <select id="brz-updater-source-mode" name="source_mode" style="min-width:280px;">
                                        <option value="auto" <?php selected( $settings['source_mode'], 'auto' ); ?>>🌟 ترکیبی هوشمند (اولویت آروان‌کلاد + پشتیبان گیت‌هاب)</option>
                                        <option value="arvan" <?php selected( $settings['source_mode'], 'arvan' ); ?>>فقط استوریج و CDN آروان‌کلاد (سرور ایران)</option>
                                        <option value="github" <?php selected( $settings['source_mode'], 'github' ); ?>>فقط مخزن پرایوت گیت‌هاب (GitHub Releases)</option>
                                    </select>
                                    <p class="description">حالت ترکیبی هوشمند تضمین می‌کند در صورت تحریم، فیلترینگ یا قطعی هر یک از سرورها، به‌روزرسانی بدون وقفه انجام پذیرد.</p>
                                </td>
                            </tr>
                            <tr class="brz-updater-field-arvan">
                                <th scope="row"><label for="brz-updater-arvan-url">آدرس مانیفست آروان‌کلاد (CDN)</label></th>
                                <td>
                                    <input type="url" id="brz-updater-arvan-url" name="arvan_url" class="regular-text code" dir="ltr" value="<?php echo esc_url( $settings['arvan_url'] ); ?>" placeholder="https://dl.buyruz.ir/plugins/buyruz-plugin/version.json" />
                                    <p class="description">آدرس مستقیم فایل <code>version.json</code> بر روی استوریج و CDN ابری بایروز.</p>
                                </td>
                            </tr>
                            <tr class="brz-updater-field-github">
                                <th scope="row"><label for="brz-updater-github-token">توکن دسترسی مخزن پرایوت گیت‌هاب (PAT)</label></th>
                                <td>
                                    <?php if ( $has_gh_token ) : ?>
                                        <input type="hidden" name="github_token_preserved" value="1" />
                                    <?php endif; ?>
                                    <input type="password" id="brz-updater-github-token" name="github_token" class="regular-text code" dir="ltr" value="<?php echo $has_gh_token ? '••••••••••••••••••••••••••••••••' : ''; ?>" placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" autocomplete="new-password" />
                                    <p class="description">
                                        Personal Access Token با دسترسی فقط‌خواندنی (Read-Only Contents) برای دریافت بسته‌ها از مخزن اختصاصی <code>Codruz/buyruz-plugin</code>.
                                        <?php if ( defined( 'BRZ_GITHUB_UPDATE_TOKEN' ) ) : ?>
                                            <br><span style="color:#05593D;">(توکن اختصاصی از ثابت سیستم در wp-config لود شده است)</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="brz-save-bar" style="display:flex;gap:8px;align-items:center;margin-top:20px;">
                        <button type="submit" class="button button-primary">ذخیره تنظیمات به‌روزرسانی</button>
                        <span id="brz-updater-save-status" class="description"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private static function inline_js() {
        $nonce = wp_create_nonce( 'brz_smart_linker_save' );
        ?>
        <script>
        (function(){
            const tabs = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
            const panes = document.querySelectorAll('.brz-tab-pane');
            tabs.forEach(tab=>{
                tab.addEventListener('click', ()=>{
                    tabs.forEach(t=>t.classList.remove('nav-tab-active'));
                    tab.classList.add('nav-tab-active');
                    const target = tab.getAttribute('data-brz-tab');
                    panes.forEach(p=>p.style.display = (p.dataset.pane === target ? 'block' : 'none'));
                });
            });

            const ajaxForms = document.querySelectorAll('form[data-ajax="1"]');
            ajaxForms.forEach(form=>{
                form.addEventListener('submit', function(e){
                    if (!window.ajaxurl) return;
                    e.preventDefault();
                    const data = new FormData(form);
                    data.append('action','brz_smart_linker_save');
                    data.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:data})
                        .then(r=>r.json()).then(json=>{
                            if (window.brzToast) {
                                window.brzToast(json && json.success ? 'تنظیمات ذخیره شد.' : 'خطا در ذخیره‌سازی.', json && json.success ? 'success' : 'error');
                            }
                        }).catch(()=>{
                            if (window.brzToast) window.brzToast('خطا در برقراری ارتباط با سرور.', 'error');
                        });
                });
            });

            // BI settings ajax save
            const biForm = document.getElementById('brz-bi-settings-form');
            const biStatus = document.getElementById('brz-bi-save-status');
            if(biForm){
                biForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    const fd=new FormData(biForm);
                    fd.append('action','brz_bi_exporter_save');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd})
                        .then(r=>r.json())
                        .then(j=>{
                            if(j?.success){ biStatus.textContent='ذخیره شد'; }
                            else { biStatus.textContent='خطا در ذخیره'; biStatus.style.color='#b91c1c'; }
                        })
                        .catch(()=>{ biStatus.textContent='خطا در ذخیره'; biStatus.style.color='#b91c1c'; });
                });
            }

            // Copy buttons functionality
            document.querySelectorAll('.brz-copy-btn').forEach(btn=>{
                btn.addEventListener('click', ()=>{
                    const targetId = btn.dataset.target;
                    const input = document.getElementById(targetId);
                    if(input){
                        navigator.clipboard.writeText(input.value).then(()=>{
                            const orig = btn.textContent;
                            btn.textContent = '✓';
                            btn.style.background = '#22c55e';
                            btn.style.color = '#fff';
                            setTimeout(()=>{ btn.textContent = orig; btn.style.background = ''; btn.style.color = ''; }, 1500);
                        });
                    }
                });
            });

            // Regenerate API key
            const regenBtn = document.getElementById('brz-regenerate-key');
            if(regenBtn){
                regenBtn.addEventListener('click', ()=>{
                    if(!confirm('آیا کلید جدید بسازم؟ کلید قبلی دیگر کار نخواهد کرد.')) return;
                    regenBtn.textContent = '⏳';
                    regenBtn.disabled = true;
                    const fd = new FormData();
                    fd.append('action', 'brz_regenerate_api_key');
                    fd.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
                        .then(r=>r.json())
                        .then(j=>{
                            if(j?.success && j?.data?.new_key){
                                document.getElementById('brz-local-apikey').value = j.data.new_key;
                                regenBtn.textContent = '✓';
                                setTimeout(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; }, 1500);
                            } else {
                                regenBtn.textContent = '❌';
                                setTimeout(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; }, 1500);
                            }
                        })
                        .catch(()=>{ regenBtn.textContent = '🔄'; regenBtn.disabled = false; });
                });
            }

            // Help tooltips - modern popup instead of alert
            document.querySelectorAll('.brz-help-tip').forEach(icon=>{
                const tip = icon.dataset.tip;
                if(tip){
                    icon.setAttribute('role','button');
                    icon.setAttribute('tabindex','0');
                    icon.setAttribute('aria-label','راهنما');
                    
                    // Create tooltip element
                    const tooltip = document.createElement('span');
                    tooltip.className = 'brz-tooltip';
                    tooltip.textContent = tip;
                    icon.appendChild(tooltip);
                    
                    // Toggle on click
                    icon.addEventListener('click', (e)=>{
                        e.stopPropagation();
                        document.querySelectorAll('.brz-help-tip.is-open').forEach(el=>{ if(el!==icon) el.classList.remove('is-open'); });
                        icon.classList.toggle('is-open');
                    });
                    icon.addEventListener('keydown', (ev)=>{ if(ev.key==='Enter' || ev.key===' '){ ev.preventDefault(); icon.click(); }});
                }
            });
            // Close tooltips when clicking outside
            document.addEventListener('click', ()=>{ document.querySelectorAll('.brz-help-tip.is-open').forEach(el=>el.classList.remove('is-open')); });

            const syncBtn=document.getElementById('brz-sl-sync-btn');
            if(syncBtn){
                const status=document.getElementById('brz-sl-sync-status');
                syncBtn.addEventListener('click',()=>{
                    const fd=new FormData();fd.append('action','brz_smart_linker_sync_cache');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{status.textContent=j?.data?.message||'Done';}).catch(()=>status.textContent='خطا');
                });
            }

            const testG=document.getElementById('brz-sl-test-gsheet');
            if(testG){
                const s=document.getElementById('brz-sl-gsheet-status');
                testG.addEventListener('click',()=>{
                    s.textContent='Testing...';
                    const fd=new FormData();fd.append('action','brz_smart_linker_test_gsheet');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{s.textContent=j?.data?.message||'OK';}).catch(()=>s.textContent='خطا');
                });
            }

            const testP=document.getElementById('brz-sl-test-peer');
            if(testP){
                const s=document.getElementById('brz-sl-peer-status');
                testP.addEventListener('click',()=>{
                    s.textContent='Testing...';
                    const fd=new FormData();fd.append('action','brz_smart_linker_test_peer');fd.append('_wpnonce','<?php echo esc_js( $nonce ); ?>');
                    fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(j=>{s.textContent=j?.data?.message||'OK';}).catch(()=>s.textContent='خطا');
                });
            }

            // Updater Form AJAX Submission
            const updaterForm = document.getElementById('brz-updater-settings-form');
            if(updaterForm){
                const statusSpan = document.getElementById('brz-updater-save-status');
                updaterForm.addEventListener('submit', (e)=>{
                    e.preventDefault();
                    statusSpan.style.color = '#05593D';
                    statusSpan.textContent = 'در حال ذخیره...';
                    const fd = new FormData(updaterForm);
                    fd.append('action', 'brz_save_updater_settings');
                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(j => {
                            if(j.success){
                                statusSpan.style.color = '#05593D';
                                statusSpan.textContent = j.data?.message || 'ذخیره شد.';
                            } else {
                                statusSpan.style.color = '#dc2626';
                                statusSpan.textContent = j.data?.message || 'خطا در ذخیره سازی.';
                            }
                            setTimeout(()=>{ statusSpan.textContent = ''; }, 4000);
                        })
                        .catch(()=>{
                            statusSpan.style.color = '#dc2626';
                            statusSpan.textContent = 'خطا در ارتباط با سرور.';
                        });
                });
            }

            // Updater Manual Check Button
            const checkBtn = document.getElementById('brz-check-updater-btn');
            if(checkBtn){
                const resBox = document.getElementById('brz-updater-result-box');
                const lastCheckSpan = document.getElementById('brz-updater-last-check');
                const lastSourceSpan = document.getElementById('brz-updater-last-source');

                checkBtn.addEventListener('click', ()=>{
                    checkBtn.disabled = true;
                    checkBtn.textContent = '⏳ در حال بررسی آنلاین...';
                    resBox.style.display = 'none';

                    const fd = new FormData();
                    fd.append('action', 'brz_check_updater_now');
                    fd.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');

                    fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                        .then(r => r.json())
                        .then(j => {
                            checkBtn.disabled = false;
                            checkBtn.textContent = '🔄 بررسی اکنون نگارش جدید';
                            resBox.style.display = 'block';

                            if(j.success){
                                const d = j.data;
                                if(d.has_update){
                                    resBox.style.background = '#fef3c7';
                                    resBox.style.color = '#92400e';
                                    resBox.style.border = '1px solid #fcd34d';
                                    resBox.innerHTML = '<strong>' + d.message + '</strong><br><span style="font-size:13px;">برای ارتقا به صفحه <a href="plugins.php" style="color:#05593D;text-decoration:underline;">افزونه‌ها</a> مراجعه کنید.</span>';
                                } else {
                                    resBox.style.background = '#ecfdf5';
                                    resBox.style.color = '#065f46';
                                    resBox.style.border = '1px solid #a7f3d0';
                                    resBox.innerHTML = '<strong>' + d.message + '</strong>';
                                }
                                if(lastCheckSpan){
                                    const now = new Date();
                                    lastCheckSpan.textContent = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0') + ' ' + String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
                                }
                                if(lastSourceSpan && d.source){
                                    lastSourceSpan.textContent = d.source;
                                }
                            } else {
                                resBox.style.background = '#fef2f2';
                                resBox.style.color = '#991b1b';
                                resBox.style.border = '1px solid #fecaca';
                                resBox.textContent = j.data?.message || 'خطا در بررسی به‌روزرسانی.';
                            }
                        })
                        .catch(()=>{
                            checkBtn.disabled = false;
                            checkBtn.textContent = '🔄 بررسی اکنون نگارش جدید';
                            resBox.style.display = 'block';
                            resBox.style.background = '#fef2f2';
                            resBox.style.color = '#991b1b';
                            resBox.style.border = '1px solid #fecaca';
                            resBox.textContent = 'خطای اتصال شبکه هنگام بررسی نگارش.';
                        });
                });
            }
        })();
        </script>
        <?php
    }
}
