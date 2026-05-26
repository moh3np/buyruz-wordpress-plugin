<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Offline_Transfer {
    private $admin;
    private $api;
    private $codec;
    private $processor;

    public function __construct() {
        require_once plugin_dir_path( __FILE__ ) . 'class-brz-transfer-codec.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-brz-transfer-processor.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-brz-transfer-admin.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-brz-transfer-api.php';

        $this->codec = new BRZ_Transfer_Codec();
        $this->processor = new BRZ_Transfer_Processor();
        
        $this->admin = new BRZ_Transfer_Admin();
        $this->api = new BRZ_Transfer_API( $this->codec, $this->processor );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'buyruz-offline-transfer' ) === false ) {
            return;
        }

        wp_enqueue_style( 'brz-transfer-css', plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'assets/admin/transfer.css', array(), BRZ_VERSION );
        wp_enqueue_script( 'brz-transfer-js', plugin_dir_url( dirname( dirname( __FILE__ ) ) ) . 'assets/admin/transfer.js', array('jquery'), BRZ_VERSION, true );
        wp_localize_script( 'brz-transfer-js', 'brzTransferData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brz_transfer_nonce' )
        ));
    }
}
