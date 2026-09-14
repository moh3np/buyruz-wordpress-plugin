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

if ( ! defined( 'BRZ_OPTION' ) ) {
    define( 'BRZ_OPTION', 'brz_options' );
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

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ): int {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
        global $wp_test_postmeta, $wp_test_post_meta;
        if ( isset( $wp_test_post_meta[ $post_id ][ $key ] ) ) {
            return $wp_test_post_meta[ $post_id ][ $key ];
        }
        if ( empty( $key ) ) {
            return $wp_test_postmeta[ $post_id ] ?? [];
        }
        return $wp_test_postmeta[ $post_id ][ $key ] ?? ( $single ? '' : [] );
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( int $post_id, string $key, $value ) {
        global $wp_test_postmeta;
        if ( ! isset( $wp_test_postmeta[ $post_id ] ) ) {
            $wp_test_postmeta[ $post_id ] = [];
        }
        $wp_test_postmeta[ $post_id ][ $key ] = $value;
        return true;
    }
}

function wp_parse_args( $args, $defaults = array() ): array {
    if ( is_object( $args ) ) {
        $r = get_object_vars( $args );
    } elseif ( is_array( $args ) ) {
        $r = &$args;
    } else {
        wp_parse_str( (string) $args, $r );
    }
    if ( is_array( $defaults ) ) {
        return array_merge( $defaults, $r );
    }
    return (array) $r;
}

function sanitize_text_field( $str ) {
    return trim( strip_tags( (string) $str ) );
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        $raw_key = $key;
        $key = strtolower( (string) $key );
        $key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
        return $key;
    }
}

function wp_unslash( $value ) {
    return is_string( $value ) ? stripslashes( $value ) : $value;
}

function is_wp_error( $thing ): bool {
    return $thing instanceof WP_Error;
}

function get_term( $term, string $taxonomy = '', string $output = 'OBJECT', string $filter = 'raw' ) {
    global $wp_test_terms;
    if ( isset( $wp_test_terms[ $term ] ) ) {
        return (object) $wp_test_terms[ $term ];
    }
    return null;
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

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public $ID = 0;
        public $post_title = '';
        public $post_content = '';
        public $post_excerpt = '';
        public $post_status = '';
        public $post_type = '';
        public $post_parent = 0;
        public function __construct( $data = [] ) {
            foreach ( (array) $data as $k => $v ) {
                $this->$k = $v;
            }
        }
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

// ─── WC_Product class mock ─────────────────────────────────────────────────────

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        public $id;
        public $data = array();
        public $meta = array();
        public $category_ids = array();
        public $tag_ids = array();
        public $attributes = array();
        public $image_id;
        public $gallery_image_ids = array();

        public function __construct( $id = 0, $price = 0, $date_created = null, $date_on_sale_from = null ) {
            $this->id = $id;
            if ( $price > 0 ) {
                $this->data['price'] = $price;
                $this->data['regular_price'] = $price;
            }
            if ( $date_created ) {
                $this->data['date_created'] = $date_created;
            }
            if ( $date_on_sale_from ) {
                $this->data['date_on_sale_from'] = $date_on_sale_from;
            }
        }

        public function get_id() { return $this->id; }
        public function get_name( $context = 'view' ) { return $this->data['name'] ?? ''; }
        public function get_sku( $context = 'view' ) { return $this->data['sku'] ?? ''; }
        public function get_slug( $context = 'view' ) { return $this->data['slug'] ?? ''; }
        public function get_status( $context = 'view' ) { return $this->data['status'] ?? ''; }
        public function get_price( $context = 'view' ) { return $this->data['price'] ?? $this->data['regular_price'] ?? 0; }
        public function get_regular_price( $context = 'view' ) { return $this->data['regular_price'] ?? ''; }
        public function get_sale_price( $context = 'view' ) { return $this->data['sale_price'] ?? ''; }
        public function is_on_sale( $context = 'view' ) { return ! empty( $this->data['sale_price'] ) || ! empty( $this->data['date_on_sale_from'] ); }
        public function get_date_created( $context = 'view' ) { return $this->data['date_created'] ?? new DateTime( '2025-01-15' ); }
        public function get_date_on_sale_from( $context = 'view' ) { return $this->data['date_on_sale_from'] ?? null; }
        public function get_date_on_sale_to( $context = 'view' ) { return $this->data['date_on_sale_to'] ?? null; }
        public function get_manage_stock( $context = 'view' ) { return $this->data['manage_stock'] ?? false; }
        public function get_stock_quantity( $context = 'view' ) { return $this->data['stock_quantity'] ?? 0; }
        public function get_stock_status( $context = 'view' ) { return $this->data['stock_status'] ?? 'instock'; }
        public function get_weight( $context = 'view' ) { return $this->data['weight'] ?? ''; }
        public function get_length( $context = 'view' ) { return $this->data['length'] ?? ''; }
        public function get_width( $context = 'view' ) { return $this->data['width'] ?? ''; }
        public function get_height( $context = 'view' ) { return $this->data['height'] ?? ''; }
        public function get_attribute( $key ) { return $this->attributes[ $key ] ?? ''; }
        public function get_description( $context = 'view' ) { return $this->data['description'] ?? ''; }
        public function get_short_description( $context = 'view' ) { return $this->data['short_description'] ?? ''; }

        public function set_name( $name ) { $this->data['name'] = $name; }
        public function set_slug( $slug ) { $this->data['slug'] = $slug; }
        public function set_status( $status ) { $this->data['status'] = $status; }
        public function set_description( $desc ) { $this->data['description'] = $desc; }
        public function set_short_description( $desc ) { $this->data['short_description'] = $desc; }
        public function set_price( $price ) { $this->data['price'] = $price; }
        public function set_regular_price( $price ) { $this->data['regular_price'] = $price; }
        public function set_sale_price( $price ) { $this->data['sale_price'] = $price; }
        public function set_date_on_sale_from( $date ) { $this->data['date_on_sale_from'] = $date; }
        public function set_date_on_sale_to( $date ) { $this->data['date_on_sale_to'] = $date; }
        public function set_manage_stock( $manage ) { $this->data['manage_stock'] = $manage; }
        public function set_stock_quantity( $qty ) { $this->data['stock_quantity'] = $qty; }
        public function set_stock_status( $status ) { $this->data['stock_status'] = $status; }
        public function set_sku( $sku ) { $this->data['sku'] = $sku; }
        public function set_weight( $weight ) { $this->data['weight'] = $weight; }
        public function set_length( $length ) { $this->data['length'] = $length; }
        public function set_width( $width ) { $this->data['width'] = $width; }
        public function set_height( $height ) { $this->data['height'] = $height; }

        public function set_category_ids( $ids ) { $this->category_ids = $ids; }
        public function set_tag_ids( $ids ) { $this->tag_ids = $ids; }
        public function set_image_id( $id ) { $this->image_id = $id; }
        public function set_gallery_image_ids( $ids ) { $this->gallery_image_ids = $ids; }
        public function set_attributes( $attributes ) { $this->attributes = $attributes; }

        public function set_global_unique_id( $id ) {
            $this->meta['_global_unique_id'] = $id;
        }

        public function update_meta_data( $key, $value ) {
            $this->meta[ $key ] = $value;
        }

        public function save() {
            return true;
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
