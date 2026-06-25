<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Settings {
    const CAPABILITY  = 'manage_options';
    const PARENT_SLUG = 'buyruz-dashboard';
    const MENU_POSITION = 2;
    private static $options_cache = null;

    private static function sections_meta() {
        $sections = array(
            'brz_main'    => array(
                'title'       => 'استایل FAQ',
                'description' => 'کنترل رنگ برند، انیمیشن و رفتار آکاردئون FAQ برای همهٔ سایت.',
                'footer'      => 'تأثیر روی صفحات دارای Rank Math FAQ.',
            ),
            'brz_tables' => array(
                'title'       => 'استایل جداول',
                'description' => 'اعمال استایل جمع‌وجور بایروز روی جداول محتوا به‌صورت انتخابی.',
                'footer'      => 'می‌توانید محدودهٔ اعمال را جداگانه برای محصولات، برگه‌ها یا دسته‌بندی‌ها روشن کنید.',
            ),
            'brz_compare' => array(
                'title'       => 'پیش‌فرض‌های جدول متا',
                'description' => 'عنوان و نام ستون‌های پیش‌فرض برای جدول متای محصولات.',
            ),

            'brz_guidelines' => array(
                'title'       => 'راهنمای توسعه و پاکسازی',
                'description' => 'توصیه‌های کلیدی برای افزودن ماژول‌های آینده بدون قربانی کردن سرعت و سلامت دیتابیس.',
                'callback'    => array( __CLASS__, 'render_guidelines_card' ),
            ),
        );

        return apply_filters( 'brz/settings/sections_meta', $sections );
    }

    private static function nav_items() {
        $items = array(
            array(
                'slug'  => self::PARENT_SLUG,
                'label' => 'پیشخوان',
            ),
            array(
                'slug'  => 'buyruz-general',
                'label' => 'تنظیمات عمومی',
            ),
            array(
                'slug'  => 'buyruz-connections',
                'label' => 'ارتباطات',
            ),
            array(
                'slug'  => 'buyruz-style',
                'label' => 'استایل',
            ),
        );

        foreach ( self::module_nav_items() as $slug => $meta ) {
            $items[] = array(
                'slug'   => 'buyruz-module-' . $slug,
                'label'  => isset( $meta['label'] ) ? $meta['label'] : $slug,
                'module' => $slug,
            );
        }

        return $items;
    }

    private static function module_nav_items() {
        $modules = BRZ_Modules::registry();
        $states  = BRZ_Modules::get_states();
        foreach ( $modules as $slug => $meta ) {
            if ( empty( $states[ $slug ] ) ) {
                unset( $modules[ $slug ] );
            }
        }
        return $modules;
    }

    public static function get( $key = null, $default = null ) {
        if ( null === self::$options_cache ) {
            self::$options_cache = get_option( BRZ_OPTION, array() );
        }
        $opts = self::$options_cache;
        if ( $key === null ) { return $opts; }
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_brz_toggle_module', array( __CLASS__, 'handle_toggle_module' ) );
        add_action( 'wp_ajax_brz_toggle_module', array( __CLASS__, 'handle_toggle_module_ajax' ) );
        add_action( 'wp_ajax_brz_save_settings', array( __CLASS__, 'handle_save_settings_ajax' ) );
        add_action( 'admin_post_brz_delete_compare_table', array( __CLASS__, 'handle_delete_compare_table' ) );
        add_action( 'admin_post_brz_create_compare_table', array( __CLASS__, 'handle_create_compare_table' ) );

        if ( class_exists( 'BRZ_Connections' ) ) {
            BRZ_Connections::init();
        }
    }

    public static function register() {
        register_setting( 'brz_group', BRZ_OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
        register_setting(
            'brz_group',
            'myplugin_enable_wc_product_shortcodes',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => array( __CLASS__, 'sanitize_wc_product_shortcodes_option' ),
                'default'           => 0,
            )
        );

        add_settings_section( 'brz_main', 'نمایش و تجربه کاربری', '__return_false', 'brz-settings' );

        add_settings_field( 'enable_css', 'فعال‌سازی CSS', function(){
            $v = self::get( 'enable_css', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[enable_css]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[enable_css]" value="1" '.checked( 1, $v, false ).'> بارگذاری استایل</label>';
            echo '<p class="description">با غیرفعال‌شدن، هیچ استایلی تزریق نمی‌شود.</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'inline_css', 'درون‌خطی کردن CSS', function(){
            $v = self::get( 'inline_css', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[inline_css]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[inline_css]" value="1" '.checked( 1, $v, false ).'> افزودن CSS به صورت inline</label>';
            echo '<p class="description">درون‌خطی باعث حذف درخواست فایل جداگانه می‌شود و برای سرعت بهتر است.</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'brand_color', 'رنگ برند', function(){
            $v = esc_attr( self::get( 'brand_color', '#1a73e8' ) );
            echo '<input type="text" class="regular-text" name="'.BRZ_OPTION.'[brand_color]" value="'.$v.'" />';
            echo '<p class="description">مثال: #1a73e8</p>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'enable_js', 'فعال‌سازی JS', function(){
            $v = self::get( 'enable_js', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[enable_js]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[enable_js]" value="1" '.checked( 1, $v, false ).'> بارگذاری اسکریپت آکاردئون</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'single_open', 'فقط یک مورد باز', function(){
            $v = self::get( 'single_open', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[single_open]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[single_open]" value="1" '.checked( 1, $v, false ).'> همواره فقط یک سؤال باز باشد</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_field( 'animate', 'انیمیشن نرم', function(){
            $v = self::get( 'animate', 1 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[animate]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[animate]" value="1" '.checked( 1, $v, false ).'> فعال</label>';
        }, 'brz-settings', 'brz_main' );

        add_settings_section( 'brz_tables', 'استایل جداول', '__return_false', 'brz-settings' );

        add_settings_field( 'table_styles_enabled', 'فعال‌سازی استایل جداول', function() {
            $enabled = (bool) self::get( 'table_styles_enabled', 0 );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[table_styles_enabled]" value="0" />';
            echo '<label><input type="checkbox" name="'.BRZ_OPTION.'[table_styles_enabled]" value="1" '.checked( true, $enabled, false ).'> استایل بایروز برای جداول فعال شود</label>';
            echo '<p class="description">برای فعال شدن نیاز است محدودهٔ اعمال را در گزینهٔ زیر انتخاب کنید.</p>';
        }, 'brz-settings', 'brz_tables' );

        add_settings_field( 'table_styles_targets', 'محدودهٔ اعمال', function() {
            $selected = (array) self::get( 'table_styles_targets', array() );
            $targets  = array(
                'product'  => 'محصولات',
                'page'     => 'برگه‌ها',
                'category' => 'دسته‌بندی‌ها',
            );
            echo '<input type="hidden" name="'.BRZ_OPTION.'[table_styles_targets_submitted]" value="1" />';
            foreach ( $targets as $key => $label ) {
                $checked = in_array( $key, $selected, true );
                echo '<label style="display:block;margin-bottom:6px;">';
                echo '<input type="checkbox" name="'.BRZ_OPTION.'[table_styles_targets][]" value="'.esc_attr( $key ).'" '.checked( true, $checked, false ).'> '.esc_html( $label );
                echo '</label>';
            }
            echo '<p class="description">حداقل یکی از گزینه‌ها را انتخاب کنید تا استایل روی همان بخش‌ها اعمال شود.</p>';
        }, 'brz-settings', 'brz_tables' );


    }

    public static function page() {
        $capability = self::CAPABILITY;

        add_menu_page(
            'تنظیمات بایروز',
            'تنظیمات بایروز',
            $capability,
            self::PARENT_SLUG,
            array( __CLASS__, 'render_page' ),
            'dashicons-admin-generic',
            self::MENU_POSITION
        );

        add_submenu_page(
            self::PARENT_SLUG,
            'تنظیمات عمومی',
            'تنظیمات عمومی',
            $capability,
            'buyruz-general',
            array( __CLASS__, 'render_page' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            'استایل',
            'استایل',
            $capability,
            'buyruz-style',
            array( __CLASS__, 'render_page' )
        );



        foreach ( self::module_nav_items() as $slug => $meta ) {
            add_submenu_page(
                self::PARENT_SLUG,
                isset( $meta['label'] ) ? $meta['label'] : $slug,
                isset( $meta['label'] ) ? $meta['label'] : $slug,
                $capability,
                'buyruz-module-' . $slug,
                array( __CLASS__, 'render_page' )
            );
        }

        global $submenu;
        if ( isset( $submenu[ self::PARENT_SLUG ][0][0] ) ) {
            $submenu[ self::PARENT_SLUG ][0][0] = 'پیشخوان';
        }
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::PARENT_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'buyruz-general' === $page ) {
            self::render_general_settings();
            return;
        }

        if ( 'buyruz-style' === $page ) {
            self::render_style_settings();
            return;
        }


        if ( 'buyruz-connections' === $page ) {
            $_GET['page'] = 'buyruz-module-smart_linker'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $_GET['tab']  = 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::render_module_settings( 'smart_linker' );
            return;
        }

        if ( strpos( $page, 'buyruz-module-' ) === 0 ) {
            $slug = substr( $page, strlen( 'buyruz-module-' ) );
            self::render_module_settings( $slug );
            return;
        }

        self::render_dashboard();
    }

    private static function render_shell( $active_slug, callable $content_cb ) {
        $brand = esc_attr( self::get( 'brand_color', '#1a73e8' ) );
        ?>
        <div class="brz-admin-wrap" dir="rtl" style="--brz-brand: <?php echo $brand; ?>;">
            <div id="brz-snackbar" class="brz-snackbar" aria-live="polite"></div>
            <?php self::render_hero( $active_slug ); ?>
            <div class="brz-layout brz-layout--single" style="display:block;">
                <div class="brz-content" style="margin-right:0;">
                    <?php call_user_func( $content_cb ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function get_page_metadata( $active_slug ) {
        $meta = array(
            'buyruz-dashboard' => array(
                'title'       => 'پیشخوان',
                'description' => 'پیشخوان مدیریت و کنترل سریع ماژول‌های فعال بایروز.',
            ),
            'buyruz-general' => array(
                'title'       => 'تنظیمات عمومی',
                'description' => 'تنظیمات همگانی، گزینه‌های پردازش شورت‌کد و دوره‌های پاکسازی لاگ‌ها.',
            ),
            'buyruz-connections' => array(
                'title'       => 'ارتباطات',
                'description' => 'مدیریت کلیدهای امنیتی و همگام‌سازی ارتباط بین سایت‌ها.',
            ),
            'buyruz-style' => array(
                'title'       => 'استایل',
                'description' => 'سفارشی‌سازی ظاهر، انیمیشن‌ها، رنگ برند و قوانین استایل‌دهی جداول.',
            ),
            'buyruz-module-compare_table' => array(
                'title'       => 'جدول متا',
                'description' => 'تنظیمات پیش‌فرض، نام ستون‌ها و مدیریت فعال‌سازی جدول مقایسهٔ محصول.',
            ),
            'buyruz-module-faq_rankmath' => array(
                'title'       => 'سوالات متداول (Rank Math)',
                'description' => 'تبدیل خودکار خروجی FAQ افزونه Rank Math به آکاردئون متحرک بایروز.',
            ),
            'buyruz-module-smart_linker' => array(
                'title'       => 'لینک‌ساز هوشمند',
                'description' => 'لینک‌سازی داخلی هوشمند با AI - سینک یکپارچه بین سایت‌ها.',
            ),
            'buyruz-module-bi_exporter' => array(
                'title'       => 'تحلیل سایت',
                'description' => 'اجرای پس‌زمینه و دریافت فایل JSON فشرده برای ممیزی سئو و داده.',
            ),
            'buyruz-module-outbound_guard' => array(
                'title'       => 'فایروال HTTP',
                'description' => 'کنترل و فیلتر کردن هوشمند درخواست‌های خروجی وردپرس برای امنیت بیشتر.',
            ),
            'buyruz-module-static_controller' => array(
                'title'       => 'کنترلر استاتیک',
                'description' => 'تولید و مدیریت کش استاتیک صفحات برای افزایش سرعت فوق‌العاده سایت.',
            ),
            'buyruz-module-offline_bridge' => array(
                'title'       => 'پل آفلاین',
                'description' => 'اعمال آفلاین تغییرات محصولات از Google Sheet — وقتی ارسال مستقیم ممکن نیست.',
            ),
            'buyruz-module-offline_bridge_log' => array(
                'title'       => 'لاگ تغییرات',
                'description' => 'تاریخچه تغییرات فیلدهای محصولات از تمام منابع.',
            ),
            'buyruz-module-label_overrides' => array(
                'title'       => 'ویرایش برچسب‌ها',
                'description' => 'جایگزینی متن‌های ترجمه‌شده تم بدون ویرایش فایل‌ها.',
            ),
        );

        return isset( $meta[ $active_slug ] ) ? $meta[ $active_slug ] : array(
            'title'       => 'تنظیمات',
            'description' => '',
        );
    }

    private static function render_hero( $active_slug ) {
        $metadata = self::get_page_metadata( $active_slug );
        $is_dashboard = ( 'buyruz-dashboard' === $active_slug );
        
        // Check if it's a module and get its status
        $actions_html = '';
        if ( strpos( $active_slug, 'buyruz-module-' ) === 0 ) {
            $module_slug = substr( $active_slug, strlen( 'buyruz-module-' ) );
            if ( 'offline_bridge_log' === $module_slug ) {
                $module_slug = 'offline_bridge';
            }
            $states  = BRZ_Modules::get_states();
            if ( isset( $states[ $module_slug ] ) ) {
                $active = ! empty( $states[ $module_slug ] );
                $status_text = $active ? 'فعال است' : 'غیرفعال است';
                $status_class = $active ? 'is-on' : 'is-off';
                $actions_html = '<span class="brz-status ' . $status_class . '">' . esc_html( $status_text ) . '</span>';
            }
        }
        ?>
        <div class="brz-hero">
            <div class="brz-hero__content">
                <div class="brz-hero__title-row">
                    <?php if ( $is_dashboard ) : ?>
                        <h1>تنظیمات بایروز</h1>
                    <?php else : ?>
                        <div class="brz-hero__breadcrumbs">
                            <span class="brz-hero__plugin-title">تنظیمات بایروز</span>
                            <span class="brz-hero__separator">/</span>
                            <h1><?php echo esc_html( $metadata['title'] ); ?></h1>
                        </div>
                    <?php endif; ?>
                    <span class="brz-hero__version">نسخه <?php echo esc_html( BRZ_VERSION ); ?></span>
                </div>
                <?php if ( ! empty( $metadata['description'] ) ) : ?>
                    <p class="brz-hero__desc" style="margin-top:var(--md-space-sm);"><?php echo esc_html( $metadata['description'] ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $actions_html ) ) : ?>
                <div class="brz-hero__actions" style="display:flex; align-items:center; gap:var(--md-space-md);">
                    <?php echo $actions_html; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_notices() {
        settings_errors( 'brz_group' );

        if ( empty( $_GET['brz-msg'] ) || empty( $_GET['module'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $msg    = sanitize_key( wp_unslash( $_GET['brz-msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $module = sanitize_key( wp_unslash( $_GET['module'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $registry = BRZ_Modules::registry();
        $label    = isset( $registry[ $module ]['label'] ) ? $registry[ $module ]['label'] : $module;

        if ( 'module-on' === $msg ) {
            echo '<div class="notice notice-success"><p>' . esc_html( sprintf( '%s فعال شد.', $label ) ) . '</p></div>';
        } elseif ( 'module-off' === $msg ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( '%s غیرفعال شد.', $label ) ) . '</p></div>';
        } elseif ( 'module-error' === $msg ) {
            echo '<div class="notice notice-error"><p>' . esc_html( sprintf( 'تغییر وضعیت %s امکان‌پذیر نبود.', $label ) ) . '</p></div>';
        }
    }

    private static function render_dashboard() {
        $modules = BRZ_Modules::registry();
        $states  = BRZ_Modules::get_states();

        self::render_shell( self::PARENT_SLUG, function() use ( $modules, $states ) {
            self::render_notices();
            ?>
            <div class="brz-section-header brz-section-header--modules">
                <div>
                    <h2>پیشخوان ماژول‌ها</h2>
                    <p>شبکهٔ مدرن و واکنش‌گرا برای کنترل سریع ماژول‌ها.</p>
                </div>
            </div>

            <div class="brz-module-grid">
                        <?php foreach ( $modules as $slug => $meta ) : ?>
                            <?php $enabled = ! empty( $states[ $slug ] ); ?>
                            <?php $icon = self::module_icon_letter( $meta ); ?>
                            <div class="brz-module-card <?php echo $enabled ? 'is-active' : 'is-inactive'; ?>" data-module="<?php echo esc_attr( $slug ); ?>">
                                <div class="brz-module-card__badge">ماژول</div>
                                <div class="brz-module-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
                                <h3 class="brz-module-card__title"><?php echo esc_html( $meta['label'] ); ?></h3>
                                <?php if ( ! empty( $meta['description'] ) ) : ?>
                                    <p class="brz-module-card__desc"><?php echo esc_html( $meta['description'] ); ?></p>
                                <?php endif; ?>
                                <?php
                                if ( 'faq_rankmath' === $slug && ! class_exists( '\RankMath\Schema\DB' ) ) {
                                    echo '<p class="brz-warning">برای استفاده، افزونه Rank Math باید فعال باشد.</p>';
                                }
                                ?>
                                <div class="brz-module-card__footer">
                                    <div class="brz-toggle-wrap">
                                        <span class="brz-toggle-label"><?php echo $enabled ? 'روشن' : 'خاموش'; ?></span>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-toggle-form" data-module="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $meta['label'] ); ?>">
                                            <?php wp_nonce_field( 'brz_toggle_module_' . $slug ); ?>
                                            <input type="hidden" name="action" value="brz_toggle_module" />
                                            <input type="hidden" name="module" value="<?php echo esc_attr( $slug ); ?>" />
                                            <input type="hidden" name="state" value="<?php echo $enabled ? '0' : '1'; ?>" />
                                            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>" />
                                            <button type="submit" class="brz-toggle-switch <?php echo $enabled ? 'is-on' : 'is-off'; ?>">
                                                <span class="screen-reader-text"><?php echo $enabled ? 'غیرفعال کردن ماژول' : 'فعال کردن ماژول'; ?></span>
                                            </button>
                                        </form>
                                    </div>
                                    <?php if ( $enabled ) : ?>
                                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-' . $slug ) ); ?>">تنظیمات</a>
                                    <?php else : ?>
                                        <span class="brz-link" style="opacity: 0.5; cursor: not-allowed; text-decoration: none;" title="برای دسترسی به تنظیمات ابتدا ماژول را روشن کنید.">تنظیمات</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
            <?php
        } );
    }

    private static function render_general_settings() {
        self::render_shell( 'buyruz-general', function() {
            self::render_notices();
            ?>


            <div class="brz-single-column">
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>پردازش شورت‌کد در توضیحات محصول</h3>
                    </div>
                    <div class="brz-card__body">
                        <p>اجرای شورت‌کدها در تب توضیحات اصلی و خلاصهٔ محصولات ووکامرس. برای حفظ کارایی، به‌صورت پیش‌فرض خاموش است.</p>
                        <form method="post" action="options.php" class="brz-settings-form" data-context="wc-product-shortcodes">
                            <?php
                            settings_fields( 'brz_group' );
                            $wc_shortcodes = (bool) get_option( 'myplugin_enable_wc_product_shortcodes', 0 );
                            ?>
                            <input type="hidden" name="myplugin_enable_wc_product_shortcodes" value="0" />
                            <label>
                                <input type="checkbox" name="myplugin_enable_wc_product_shortcodes" value="1" <?php checked( true, $wc_shortcodes ); ?> />
                                پردازش شورت‌کدها در توضیحات و خلاصهٔ محصولات
                            </label>
                            <p class="description">فقط روی فرانت‌اند و صفحات محصول اعمال می‌شود و از درخواست‌های ادمین/REST دور نگه داشته شده است.</p>
                            <div class="brz-save-bar">
                                <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="brz-card">
                    <div class="brz-card__header">
                        <h3>تنظیمات پل آفلاین</h3>
                    </div>
                    <div class="brz-card__body">
                        <p>مدت زمان نگهداری لاگ تغییرات محصولات.</p>
                        <form method="post" action="options.php" class="brz-settings-form" data-context="offline-bridge">
                            <?php
                            settings_fields( 'brz_group' );
                            $retention = (int) BRZ_Settings::get( 'log_retention_days', 30 );
                            ?>
                            <input type="hidden" name="<?php echo BRZ_OPTION; ?>[brz_form_context]" value="offline_bridge" />
                            <label>
                                مدت نگهداری لاگ (روز):
                                <input type="number" min="1" name="<?php echo BRZ_OPTION; ?>[log_retention_days]" value="<?php echo esc_attr( $retention ); ?>" class="small-text" />
                            </label>
                            <p class="description">لاگ‌های قدیمی‌تر از این تعداد روز به صورت خودکار حذف می‌شوند. (پیش‌فرض: ۳۰ روز)</p>
                            <div class="brz-save-bar">
                                <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php
        } );
    }

    private static function render_style_settings() {
        self::render_shell( 'buyruz-style', function() {
            ?>

            <div class="brz-single-column">
                <form method="post" action="options.php" class="brz-settings-form" data-context="general">
                    <?php
                    settings_fields( 'brz_group' );
                    echo '<input type="hidden" name="' . BRZ_OPTION . '[brz_form_context]" value="general" />';
                    self::render_section_cards( array( 'brz_main', 'brz_tables' ) );
                    ?>
                    <div class="brz-save-bar">
                        <?php submit_button( 'ذخیره تغییرات', 'primary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
            <?php
        } );
    }

    private static function render_module_settings( $module_slug ) {
        $modules = BRZ_Modules::registry();
        $states  = BRZ_Modules::get_states();
        $active  = ! empty( $states[ $module_slug ] );

        self::render_shell( 'buyruz-module-' . $module_slug, function() use ( $modules, $module_slug, $active ) {
            self::render_notices();

            if ( 'label_overrides' === $module_slug && $active ) {
                BRZ_Label_Overrides::render_admin_page();
                return;
            }

            if ( 'compare_table' === $module_slug ) {
                $compare_msg = isset( $_GET['brz-compare-msg'] ) ? sanitize_key( wp_unslash( $_GET['brz-compare-msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $compare_product = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ?>


                <?php if ( ! empty( $compare_msg ) ) : ?>
                    <?php
                    $notice_class = 'notice-info';
                    $notice_text  = '';
                    if ( 'deleted' === $compare_msg ) {
                        $notice_class = 'notice-success';
                        $notice_text  = 'جدول متا برای محصول ' . ( $compare_product ? '#' . $compare_product : '' ) . ' حذف شد.';
                    } elseif ( 'delete-error' === $compare_msg ) {
                        $notice_class = 'notice-error';
                        $notice_text  = 'حذف جدول متا امکان‌پذیر نبود. لطفاً دوباره تلاش کنید.';
                    } elseif ( 'create-error' === $compare_msg ) {
                        $notice_class = 'notice-error';
                        $notice_text  = 'ایجاد جدول جدید انجام نشد. شناسه محصول را بررسی کنید.';
                    }
                    ?>
                    <?php if ( ! empty( $notice_text ) ) : ?>
                        <div class="notice <?php echo esc_attr( $notice_class ); ?>"><p><?php echo esc_html( $notice_text ); ?></p></div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="brz-card">
                    <div class="brz-card__body">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-toggle-form" data-module="<?php echo esc_attr( $module_slug ); ?>" data-label="<?php echo esc_attr( $modules[ $module_slug ]['label'] ); ?>">
                            <?php wp_nonce_field( 'brz_toggle_module_' . $module_slug ); ?>
                            <input type="hidden" name="action" value="brz_toggle_module" />
                            <input type="hidden" name="module" value="<?php echo esc_attr( $module_slug ); ?>" />
                            <input type="hidden" name="state" value="<?php echo $active ? '0' : '1'; ?>" />
                            <input type="hidden" name="redirect" value="<?php echo esc_url( admin_url( 'admin.php?page=buyruz-module-' . $module_slug ) ); ?>" />
                            <button type="submit" class="brz-button <?php echo $active ? 'brz-button--ghost' : 'brz-button--primary'; ?>">
                                <?php echo $active ? 'غیرفعال کردن' : 'فعال کردن'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="brz-single-column">
                    <form method="post" action="options.php" class="brz-settings-form" data-context="compare">
                        <?php
                        settings_fields( 'brz_group' );
                        echo '<input type="hidden" name="' . BRZ_OPTION . '[brz_form_context]" value="compare" />';
                        self::render_section_cards( array( 'brz_compare' ) );
                        ?>
                        <div class="brz-save-bar">
                            <?php submit_button( 'ذخیره تنظیمات جدول متا', 'primary', 'submit', false ); ?>
                        </div>
                    </form>
                    <?php self::render_compare_tables_index_card(); ?>
                </div>
                <?php
                return;
            }

            if ( 'faq_rankmath' === $module_slug ) {
                self::render_rankmath_module_card( $active );
                return;
            }

            if ( 'smart_linker' === $module_slug ) {
                self::render_shell( 'buyruz-module-' . $module_slug, function() {
                    BRZ_Smart_Linker::render_module_content();
                } );
                return;
            }

            if ( 'bi_exporter' === $module_slug ) {
                BRZ_BI_Exporter::render_admin_page();
                return;
            }

            if ( 'outbound_guard' === $module_slug ) {
                BRZ_Firewall::render_admin_page();
                return;
            }

            if ( 'static_controller' === $module_slug ) {
                BRZ_Static_Controller::render_admin_page();
                return;
            }

            if ( 'offline_bridge' === $module_slug ) {
                BRZ_Offline_Bridge::render_page();
                return;
            }

            $label = isset( $modules[ $module_slug ]['label'] ) ? $modules[ $module_slug ]['label'] : $module_slug;
            self::render_generic_module_card( $label, $active );
        } );
    }

    private static function render_rankmath_module_card( $active ) {
        $rankmath_active = class_exists( '\RankMath\Schema\DB' );
        ?>


        <div class="brz-grid">
            <div class="brz-grid__main">
                <div class="brz-card">
                    <div class="brz-card__body">
                        <ul class="brz-checklist">
                            <li>نیازمند افزونه Rank Math فعال.</li>
                            <li>در صفحات دارای FAQ Rank Math به صورت خودکار HTML را به آکاردئون تبدیل می‌کند.</li>
                            <li>برای توقف موقت، از پیشخوان ماژول را غیرفعال کنید.</li>
                        </ul>
                        <?php if ( ! $rankmath_active ) : ?>
                            <p class="brz-warning">Rank Math روی این سایت فعال نیست. پس از فعال‌سازی، این ماژول به صورت خودکار FAQها را مدیریت می‌کند.</p>
                        <?php endif; ?>
                    </div>
                    <div class="brz-card__footer">
                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">رفتن به پیشخوان برای تغییر وضعیت ماژول</a>
                    </div>
                </div>
            </div>
            <aside class="brz-grid__aside">
                <?php self::render_support_card(
                    'نکات هماهنگی',
                    array(
                        'با خاموش شدن ماژول، هیچ خروجی یا فایل اضافه‌ای بارگذاری نمی‌شود.',
                        'در حالت موبایل، فاصله‌ها فشرده می‌شوند تا FAQ خواناتر باشد.',
                        'برای تغییر رنگ برند، از تنظیمات عمومی استفاده کنید.',
                    ),
                    'سازگاری'
                ); ?>
            </aside>
        </div>
        <?php
    }

    private static function render_generic_module_card( $label, $active ) {
        ?>
        <div class="brz-grid">
            <div class="brz-grid__main">
                <div class="brz-card">
                    <div class="brz-card__header">
                        <h2><?php echo esc_html( $label ); ?></h2>
                        <p>در حال حاضر تنظیمات اختصاصی برای این ماژول تعریف نشده است.</p>
                    </div>
                    <div class="brz-card__body">
                        <p>برای تغییر وضعیت فعال/غیرفعال به پیشخوان برگردید.</p>
                        <p class="brz-status <?php echo $active ? 'is-on' : 'is-off'; ?>"><?php echo $active ? 'فعال' : 'غیرفعال'; ?></p>
                    </div>
                    <div class="brz-card__footer">
                        <a class="brz-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">بازگشت به پیشخوان</a>
                    </div>
                </div>
            </div>
            <aside class="brz-grid__aside">
                <?php self::render_support_card(
                    'ساده و تمیز',
                    array(
                        'تا زمانی که فعال نباشد، هیچ فایل یا هوکی از این ماژول لود نمی‌شود.',
                        'می‌توانید بعداً تنظیمات اختصاصی را اضافه کنید بدون آنکه دیتابیس آلوده شود.',
                        'برای فعال/غیرفعال کردن، از پیشخوان استفاده کنید؛ لحظه‌ای اعمال می‌شود.',
                    ),
                    'اطلاعات'
                ); ?>
            </aside>
        </div>
        <?php
    }

    private static function render_compare_tables_index_card() {
        $tables   = BRZ_Compare_Table_Admin::get_tables_index();
        $redirect = admin_url( 'admin.php?page=buyruz-module-compare_table' );
        ?>
        <div class="brz-card">
            <div class="brz-card__header">
                <h3>فهرست جداول متا</h3>
                <p>شناسه یکتا، شورت‌کد و لینک ویرایش جدول برای هر محصول.</p>
            </div>
            <div class="brz-card__body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="brz-inline-form">
                    <?php wp_nonce_field( 'brz_create_compare_table' ); ?>
                    <input type="hidden" name="action" value="brz_create_compare_table" />
                    <input type="hidden" name="redirect" value="<?php echo esc_url( $redirect ); ?>" />
                    <label for="brz-compare-product-id"><strong>ایجاد جدول جدید برای محصول</strong></label>
                    <input id="brz-compare-product-id" type="number" name="product_id" min="1" required style="width:140px;" placeholder="ID محصول" />
                    <button type="submit" class="brz-button brz-button--primary">ایجاد و ویرایش</button>
                </form>

                <?php if ( empty( $tables ) ) : ?>
                    <p class="description">هنوز جدولی ثبت نشده است.</p>
                <?php else : ?>
                    <div class="brz-table-responsive">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>محصول</th>
                                    <th>شناسه و شورت‌کد</th>
                                    <th>تعداد ستون</th>
                                    <th>تعداد ردیف</th>
                                    <th>اقدامات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $tables as $item ) : ?>
                                    <?php
                                    $product_id  = $item['product_id'];
                                    $product_link = get_edit_post_link( $product_id, '' );
                                    $col_count   = is_array( $item['columns'] ) ? count( $item['columns'] ) : 0;
                                    $row_count   = is_array( $item['rows'] ) ? count( $item['rows'] ) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $item['product_title'] ); ?></strong>
                                            <div class="description">#<?php echo esc_html( $product_id ); ?></div>
                                            <?php if ( $product_link ) : ?>
                                                <a class="brz-link" href="<?php echo esc_url( $product_link ); ?>">ویرایش محصول</a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code dir="ltr"><?php echo esc_html( $item['table_id'] ); ?></code>
                                            <div class="description" dir="ltr">[buyruz_compare_table id="<?php echo esc_attr( $item['table_id'] ); ?>"]</div>
                                        </td>
                                        <td><?php echo esc_html( $col_count ); ?></td>
                                        <td><?php echo esc_html( $row_count ); ?></td>
                                        <td class="brz-compare-table-actions">
                                            <?php if ( ! empty( $item['edit_url'] ) ) : ?>
                                                <a class="brz-button brz-button--ghost" href="<?php echo esc_url( $item['edit_url'] ); ?>">ویرایش جدول</a>
                                            <?php endif; ?>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('حذف جدول برای این محصول قطعی است؟');">
                                                <?php wp_nonce_field( 'brz_delete_compare_table_' . $product_id ); ?>
                                                <input type="hidden" name="action" value="brz_delete_compare_table" />
                                                <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
                                                <input type="hidden" name="redirect" value="<?php echo esc_url( $redirect ); ?>" />
                                                <button type="submit" class="brz-button brz-button--ghost">حذف جدول</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_section_cards( array $sections ) {
        $all_sections = self::sections_meta();
        echo '<div class="brz-card-stack">';
        foreach ( $sections as $id ) {
            if ( empty( $all_sections[ $id ] ) ) { continue; }
            $meta = $all_sections[ $id ];
            echo '<section class="brz-card" id="' . esc_attr( $id ) . '">';
            echo '<div class="brz-card__header">';
            echo '<h2>' . esc_html( $meta['title'] ) . '</h2>';
            if ( ! empty( $meta['description'] ) ) {
                echo '<p>' . esc_html( $meta['description'] ) . '</p>';
            }
            echo '</div>';
            echo '<div class="brz-card__body">';
            if ( isset( $meta['callback'] ) && is_callable( $meta['callback'] ) ) {
                call_user_func( $meta['callback'] );
            } else {
                echo '<table class="form-table" role="presentation"><tbody>';
                do_settings_fields( 'brz-settings', $id );
                echo '</tbody></table>';
            }
            echo '</div>';
            if ( ! empty( $meta['footer'] ) ) {
                echo '<div class="brz-card__footer">' . wp_kses_post( $meta['footer'] ) . '</div>';
            }
            echo '</section>';
        }
        echo '</div>';
    }





    private static function module_icon_letter( $meta ) {
        $label = isset( $meta['label'] ) ? $meta['label'] : '';
        if ( function_exists( 'mb_substr' ) ) {
            $char = mb_substr( $label, 0, 1, 'UTF-8' );
        } else {
            $char = substr( $label, 0, 1 );
        }
        return $char ? $char : '•';
    }

    private static function render_guidelines_card() {
        ?>
        <div class="brz-card">
            <div class="brz-card__header">
                <h2>راهنمای توسعه و پاکسازی</h2>
            </div>
            <div class="brz-card__body">
                <ul class="brz-checklist">
                    <li>هر ماژول جدید باید در صورت غیرفعال شدن، داده‌های خود را از دیتابیس یا کش پاک کند.</li>
                    <li>بارگذاری فایل‌ها باید فقط در صورت نیاز هر صفحه انجام شود؛ از هوک‌های شرطی یا دیفر استفاده کنید.</li>
                    <li>برای حفظ سرعت، اسکریپت‌ها و استایل‌های بایروز را در یک صف نگه دارید و از وابستگی‌های سنگین پرهیز کنید.</li>
                    <li>رابط کاربری باید با الگوهای طراحی وردپرس هماهنگ باشد اما حس مدرن و ساده‌ای ارائه دهد.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    private static function render_support_card( $title, array $items, $badge = '' ) {
        echo '<div class="brz-side-card">';
        if ( ! empty( $badge ) ) {
            echo '<span class="brz-side-card__badge">' . esc_html( $badge ) . '</span>';
        }
        echo '<h3 class="brz-side-card__title">' . esc_html( $title ) . '</h3>';
        if ( ! empty( $items ) ) {
            echo '<ul class="brz-side-card__list">';
            foreach ( $items as $item ) {
                echo '<li>' . esc_html( $item ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'buyruz-' ) === false ) {
            return;
        }
        wp_enqueue_style( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.css', array(), BRZ_VERSION );
        wp_enqueue_script( 'brz-settings-admin', BRZ_URL . 'assets/admin/settings.js', array(), BRZ_VERSION, true );
        wp_localize_script(
            'brz-settings-admin',
            'brzSettings',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'successOn'    => 'ماژول فعال شد',
                'successOff'   => 'ماژول غیرفعال شد',
                'failText'     => 'تغییر وضعیت انجام نشد. دوباره تلاش کنید.',
                'nonceField'   => '_wpnonce',
                'screenReader' => 'تغییر وضعیت ماژول',
                'saveNonce'    => wp_create_nonce( 'brz_save_settings' ),
                'savingText'   => 'در حال ذخیره...',
                'savedText'    => 'تنظیمات ذخیره شد',
                'saveFailText' => 'ذخیره انجام نشد. دوباره تلاش کنید.',
            )
        );
    }

    public static function handle_toggle_module() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'شما مجوز کافی ندارید.', 'buyruz' ) );
        }

        $slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $state = isset( $_POST['state'] ) ? (int) wp_unslash( $_POST['state'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=' . self::PARENT_SLUG ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $redirect ) ) {
            $redirect = admin_url( 'admin.php?page=' . self::PARENT_SLUG );
        }

        if ( empty( $slug ) || null === $state ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => 'module-error', 'module' => $slug ), $redirect ) );
            exit;
        }

        check_admin_referer( 'brz_toggle_module_' . $slug );

        $result = self::toggle_module_state( $slug, $state );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => 'module-error', 'module' => $slug ), $redirect ) );
            exit;
        }

        $msg = $state ? 'module-on' : 'module-off';
        wp_safe_redirect( add_query_arg( array( 'page' => self::PARENT_SLUG, 'brz-msg' => $msg, 'module' => $slug ), $redirect ) );
        exit;
    }

    public static function handle_toggle_module_ajax() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        $slug  = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $state = isset( $_POST['state'] ) ? (int) wp_unslash( $_POST['state'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( empty( $slug ) || null === $state ) {
            wp_send_json_error( array( 'message' => 'دادهٔ نامعتبر' ), 400 );
        }

        check_ajax_referer( 'brz_toggle_module_' . $slug );

        $result = self::toggle_module_state( $slug, $state );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success(
            array(
                'state'  => $result['state'],
                'label'  => $result['label'],
                'text'   => $result['state'] ? 'فعال' : 'غیرفعال',
                'status' => $result['state'] ? 'on' : 'off',
            )
        );
    }

    public static function handle_save_settings_ajax() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
        }

        check_ajax_referer( 'brz_save_settings', 'security' );

        $input = isset( $_POST[ BRZ_OPTION ] ) ? (array) wp_unslash( $_POST[ BRZ_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sanitized = self::sanitize( $input );

        update_option( BRZ_OPTION, $sanitized, false );
        self::$options_cache = $sanitized;

        $extra_saved = self::save_extra_options_from_post();

        wp_send_json_success(
            array(
                'message' => 'تنظیمات ذخیره شد.',
                'accent'  => isset( $sanitized['brand_color'] ) ? $sanitized['brand_color'] : self::get( 'brand_color', '#1a73e8' ),
                'options' => $extra_saved,
            )
        );
    }

    private static function save_extra_options_from_post() {
        $map = array(
            'myplugin_enable_wc_product_shortcodes' => array( __CLASS__, 'sanitize_wc_product_shortcodes_option' ),
        );

        $saved = array();

        foreach ( $map as $option => $callback ) {
            $raw = isset( $_POST[ $option ] ) ? wp_unslash( $_POST[ $option ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $value = is_callable( $callback ) ? call_user_func( $callback, $raw ) : ( empty( $raw ) ? 0 : 1 );
            update_option( $option, $value ? 1 : 0, false );
            $saved[ $option ] = $value ? 1 : 0;
        }

        return $saved;
    }

    public static function handle_delete_compare_table() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'شما مجوز کافی ندارید.', 'buyruz' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $redirect   = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=buyruz-module-compare_table' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $redirect ) ) {
            $redirect = admin_url( 'admin.php?page=buyruz-module-compare_table' );
        }

        $nonce_action = 'brz_delete_compare_table_' . $product_id;
        if ( ! $product_id || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), $nonce_action ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wp_safe_redirect( add_query_arg( array( 'page' => 'buyruz-module-compare_table', 'brz-compare-msg' => 'delete-error' ), $redirect ) );
            exit;
        }

        BRZ_Compare_Table_Admin::delete_table( $product_id );
        wp_safe_redirect( add_query_arg( array( 'page' => 'buyruz-module-compare_table', 'brz-compare-msg' => 'deleted', 'product' => $product_id ), $redirect ) );
        exit;
    }

    public static function handle_create_compare_table() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'شما مجوز کافی ندارید.', 'buyruz' ) );
        }

        $redirect   = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : admin_url( 'admin.php?page=buyruz-module-compare_table' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ( empty( $redirect ) ) {
            $redirect = admin_url( 'admin.php?page=buyruz-module-compare_table' );
        }

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'brz_create_compare_table' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wp_safe_redirect( add_query_arg( array( 'page' => 'buyruz-module-compare_table', 'brz-compare-msg' => 'create-error' ), $redirect ) );
            exit;
        }

        if ( ! $product_id ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'buyruz-module-compare_table', 'brz-compare-msg' => 'create-error' ), $redirect ) );
            exit;
        }

        $product = get_post( $product_id );
        if ( ! $product || 'product' !== $product->post_type ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'buyruz-module-compare_table', 'brz-compare-msg' => 'create-error' ), $redirect ) );
            exit;
        }

        $existing = BRZ_Compare_Table_Admin::get_meta( $product_id );
        $table_id = BRZ_Compare_Table_Admin::get_table_id( $product_id );

        if ( empty( $existing ) ) {
            $columns = array( '', '' );
            $rows    = array( array( '', '' ) );
            $payload = array(
                'id'      => $table_id,
                'title'   => '',
                'columns' => $columns,
                'rows'    => $rows,
            );

            update_post_meta( $product_id, BRZ_Compare_Table_Admin::META_ID_KEY, $table_id );
            update_post_meta( $product_id, BRZ_Compare_Table_Admin::META_KEY, wp_json_encode( $payload ) );
        }

        $edit_url = BRZ_Compare_Table_Admin::build_editor_url( $product_id );
        wp_safe_redirect( $edit_url ? $edit_url : $redirect );
        exit;
    }

    private static function toggle_module_state( $slug, $state ) {
        $registry = BRZ_Modules::registry();
        if ( empty( $registry[ $slug ] ) ) {
            return new WP_Error( 'brz_invalid_module', 'ماژول معتبر نیست' );
        }

        $states = BRZ_Modules::get_states();
        $states[ $slug ] = $state ? 1 : 0;

        $current = self::get();
        if ( ! is_array( $current ) ) {
            $current = array();
        }

        $current['modules'] = $states;
        update_option( BRZ_OPTION, $current, false );
        self::$options_cache = $current;

        $label = isset( $registry[ $slug ]['label'] ) ? $registry[ $slug ]['label'] : $slug;

        return array(
            'state' => $states[ $slug ],
            'label' => $label,
        );
    }

    public static function sanitize_wc_product_shortcodes_option( $value ) {
        return empty( $value ) ? 0 : 1;
    }

    public static function add_plugin_action_links( $links ) {
        $settings_link = '<a href="admin.php?page=' . self::PARENT_SLUG . '">تنظیمات</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }



    public static function sanitize( $input ) {
        $existing = self::get();
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        $output  = $existing;
        $context = '';

        if ( isset( $input['brz_form_context'] ) ) {
            $context = sanitize_key( $input['brz_form_context'] );
            unset( $input['brz_form_context'] );
        }

        // Modules (preserve by default)
        if ( isset( $input['modules'] ) && is_array( $input['modules'] ) ) {
            $states = array();
            foreach ( BRZ_Modules::registry() as $slug => $meta ) {
                $states[ $slug ] = ! empty( $input['modules'][ $slug ] ) ? 1 : 0;
            }
            $output['modules'] = $states;
            unset( $input['modules'] );
        } elseif ( ! isset( $output['modules'] ) ) {
            $output['modules'] = BRZ_Modules::default_states();
        }

        // General settings.
        if ( 'general' === $context || isset( $input['enable_css'] ) ) {
            $checkboxes = array( 'enable_css', 'inline_css', 'enable_js', 'single_open', 'animate', 'table_styles_enabled' );
            foreach ( $checkboxes as $checkbox ) {
                if ( array_key_exists( $checkbox, $input ) ) {
                    $output[ $checkbox ] = $input[ $checkbox ] ? 1 : 0;
                    unset( $input[ $checkbox ] );
                } elseif ( 'general' === $context && array_key_exists( $checkbox, $output ) ) {
                    $output[ $checkbox ] = $output[ $checkbox ] ? 1 : 0;
                }
            }

            if ( isset( $input['brand_color'] ) ) {
                $output['brand_color'] = sanitize_text_field( $input['brand_color'] );
                unset( $input['brand_color'] );
            }

            $tables_submitted = isset( $input['table_styles_targets_submitted'] );
            if ( $tables_submitted && isset( $input['table_styles_targets'] ) ) {
                $targets = array_map( 'sanitize_text_field', (array) $input['table_styles_targets'] );
                $allowed = array( 'product', 'page', 'category' );
                $output['table_styles_targets'] = array_values( array_intersect( $targets, $allowed ) );
                unset( $input['table_styles_targets'] );
            } elseif ( $tables_submitted ) {
                $output['table_styles_targets'] = array();
            }

            unset( $input['table_styles_targets_submitted'] );
        }

        // Compare table settings.
        if ( 'compare' === $context ) {
            // فیلدهای پیش‌فرض جدول مقایسه حذف شده‌اند.
        }

        // Offline Bridge settings.
        if ( 'offline_bridge' === $context || isset( $input['log_retention_days'] ) ) {
            if ( isset( $input['log_retention_days'] ) ) {
                $retention_val = $input['log_retention_days'];
                if ( is_numeric( $retention_val ) && (int) $retention_val > 0 ) {
                    $output['log_retention_days'] = (int) $retention_val;
                }
                // Non-positive or non-numeric values are rejected; previous value is retained.
                unset( $input['log_retention_days'] );
            }
        }



        // Any remaining string values.
        foreach ( $input as $key => $value ) {
            if ( is_string( $value ) ) {
                $output[ $key ] = sanitize_text_field( $value );
            } else {
                $output[ $key ] = $value;
            }
        }

        self::$options_cache = $output;
        return $output;
    }
}
BRZ_Settings::init();
