<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Modules {
    public static function registry() {
        return array(
            'compare_table' => array(
                'label'       => 'جدول متا',
                'description' => 'جدول مقایسهٔ محصول را مدیریت و در فرانت نمایش می‌دهد.',
                'class'       => 'BRZ_Compare_Table',
            ),
            'smart_linker' => array(
                'label'       => 'لینک‌ساز هوشمند',
                'description' => 'سینک دوطرفه پیشنهاد لینک، تایید و تزریق خودکار با Google Sheet.',
                'class'       => 'BRZ_Smart_Linker',
            ),
            'bi_exporter' => array(
                'label'       => 'تحلیل سایت',
                'description' => 'خروجی JSON هوش تجاری و سئو برای اتصال به LLM.',
                'class'       => 'BRZ_BI_Exporter',
            ),
            'outbound_guard' => array(
                'label'       => 'فایروال HTTP',
                'description' => 'کنترل درخواست‌های خروجی وردپرس',
                'class'       => 'BRZ_Firewall',
            ),
            'order_processor' => array(
                'label'       => 'پردازش سفارش',
                'description' => 'REST API پردازش سفارشات از گوگل شیت',
                'class'       => 'BRZ_Order_Processor',
            ),
            'static_controller' => array(
                'label'       => 'کنترلر استاتیک',
                'description' => 'مدیریت صفحات و تولید نقشه URL برای ژنراتور استاتیک',
                'class'       => 'BRZ_Static_Controller',
            ),
            'offline_bridge' => array(
                'label'       => 'پل آفلاین',
                'description' => 'اعمال تغییرات محصول از Google Sheet بدون نیاز به اتصال مستقیم',
                'class'       => 'BRZ_Offline_Bridge',
            ),
            'label_overrides' => array(
                'label'       => 'ویرایش برچسب‌ها',
                'description' => 'جایگزینی متن‌های ترجمه‌شده تم از طریق فیلتر gettext',
                'class'       => 'BRZ_Label_Overrides',
            ),
            'ai_schema' => array(
                'label'       => 'مدیریت اسکیما AI',
                'description' => 'تزریق PropertyValue و itemCondition به اسکیمای محصول رنک‌مث.',
                'class'       => 'BRZ_AI_Schema',
            ),
            'product_guarantee_tab' => array(
                'label'       => 'تب ضمانت محصول',
                'description' => 'تب آکاردئونی ضمانت، ارسال و پشتیبانی در صفحه محصول ووکامرس.',
                'class'       => 'BRZ_Product_Guarantee_Tab',
            ),
            'a11y_fixes' => array(
                'label'       => 'رفع دسترسی‌پذیری',
                'description' => 'اصلاح خودکار مشکلات ARIA و ساختار HTML در صفحه محصول (WCAG 2.1 AA).',
                'class'       => 'BRZ_A11y_Fixes',
            ),
            'sso_portal' => array(
                'label'       => 'پورتال احراز هویت (SSO)',
                'description' => 'مدیریت متمرکز کاربران، دسترسی‌ها و لاگ‌ها برای پنل عملیات.',
                'class'       => 'BRZ_SSO_Portal',
            ),
            'product_specs' => array(
                'label'       => 'مشخصات فنی محصول',
                'description' => 'مدیریت و نمایش مشخصات فنی داینامیک و فیلدهای عددی سفارشی محصولات.',
                'class'       => 'BRZ_Product_Specs',
            ),
            'sidebar_filters' => array(
                'label'       => 'فیلتر سایدبار آرشیو',
                'description' => 'سیستم فیلتر محصولات پیشرفته سایدبار با استفاده از جدول جستجوی سفارشی و آژاکس.',
                'class'       => 'BRZ_Sidebar_Filters',
            ),
            'attributes_analyzer' => array(
                'label'       => 'آنالیز ویژگی‌ها',
                'description' => 'ارائه آمار دقیق استفاده از ویژگی‌ها و گزینه‌های ووکامرس برای هوش مصنوعی.',
                'class'       => 'BRZ_Attributes_Analyzer',
            ),
            'specs_exporter' => array(
                'label'       => 'برون‌بری مشخصات و ویژگی‌ها',
                'description' => 'خروجی یکباره از ویژگی‌های متایی و اتریبیوت‌های ووکامرس به صورت JSON.',
                'class'       => 'BRZ_Specs_Exporter',
            ),
        );
    }

    public static function default_states() {
        $states = array();
        $disabled_by_default = array( 'outbound_guard', 'order_processor', 'static_controller', 'label_overrides', 'ai_schema', 'product_guarantee_tab', 'sso_portal' );
        foreach ( self::registry() as $slug => $meta ) {
            $states[ $slug ] = in_array( $slug, $disabled_by_default, true ) ? 0 : 1;
        }
        return $states;
    }

    public static function is_enabled( $slug ) {
        $states = self::get_states();
        return ! empty( $states[ $slug ] );
    }

    public static function set_enabled( $slug, $enabled ) {
        $states = self::get_states();
        $states[ $slug ] = $enabled ? 1 : 0;
        update_option( BRZ_OPTION, wp_parse_args( array( 'modules' => $states ), get_option( BRZ_OPTION, array() ) ), false );
    }

    public static function active_classes() {
        $classes = array();
        $states  = self::get_states();
        foreach ( self::registry() as $slug => $meta ) {
            if ( ! empty( $states[ $slug ] ) && ! empty( $meta['class'] ) ) {
                $classes[] = $meta['class'];
            }
        }
        return $classes;
    }

    public static function get_states() {
        $opts = class_exists( 'BRZ_Settings' ) ? BRZ_Settings::get() : get_option( BRZ_OPTION, array() );
        if ( ! is_array( $opts ) ) {
            $opts = array();
        }
        $states = isset( $opts['modules'] ) && is_array( $opts['modules'] ) ? $opts['modules'] : array();
        return wp_parse_args( $states, self::default_states() );
    }
}
