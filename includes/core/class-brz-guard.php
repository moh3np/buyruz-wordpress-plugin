<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Guard {
    const MIN_PHP = '8.3.0';

    public static function ready() {
        if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
            add_action( 'admin_notices', array( __CLASS__, 'php_notice' ) );
            return false;
        }
        return true;
    }

    public static function php_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html( sprintf( 'افزونه «تنظیمات بایروز» حداقل به PHP %s نیاز دارد. نسخهٔ فعلی سرور: %s', self::MIN_PHP, PHP_VERSION ) );
        echo '</p></div>';
    }
}
