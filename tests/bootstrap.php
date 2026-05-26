<?php
/**
 * PHPUnit Bootstrap for buyruz-plugin tests.
 *
 * Mocks WordPress core functions and classes so that the firewall module
 * can be tested in isolation without a full WordPress installation.
 */

// Define ABSPATH so the source files don't exit.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'BRZ_VERSION' ) ) {
    define( 'BRZ_VERSION', '1.0.0-test' );
}

// ─── In-memory options store ───────────────────────────────────────────────────

global $wp_options;
$wp_options = array();

/**
 * Mock get_option.
 */
function get_option( string $key, $default = false ) {
    global $wp_options;
    return array_key_exists( $key, $wp_options ) ? $wp_options[ $key ] : $default;
}

/**
 * Mock update_option.
 */
function update_option( string $key, $value ): bool {
    global $wp_options;
    $wp_options[ $key ] = $value;
    return true;
}

/**
 * Mock delete_option.
 */
function delete_option( string $key ): bool {
    global $wp_options;
    unset( $wp_options[ $key ] );
    return true;
}

// ─── WordPress utility function mocks ──────────────────────────────────────────

function wp_parse_url( string $url, int $component = -1 ) {
    if ( $component === -1 ) {
        return parse_url( $url );
    }
    return parse_url( $url, $component );
}

function sanitize_text_field( $str ) {
    return trim( strip_tags( (string) $str ) );
}

function wp_unslash( $value ) {
    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function is_wp_error( $thing ): bool {
    return $thing instanceof WP_Error;
}

function esc_html( string $text ): string {
    return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $text ): string {
    return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function checked( $checked, $current = true, bool $echo = true ): string {
    $result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
    if ( $echo ) {
        echo $result;
    }
    return $result;
}

function current_user_can( string $capability ): bool {
    global $wp_test_current_user_can;
    if ( isset( $wp_test_current_user_can ) ) {
        return $wp_test_current_user_can;
    }
    return true;
}

function check_ajax_referer( string $action, $query_arg = false, bool $stop = true ) {
    global $wp_test_nonce_valid;
    if ( isset( $wp_test_nonce_valid ) && ! $wp_test_nonce_valid ) {
        if ( $stop ) {
            throw new \Exception( 'Invalid nonce' );
        }
        return false;
    }
    return 1;
}

function wp_create_nonce( string $action ): string {
    return 'test_nonce_' . $action;
}

/**
 * Custom exception to simulate wp_die() / die() behavior in AJAX handlers.
 */
class WP_Ajax_Response_Exception extends \Exception {
    public array $response;

    public function __construct( array $response ) {
        $this->response = $response;
        parent::__construct( 'AJAX response sent' );
    }
}

function wp_send_json_success( $data = null, int $status_code = 200 ): void {
    global $wp_test_json_response;
    $wp_test_json_response = array( 'success' => true, 'data' => $data );
    throw new WP_Ajax_Response_Exception( $wp_test_json_response );
}

function wp_send_json_error( $data = null, int $status_code = 200 ): void {
    global $wp_test_json_response;
    $wp_test_json_response = array( 'success' => false, 'data' => $data );
    throw new WP_Ajax_Response_Exception( $wp_test_json_response );
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    global $wp_test_filters;
    if ( ! isset( $wp_test_filters ) ) {
        $wp_test_filters = array();
    }
    $wp_test_filters[ $hook ][] = array(
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    );
    return true;
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return add_filter( $hook, $callback, $priority, $accepted_args );
}

function plugin_dir_url( string $file ): string {
    return 'https://example.com/wp-content/plugins/buyruz-plugin/';
}

function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): void {
    // No-op for tests.
}

function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $args = array() ): void {
    // No-op for tests.
}

function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
    return true;
}

function admin_url( string $path = '' ): string {
    return 'https://example.com/wp-admin/' . $path;
}

// ─── WP_Error class mock ───────────────────────────────────────────────────────

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected string $code;
        protected string $message;
        protected $data;

        public function __construct( string $code = '', string $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}

// ─── str_ends_with polyfill (PHP < 8.0) ────────────────────────────────────────

if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( string $haystack, string $needle ): bool {
        if ( '' === $needle ) {
            return true;
        }
        return substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}

// ─── Helper to reset global state between tests ────────────────────────────────

function brz_test_reset_state(): void {
    global $wp_options, $wp_test_filters, $wp_test_json_response, $wp_test_current_user_can, $wp_test_nonce_valid;
    $wp_options                = array();
    $wp_test_filters           = array();
    $wp_test_json_response     = null;
    $wp_test_current_user_can  = true;
    $wp_test_nonce_valid       = true;
}

// ─── Load source files ─────────────────────────────────────────────────────────

require_once __DIR__ . '/../includes/modules/http-firewall/class-brz-firewall-validator.php';
require_once __DIR__ . '/../includes/modules/http-firewall/class-brz-firewall.php';
