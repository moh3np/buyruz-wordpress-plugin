<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Plugin {
    /**
     * Bootstrap core modules with context-aware loading.
     */
    public static function init(): void {
        if ( ! BRZ_Guard::ready() ) {
            return;
        }

        // Version-gated migrations (only when version changes)
        self::maybe_run_migrations();

        // Context detection
        $is_admin = is_admin();
        $is_rest  = defined( 'REST_REQUEST' ) && REST_REQUEST;

        // Admin-only core
        if ( $is_admin ) {
            BRZ_Settings::init();
            BRZ_Compare_Table_Admin::init();
        }

        // Frontend-only core
        if ( ! $is_admin && ! $is_rest ) {
            BRZ_FAQ_Renderer::init();
            BRZ_Compare_Table::init();
            BRZ_WC_Shortcodes::init();
        }

        // Always needed (REST fields for products, used by both admin and REST)
        BRZ_Rest::init();
        BRZ_Tag_Sync_Guard::init();

        // Dynamic modules (only active ones)
        $active = BRZ_Modules::active_classes();
        foreach ( $active as $class ) {
            if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
                call_user_func( array( $class, 'init' ) );
            }
        }
    }

    /**
     * Version-gated migrations — only runs when plugin version changes.
     */
    private static function maybe_run_migrations(): void {
        $stored = get_option( 'brz_db_version', '0' );
        if ( version_compare( $stored, BRZ_VERSION, '>=' ) ) {
            return;
        }

        // Smart Linker DB migration
        if ( BRZ_Modules::is_enabled( 'smart_linker' ) ) {
            if ( class_exists( 'BRZ_Smart_Linker_DB' ) ) {
                BRZ_Smart_Linker_DB::migrate();
            }
            if ( class_exists( 'BRZ_Smart_Linker_Health' ) ) {
                BRZ_Smart_Linker_Health::migrate();
            }
        }

        // Migrate old module slugs to new ones (4.1.3+)
        $opts = get_option( BRZ_OPTION, array() );
        if ( isset( $opts['modules'] ) && is_array( $opts['modules'] ) ) {
            $slug_renames = array(
                'urlgen'      => 'static_controller',
                'page_mapper' => 'static_controller',
                'price_queue' => 'offline_bridge',
            );
            $changed = false;
            foreach ( $slug_renames as $old => $new ) {
                if ( isset( $opts['modules'][ $old ] ) ) {
                    $opts['modules'][ $new ] = $opts['modules'][ $old ];
                    unset( $opts['modules'][ $old ] );
                    $changed = true;
                }
            }
            if ( $changed ) {
                update_option( BRZ_OPTION, $opts, false );
            }
        }

        update_option( 'brz_db_version', BRZ_VERSION, false );
    }
}
