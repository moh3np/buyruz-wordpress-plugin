<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lightweight class autoloader for BRZ_ prefixed classes.
 * Keeps file structure declarative so modules can grow without manual requires.
 * هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
 */
spl_autoload_register( function( $class ) {
    if ( strpos( $class, 'BRZ_' ) !== 0 ) {
        return;
    }

    $map = array(
        'BRZ_Plugin'                 => 'core/class-brz-plugin.php',
        'BRZ_Guard'                  => 'core/class-brz-guard.php',
        'BRZ_Modules'                => 'core/class-brz-modules.php',
        'BRZ_Settings'               => 'admin/class-brz-settings.php',
        'BRZ_Compare_Table_Admin'    => 'admin/class-brz-compare-table.php',
        'BRZ_Detector'               => 'front/class-brz-detector.php',
        'BRZ_Compare_Table'          => 'front/class-brz-compare-table.php',
        'BRZ_FAQ_Renderer'           => 'front/class-brz-faq-renderer.php',
        'BRZ_Tag_Sync_Guard'         => 'core/class-brz-tag-sync-guard.php',
        'BRZ_Rest'                   => 'integration/class-brz-rest.php',
        'BRZ_Smart_Linker'           => 'modules/smart-linker/class-brz-smart-linker.php',
        'BRZ_Smart_Linker_DB'        => 'modules/smart-linker/class-brz-smart-linker-db.php',
        'BRZ_Smart_Linker_Link_Injector' => 'modules/smart-linker/class-brz-smart-linker-link-injector.php',
        'BRZ_Smart_Linker_Sync'      => 'modules/smart-linker/class-brz-smart-linker-sync.php',
        'BRZ_Smart_Linker_Exporter'  => 'modules/smart-linker/class-brz-smart-linker-exporter.php',
        'BRZ_Smart_Linker_Importer'  => 'modules/smart-linker/class-brz-smart-linker-importer.php',
        'BRZ_Smart_Linker_SEO'       => 'modules/smart-linker/class-brz-smart-linker-seo.php',
        'BRZ_Smart_Linker_Health'    => 'modules/smart-linker/class-brz-smart-linker-health.php',
        'BRZ_Price_Queue'            => 'modules/price-queue/class-brz-price-queue.php',
        'BRZ_Offline_Bridge'         => 'modules/offline-bridge/class-brz-offline-bridge.php',
        'BRZ_Change_Log'             => 'modules/offline-bridge/class-brz-change-log.php',
        'BRZ_GSheet'                 => 'integration/class-brz-gsheet.php',
        'BRZ_Connections'            => 'admin/class-brz-connections.php',
        'BRZ_WC_Shortcodes'          => 'integration/class-brz-wc-shortcodes.php',
        'BRZ_Order_Processor'        => 'integration/class-brz-order-processor.php',
        'BRZ_BI_Exporter'            => 'modules/bi-exporter/class-brz-bi-exporter.php',
        'BRZ_Firewall'               => 'modules/http-firewall/class-brz-firewall.php',
        'BRZ_Firewall_Validator'     => 'modules/http-firewall/class-brz-firewall-validator.php',
        'BRZ_Static_Controller'      => 'modules/static-controller/class-brz-static-controller.php',
        'BRZ_Static_Page_Detector'   => 'modules/static-controller/class-brz-static-page-detector.php',
        'BRZ_Static_Map_Generator'   => 'modules/static-controller/class-brz-static-map-generator.php',
        'BRZ_Static_Change_Trigger'  => 'modules/static-controller/class-brz-static-change-trigger.php',
        'BRZ_Static_Modal_Injector'  => 'modules/static-controller/class-brz-static-modal-injector.php',
        'BRZ_Static_Sitemap_Importer' => 'modules/static-controller/class-brz-static-sitemap-importer.php',
        'BRZ_Static_Manual_Page_Manager' => 'modules/static-controller/class-brz-static-manual-page-manager.php',
    );

    $map = apply_filters( 'brz/autoload_map', $map );

    if ( empty( $map[ $class ] ) ) {
        return;
    }

    $path = BRZ_PATH . 'includes/' . ltrim( $map[ $class ], '/' );
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );
