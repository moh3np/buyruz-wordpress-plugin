<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Tag_Sync_Guard {
    const HEADER_NAME  = 'x-buyruz-source';
    const HEADER_VALUE = 'sheets';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_filters' ) );
    }

    public static function register_filters() {
        add_filter( 'rest_pre_insert_product_tag', array( __CLASS__, 'whitelist_sheet_fields_tag' ), 10, 3 );
        add_filter( 'rest_pre_insert_product_brand', array( __CLASS__, 'whitelist_sheet_fields_brand' ), 10, 3 );
        add_filter( 'rest_pre_insert_product_cat', array( __CLASS__, 'whitelist_sheet_fields_category' ), 10, 3 );
        add_filter( 'woocommerce_rest_pre_insert_product_attribute', array( __CLASS__, 'whitelist_sheet_fields_attribute' ), 10, 3 );
        add_filter( 'rest_pre_insert_term', array( __CLASS__, 'whitelist_sheet_fields_term' ), 10, 4 );
    }

    public static function whitelist_sheet_fields_tag( $prepared_term, $request, $creating ) {
        return self::whitelist_sheet_fields( $prepared_term, $request, $creating, 'product_tag' );
    }

    public static function whitelist_sheet_fields_brand( $prepared_term, $request, $creating ) {
        return self::whitelist_sheet_fields( $prepared_term, $request, $creating, 'product_brand' );
    }

    public static function whitelist_sheet_fields_category( $prepared_term, $request, $creating ) {
        return self::whitelist_sheet_fields( $prepared_term, $request, $creating, 'product_cat' );
    }

    public static function whitelist_sheet_fields_attribute( $prepared_attr, $request, $creating ) {
        if ( ! self::is_sheet_request( $request ) ) {
            return $prepared_attr;
        }

        if ( is_wp_error( $prepared_attr ) || ! is_object( $prepared_attr ) ) {
            return $prepared_attr;
        }

        self::strip_disallowed_request_params_attribute( $request, $creating );

        $safe = new stdClass();

        if ( isset( $prepared_attr->id ) ) {
          $safe->id = $prepared_attr->id;
        }

        if ( isset( $prepared_attr->name ) ) {
            $safe->name = $prepared_attr->name;
        }

        if ( $creating && isset( $prepared_attr->slug ) ) {
            $safe->slug = $prepared_attr->slug;
        }

        if ( $creating && isset( $prepared_attr->type ) ) {
            $safe->type = $prepared_attr->type;
        }

        return $safe;
    }

    public static function whitelist_sheet_fields_term( $prepared_term, $request, $taxonomy, $creating ) {
        if ( ! self::is_sheet_request( $request ) ) {
            return $prepared_term;
        }

        // فقط ترم‌های ویژگی (pa_) و تگ/برند/کتگوری از قبل پوشش داده شده
        if ( strpos( $taxonomy, 'pa_' ) !== 0 ) {
            return $prepared_term;
        }

        self::strip_disallowed_request_params_term( $request, $creating );

        if ( is_wp_error( $prepared_term ) || ! is_object( $prepared_term ) ) {
            return $prepared_term;
        }

        $safe = new stdClass();
        if ( isset( $prepared_term->term_id ) ) {
            $safe->term_id = $prepared_term->term_id;
        }
        if ( isset( $prepared_term->name ) ) {
            $safe->name = $prepared_term->name;
        }
        if ( $creating && isset( $prepared_term->slug ) ) {
            $safe->slug = $prepared_term->slug;
        }

        return $safe;
    }

    private static function whitelist_sheet_fields( $prepared_term, $request, $creating, $taxonomy ) {
        if ( ! self::is_sheet_request( $request ) ) {
            return $prepared_term;
        }

        self::strip_disallowed_request_params( $request, $taxonomy, $creating );

        if ( is_wp_error( $prepared_term ) || ! is_object( $prepared_term ) ) {
            return $prepared_term;
        }

        $safe = new stdClass();

        if ( isset( $prepared_term->term_id ) ) {
            $safe->term_id = $prepared_term->term_id;
        }

        if ( isset( $prepared_term->name ) ) {
            $safe->name = $prepared_term->name;
        }

        if ( $creating && isset( $prepared_term->slug ) ) {
            $safe->slug = $prepared_term->slug;
        }

        if ( 'product_cat' === $taxonomy && isset( $prepared_term->parent ) ) {
            $safe->parent = $prepared_term->parent;
        }

        return $safe;
    }

    private static function is_sheet_request( $request ) {
        if ( ! ( $request instanceof WP_REST_Request ) ) {
            return false;
        }
        $header = $request->get_header( self::HEADER_NAME );
        if ( ! $header ) {
            return false;
        }
        return strtolower( (string) $header ) === self::HEADER_VALUE;
    }

    private static function strip_disallowed_request_params( WP_REST_Request $request, $taxonomy, $creating ) {
        $allowed = array( 'name' );

        if ( $creating ) {
            $allowed[] = 'slug';
        }

        if ( 'product_cat' === $taxonomy ) {
            $allowed[] = 'parent';
        }

        $whitelist = function( $params ) use ( $allowed ) {
            if ( empty( $params ) || ! is_array( $params ) ) {
                return $params;
            }
            return array_intersect_key( $params, array_flip( $allowed ) );
        };

        $body = $request->get_body_params();
        if ( ! empty( $body ) ) {
            $request->set_body_params( $whitelist( $body ) );
        }

        $json = $request->get_json_params();
        if ( ! empty( $json ) ) {
            $request->set_json_params( $whitelist( $json ) );
        }

        $query = $request->get_query_params();
        if ( ! empty( $query ) ) {
            $request->set_query_params( $whitelist( $query ) );
        }
    }

    private static function strip_disallowed_request_params_attribute( WP_REST_Request $request, $creating ) {
        $allowed = array( 'name' );
        if ( $creating ) {
            $allowed[] = 'slug';
            $allowed[] = 'type';
        }

        $whitelist = function( $params ) use ( $allowed ) {
            if ( empty( $params ) || ! is_array( $params ) ) {
                return $params;
            }
            return array_intersect_key( $params, array_flip( $allowed ) );
        };

        $body = $request->get_body_params();
        if ( ! empty( $body ) ) {
            $request->set_body_params( $whitelist( $body ) );
        }

        $json = $request->get_json_params();
        if ( ! empty( $json ) ) {
            $request->set_json_params( $whitelist( $json ) );
        }

        $query = $request->get_query_params();
        if ( ! empty( $query ) ) {
            $request->set_query_params( $whitelist( $query ) );
        }
    }

    private static function strip_disallowed_request_params_term( WP_REST_Request $request, $creating ) {
        $allowed = array( 'name' );
        if ( $creating ) {
            $allowed[] = 'slug';
        }

        $whitelist = function( $params ) use ( $allowed ) {
            if ( empty( $params ) || ! is_array( $params ) ) {
                return $params;
            }
            return array_intersect_key( $params, array_flip( $allowed ) );
        };

        $body = $request->get_body_params();
        if ( ! empty( $body ) ) {
            $request->set_body_params( $whitelist( $body ) );
        }

        $json = $request->get_json_params();
        if ( ! empty( $json ) ) {
            $request->set_json_params( $whitelist( $json ) );
        }

        $query = $request->get_query_params();
        if ( ! empty( $query ) ) {
            $request->set_query_params( $whitelist( $query ) );
        }
    }
}
