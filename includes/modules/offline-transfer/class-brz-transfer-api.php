<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Transfer_API {
    
    private $codec;
    private $processor;

    public function __construct( $codec, $processor ) {
        $this->codec = $codec;
        $this->processor = $processor;

        add_action( 'wp_ajax_brz_transfer_inbound', array( $this, 'ajax_inbound' ) );
        add_action( 'wp_ajax_brz_transfer_outbound_queue', array( $this, 'ajax_outbound_queue' ) );
        add_action( 'wp_ajax_brz_transfer_generate', array( $this, 'ajax_generate' ) );
        add_action( 'wp_ajax_brz_transfer_get_code', array( $this, 'ajax_get_code' ) );
        add_action( 'wp_ajax_brz_transfer_mark_delivered', array( $this, 'ajax_mark_delivered' ) );
    }

    private function verify_request() {
        check_ajax_referer( 'brz_transfer_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'شما دسترسی ندارید.' );
        }
    }

    private function get_queue() {
        return get_option( 'brz_transfer_queue', array() );
    }

    private function save_queue( $queue ) {
        update_option( 'brz_transfer_queue', $queue, false );
    }

    private function add_to_queue( $operationType, $direction, $status, $payload, $originalPackageId = null ) {
        $queue = $this->get_queue();
        
        $packageId = 'BRZ-WP-' . gmdate('Ymd') . '-' . strtoupper( substr( md5( uniqid() ), 0, 4 ) );
        
        $pkg = array(
            'packageId' => $packageId,
            'operationType' => $operationType,
            'direction' => $direction,
            'status' => $status,
            'createdAt' => gmdate( 'c' ),
            'payload' => $payload,
        );

        if ( $originalPackageId ) {
            $pkg['payload']['originalPackageId'] = $originalPackageId;
        }

        array_unshift( $queue, $pkg ); // Add to top
        
        // Keep queue size manageable
        if ( count( $queue ) > 100 ) {
            $queue = array_slice( $queue, 0, 100 );
        }

        $this->save_queue( $queue );
        return $pkg;
    }

    public function ajax_inbound() {
        $this->verify_request();
        $code = sanitize_text_field( $_POST['code'] );
        
        try {
            $decoded = $this->codec->decode( $code );
            $meta = $decoded['meta'];
            $payload = $decoded['payload'];

            // Check if already processed
            $queue = $this->get_queue();
            foreach ( $queue as $item ) {
                if ( $item['packageId'] === $meta['packageId'] && $item['direction'] === 'inbound' && $item['status'] === 'processed' ) {
                    wp_send_json( array( 'success' => true, 'message' => 'این بسته قبلاً پردازش شده است', 'packageId' => $meta['packageId'] ) );
                }
            }

            // Save inbound package to queue
            $this->add_to_queue( $meta['operationType'], 'inbound', 'processed', $payload, $meta['packageId'] );

            // Process payload
            $result = $this->processor->process( $meta['operationType'], $payload );

            // Create response outbound package
            $this->add_to_queue( $meta['operationType'] . '.response', 'outbound', 'pending', $result, $meta['packageId'] );

            wp_send_json( array( 'success' => true, 'packageId' => $meta['packageId'], 'result' => $result ) );

        } catch ( Exception $e ) {
            // Send error back
            if ( isset( $meta ) ) {
                $this->add_to_queue( $meta['operationType'] . '.error', 'outbound', 'pending', array( 'error' => $e->getMessage() ), $meta['packageId'] );
            }
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function ajax_outbound_queue() {
        $this->verify_request();
        $queue = $this->get_queue();
        $outbound = array();
        
        foreach ( $queue as $pkg ) {
            if ( $pkg['direction'] === 'outbound' && $pkg['status'] === 'pending' ) {
                $outbound[] = array(
                    'packageId' => $pkg['packageId'],
                    'operationType' => $pkg['operationType'],
                    'createdAt' => $pkg['createdAt'],
                );
            }
        }
        
        wp_send_json( array( 'success' => true, 'data' => $outbound ) );
    }

    public function ajax_generate() {
        $this->verify_request();
        $type = sanitize_text_field( $_POST['type'] );
        
        try {
            if ( strpos( $type, 'receive' ) !== false || strpos( $type, 'from_site' ) !== false ) {
                $result = $this->processor->process( $type, array() );
                $pkg = $this->add_to_queue( $type, 'outbound', 'pending', $result );
            } else {
                $pkg = $this->add_to_queue( $type, 'outbound', 'pending', array() );
            }
            wp_send_json( array( 'success' => true, 'data' => $pkg ) );
        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    public function ajax_get_code() {
        $this->verify_request();
        $id = sanitize_text_field( $_POST['id'] );
        
        $queue = $this->get_queue();
        $pkg = null;
        foreach ( $queue as $item ) {
            if ( $item['packageId'] === $id ) {
                $pkg = $item;
                break;
            }
        }

        if ( ! $pkg ) {
            wp_send_json_error( 'بسته یافت نشد.' );
        }

        $user = wp_get_current_user();
        $meta = array(
            'version' => '1',
            'operationType' => $pkg['operationType'],
            'packageId' => $pkg['packageId'],
            'timestamp' => current_time( 'timestamp' ),
            'operatorId' => $user->user_email,
        );

        $code = $this->codec->encode( $meta, $pkg['payload'] );
        wp_send_json( array( 'success' => true, 'data' => $code, 'packageId' => $pkg['packageId'], 'operationType' => $pkg['operationType'] ) );
    }

    public function ajax_mark_delivered() {
        $this->verify_request();
        $id = sanitize_text_field( $_POST['id'] );
        
        $queue = $this->get_queue();
        foreach ( $queue as &$item ) {
            if ( $item['packageId'] === $id ) {
                $item['status'] = 'sent';
            }
        }
        
        $this->save_queue( $queue );
        wp_send_json( array( 'success' => true ) );
    }
}
