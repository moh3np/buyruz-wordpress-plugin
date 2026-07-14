<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Product Specs Manager Module.
 *
 * Dynamically builds custom post meta fields for WooCommerce products with zero-bloat.
 * Renders fields inside product edit metabox and single product Additional Information tab.
 */
class BRZ_Product_Specs {

    /**
     * Bootstrap the module.
     */
    public static function init(): void {
        // Register post meta fields dynamically.
        self::register_dynamic_meta();

        if ( is_admin() ) {
            add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
            add_action( 'save_post_product', array( __CLASS__, 'save_metabox' ) );
            add_action( 'wp_ajax_brz_save_product_specs_fields', array( __CLASS__, 'ajax_save_fields' ) );
            add_action( 'wp_ajax_brz_save_unified_specs_layout', array( __CLASS__, 'ajax_save_unified_layout' ) );
        } else {
            // Frontend: Inject unified layout specifications and remove WooCommerce default
            add_action( 'wp', function() {
                remove_action( 'woocommerce_product_additional_information', 'wc_display_product_attributes', 10 );
            }, 20 );
            add_action( 'woocommerce_product_additional_information', array( __CLASS__, 'render_unified_product_specs' ), 10 );
        }

        // Expose specs field in REST API.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_fields' ) );

        // Monitor meta updates from any source (Bridge, REST API, WP Admin) to auto-register new options.
        add_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'monitor_meta_deletions' ), 10, 4 );

        // Recalculate filter ages when terms for target-audience change.
        add_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 10, 4 );
    }

    /**
     * Sanitize integer fields safely.
     */
    public static function sanitize_integer( $value ): int {
        return intval( $value );
    }

    /**
     * Sanitize decimal/numeric fields safely.
     */
    public static function sanitize_decimal( $value ): float {
        return floatval( $value );
    }

    /**
     * Get specific meta keys for range fields to map to correct DB fields.
     */
    public static function get_range_meta_keys( string $key ): array {
        if ( 'manual_age' === $key ) {
            return array( '_brz_spec_manual_min_age', '_brz_spec_manual_max_age' );
        }
        if ( 'players' === $key ) {
            return array( '_brz_spec_min_players', '_brz_spec_max_players' );
        }
        if ( 'time' === $key ) {
            return array( '_brz_spec_min_time', '_brz_spec_max_time' );
        }
        return array( '_brz_spec_' . $key . '_min', '_brz_spec_' . $key . '_max' );
    }

    /**
     * Intercept postmeta deletions to auto-clean background filter age keys.
     */
    public static function monitor_meta_deletions( $meta_ids, $object_id, $meta_key, $meta_values ): void {
        if ( '_brz_spec_manual_min_age' === $meta_key || '_brz_spec_manual_max_age' === $meta_key ) {
            self::recalculate_product_filter_ages( $object_id );
        }
    }

    /**
     * Intercept postmeta additions and updates to automatically register new options for array type fields.
     */
    public static function monitor_meta_changes( $meta_id, $object_id, $meta_key, $meta_value ): void {
        if ( strpos( $meta_key, '_brz_spec_' ) !== 0 ) {
            return;
        }

        // Avoid infinite loops if we are updating options or post meta.
        remove_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10 );
        remove_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10 );

        // Auto-generate filter age keys in the background
        if ( '_brz_spec_manual_min_age' === $meta_key || '_brz_spec_manual_max_age' === $meta_key ) {
            self::recalculate_product_filter_ages( $object_id );
        }

        $key = str_replace( '_brz_spec_', '', $meta_key );
        // Skip range fields sub-metas.
        if ( strpos( $key, '_min' ) !== false || strpos( $key, '_max' ) !== false ) {
            add_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
            add_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
            return;
        }

        $fields  = self::get_fields();
        $updated = false;

        foreach ( $fields as &$field ) {
            if ( $field['key'] === $key && 'array' === $field['type'] ) {
                $values = array();

                // Decode the meta value which could be an array, JSON, serialized, or comma-separated string.
                if ( is_array( $meta_value ) ) {
                    $values = $meta_value;
                } elseif ( is_string( $meta_value ) && ! empty( $meta_value ) ) {
                    $decoded = json_decode( $meta_value, true );
                    if ( is_array( $decoded ) ) {
                        $values = $decoded;
                    } else {
                        $unserialized = maybe_unserialize( $meta_value );
                        if ( is_array( $unserialized ) ) {
                            $values = $unserialized;
                        } else {
                            $values = array_map( 'trim', explode( ',', $meta_value ) );
                        }
                    }
                }

                if ( empty( $values ) ) {
                    break;
                }

                // Get current options and append new ones.
                $current_options = array_map( 'trim', explode( ',', (string) $field['options'] ) );
                $current_options = array_filter( $current_options );

                foreach ( $values as $val ) {
                    $val = trim( $val );
                    if ( '' !== $val && ! in_array( $val, $current_options, true ) ) {
                        $current_options[] = $val;
                        $updated           = true;
                    }
                }

                if ( $updated ) {
                    $field['options'] = implode( ', ', $current_options );
                }
                break;
            }
        }

        if ( $updated ) {
            update_option( 'brz_product_specs_fields', $fields );
        }

        // Re-add actions.
        add_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
    }

    /**
     * Fetch saved fields.
     */
    public static function get_fields(): array {
        $fields = get_option( 'brz_product_specs_fields', null );
        if ( is_null( $fields ) ) {
            $fields = self::get_seed_fields();
            update_option( 'brz_product_specs_fields', $fields );
        }
        return is_array( $fields ) ? $fields : array();
    }

    /**
     * Seed initial configuration fields (100% clean and blank by default).
     */
    public static function get_seed_fields(): array {
        return array();
    }

    /**
     * Register meta fields dynamically for REST API and core validation.
     */
    public static function register_dynamic_meta(): void {
        $fields = self::get_fields();
        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( 'range' === $type ) {
                $keys = self::get_range_meta_keys( $key );
                register_post_meta(
                    'product',
                    $keys[0],
                    array(
                        'type'              => 'integer',
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                    )
                );
                register_post_meta(
                    'product',
                    $keys[1],
                    array(
                        'type'              => 'integer',
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                    )
                );

                // Auto-generated backend filter keys for age
                if ( 'manual_age' === $key ) {
                    register_post_meta(
                        'product',
                        '_brz_spec_filter_min_age',
                        array(
                            'type'              => 'integer',
                            'single'            => true,
                            'show_in_rest'      => true,
                            'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                        )
                    );
                    register_post_meta(
                        'product',
                        '_brz_spec_filter_max_age',
                        array(
                            'type'              => 'integer',
                            'single'            => true,
                            'show_in_rest'      => true,
                            'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                        )
                    );
                }
            } else {
                $meta_type   = 'string';
                $sanitize_cb = 'sanitize_text_field';

                if ( 'boolean' === $type ) {
                    $meta_type   = 'boolean';
                    $sanitize_cb = 'rest_sanitize_boolean';
                } elseif ( 'integer' === $type ) {
                    $meta_type   = 'integer';
                    $sanitize_cb = array( __CLASS__, 'sanitize_integer' );
                } elseif ( 'decimal' === $type ) {
                    $meta_type   = 'number';
                    $sanitize_cb = array( __CLASS__, 'sanitize_decimal' );
                }

                register_post_meta(
                    'product',
                    '_brz_spec_' . $key,
                    array(
                        'type'              => $meta_type,
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => $sanitize_cb,
                    )
                );
            }
        }
    }

    /**
     * Register unified REST API fields for Apps Script and AI integrations.
     */
    public static function register_rest_fields(): void {
        register_rest_field(
            'product',
            'buyruz_product_specs',
            array(
                'get_callback'    => array( __CLASS__, 'rest_get_specs' ),
                'update_callback' => array( __CLASS__, 'rest_update_specs' ),
                'schema'          => array(
                    'description' => 'Buyruz Custom Product Specs',
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                ),
            )
        );
    }

    /**
     * Getter callback for REST API.
     */
    public static function rest_get_specs( array $object ): array {
        $post_id = $object['id'];
        $fields  = self::get_fields();
        $data    = array();

        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( 'range' === $type ) {
                $keys = self::get_range_meta_keys( $key );
                $min  = get_post_meta( $post_id, $keys[0], true );
                $max  = get_post_meta( $post_id, $keys[1], true );
                $data[ $key ] = array(
                    'min' => ( $min !== '' ) ? intval( $min ) : null,
                    'max' => ( $max !== '' ) ? intval( $max ) : null,
                );
            } elseif ( 'array' === $type ) {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                if ( empty( $val ) ) {
                    $data[ $key ] = array();
                } else {
                    $decoded = json_decode( $val, true );
                    if ( ! is_array( $decoded ) ) {
                        $decoded = maybe_unserialize( $val );
                    }
                    $data[ $key ] = is_array( $decoded ) ? $decoded : array();
                }
            } elseif ( 'boolean' === $type ) {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                $data[ $key ] = ( $val !== '' ) ? (bool) intval( $val ) : null;
            } elseif ( 'integer' === $type ) {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                $data[ $key ] = ( $val !== '' ) ? intval( $val ) : null;
            } elseif ( 'decimal' === $type ) {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                $data[ $key ] = ( $val !== '' ) ? floatval( $val ) : null;
            } elseif ( 'string' === $type || 'text' === $type ) {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                $data[ $key ] = ( $val !== '' ) ? sanitize_text_field( $val ) : null;
            }
        }
        return $data;
    }

    /**
     * Setter callback for REST API.
     */
    public static function rest_update_specs( $value, WP_Post $product_object ): bool {
        if ( ! is_array( $value ) ) {
            return false;
        }
        $post_id = $product_object->ID;
        $fields  = self::get_fields();

        foreach ( $fields as $field ) {
            $key  = $field['key'];
            $type = $field['type'];

            if ( ! isset( $value[ $key ] ) ) {
                continue;
            }

            $val = $value[ $key ];

            if ( 'range' === $type ) {
                $keys = self::get_range_meta_keys( $key );
                if ( is_array( $val ) ) {
                    if ( isset( $val['min'] ) ) {
                        if ( $val['min'] === null || $val['min'] === '' ) {
                            delete_post_meta( $post_id, $keys[0] );
                            if ( 'manual_age' === $key ) {
                                delete_post_meta( $post_id, '_brz_spec_filter_min_age' );
                            }
                        } else {
                            $int_min = intval( $val['min'] );
                            update_post_meta( $post_id, $keys[0], $int_min );
                            if ( 'manual_age' === $key ) {
                                update_post_meta( $post_id, '_brz_spec_filter_min_age', $int_min );
                            }
                        }
                    }
                    if ( isset( $val['max'] ) ) {
                        if ( $val['max'] === null || $val['max'] === '' ) {
                            delete_post_meta( $post_id, $keys[1] );
                            if ( 'manual_age' === $key ) {
                                delete_post_meta( $post_id, '_brz_spec_filter_max_age' );
                            }
                        } else {
                            $int_max = intval( $val['max'] );
                            update_post_meta( $post_id, $keys[1], $int_max );
                            if ( 'manual_age' === $key ) {
                                update_post_meta( $post_id, '_brz_spec_filter_max_age', $int_max );
                            }
                        }
                    }
                }
            } elseif ( 'array' === $type ) {
                if ( is_array( $val ) && ! empty( $val ) ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, wp_json_encode( array_map( 'sanitize_text_field', $val ) ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            } elseif ( 'boolean' === $type ) {
                if ( $val === null || $val === '' ) {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                } else {
                    update_post_meta( $post_id, '_brz_spec_' . $key, $val ? '1' : '0' );
                }
            } elseif ( 'integer' === $type ) {
                if ( $val === null || $val === '' ) {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                } else {
                    update_post_meta( $post_id, '_brz_spec_' . $key, intval( $val ) );
                }
            } elseif ( 'decimal' === $type ) {
                if ( $val === null || $val === '' ) {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                } else {
                    update_post_meta( $post_id, '_brz_spec_' . $key, floatval( $val ) );
                }
            } elseif ( 'string' === $type || 'text' === $type ) {
                if ( $val === null || $val === '' ) {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                } else {
                    update_post_meta( $post_id, '_brz_spec_' . $key, sanitize_text_field( $val ) );
                }
            }
        }
        self::recalculate_product_filter_ages( $post_id );
        return true;
    }

    /**
     * Add product edit page metabox.
     */
    public static function add_meta_boxes(): void {
        self::add_metabox();
    }

    public static function add_metabox(): void {
        add_meta_box(
            'brz_product_specs_metabox',
            'مشخصات فنی محصول (بایروز)',
            array( __CLASS__, 'render_metabox' ),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render product edit page metabox fields.
     */
    public static function render_metabox( WP_Post $post ): void {
        wp_nonce_field( 'brz_product_specs_save', 'brz_product_specs_nonce' );
        $fields = self::get_fields();
        if ( empty( $fields ) ) {
            echo '<p style="padding:10px 0; color:#c00;">هیچ فیلد مشخصات فنی تعریف نشده است. لطفا ابتدا از مسیر تنظیمات بایروز > مشخصات فنی محصول فیلدها را تعریف کنید.</p>';
            return;
        }

        $active_fields   = array();
        $inactive_fields = array();

        foreach ( $fields as $field ) {
            $key       = $field['key'];
            $type      = $field['type'];
            $has_value = false;

            if ( 'range' === $type ) {
                $keys    = self::get_range_meta_keys( $key );
                $min_val = get_post_meta( $post->ID, $keys[0], true );
                $max_val = get_post_meta( $post->ID, $keys[1], true );
                if ( $min_val !== '' || $max_val !== '' ) {
                    $has_value = true;
                }
            } elseif ( 'array' === $type ) {
                $saved_val = get_post_meta( $post->ID, '_brz_spec_' . $key, true );
                if ( ! empty( $saved_val ) ) {
                    $decoded = json_decode( $saved_val, true );
                    if ( ! is_array( $decoded ) ) {
                        $decoded = maybe_unserialize( $saved_val );
                    }
                    if ( ! empty( $decoded ) && is_array( $decoded ) ) {
                        $has_value = true;
                    }
                }
            } else {
                $saved_val = get_post_meta( $post->ID, '_brz_spec_' . $key, true );
                if ( $saved_val !== '' ) {
                    $has_value = true;
                }
            }

            if ( $has_value ) {
                $active_fields[] = $field;
            } else {
                $inactive_fields[] = $field;
            }
        }
        ?>
        <style>
            .brz-specs-container {
                display: flex;
                flex-direction: column;
                gap: 15px;
                padding: 10px 0;
            }
            
            /* Modern Top Toolbar */
            .brz-spec-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 12px 16px;
                margin-bottom: 10px;
            }
            .brz-toolbar-title {
                font-size: 13px;
                font-weight: bold;
                color: #1e293b;
            }
            .brz-toolbar-action {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .brz-toolbar-action label {
                font-size: 13px;
                font-weight: 600;
                color: #475569;
            }
            .brz-spec-select-add {
                padding: 6px 12px !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                font-size: 13px !important;
                background-color: #fff !important;
                color: #1e293b !important;
                cursor: pointer;
                width: 240px !important;
                height: auto !important;
                box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            }
            .brz-spec-select-add:focus {
                border-color: var(--brz-brand, #1a73e8) !important;
                outline: none;
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            
            /* Spec Rows Layout */
            .brz-spec-field-row {
                display: flex;
                align-items: center;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 15px;
                background: #fff;
                margin-bottom: 5px;
            }
            .brz-spec-label {
                width: 180px;
                font-weight: 600;
                color: #334155;
                font-size: 14px;
            }
            .brz-spec-input-wrap {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
            }
            .brz-spec-inputs-inner {
                flex: 1;
            }
            
            /* Circular Remove Button */
            .brz-spec-remove-btn {
                background: #f1f5f9;
                border: 1px solid #e2e8f0;
                color: #64748b;
                cursor: pointer;
                font-size: 12px;
                padding: 5px 12px;
                border-radius: 6px;
                transition: all 0.15s ease-in-out;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .brz-spec-remove-btn:hover {
                background: #fee2e2;
                border-color: #fecaca;
                color: #ef4444;
            }
            
            /* Toggle Switch */
            .brz-switch {
                position: relative;
                display: inline-block;
                width: 48px;
                height: 24px;
            }
            .brz-switch input { 
                opacity: 0;
                width: 0;
                height: 0;
            }
            .brz-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #cbd5e1;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 24px;
            }
            .brz-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                -webkit-transition: .2s;
                transition: .2s;
                border-radius: 50%;
            }
            input:checked + .brz-slider {
                background-color: var(--brz-brand, #1a73e8);
            }
            input:focus + .brz-slider {
                box-shadow: 0 0 1px var(--brz-brand, #1a73e8);
            }
            input:checked + .brz-slider:before {
                -webkit-transform: translateX(24px);
                -ms-transform: translateX(24px);
                transform: translateX(24px);
            }

            /* Range Inputs Row */
            .brz-range-row {
                display: flex;
                align-items: center;
                gap: 10px;
                color: #475569;
                font-size: 13px;
            }
            .brz-range-input {
                width: 90px !important;
                padding: 6px 8px !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                text-align: center;
                color: #1e293b;
            }
            .brz-range-input:focus {
                border-color: var(--brz-brand, #1a73e8) !important;
                box-shadow: 0 0 0 2px rgba(26,115,232,.15) !important;
                outline: none;
            }
            
            /* Multi select / Checklist */
            .brz-checkbox-list {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                padding: 12px 15px;
                border-radius: 6px;
                max-width: 600px;
            }
            .brz-checkbox-item {
                display: flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
                font-size: 13px;
                user-select: none;
                color: #334155;
            }
            .brz-checkbox-item input {
                margin: 0;
            }
            
            /* General Number Input */
            .brz-number-input {
                width: 120px !important;
                padding: 6px 8px !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                color: #1e293b;
            }
            .brz-number-input:focus {
                border-color: var(--brz-brand, #1a73e8) !important;
                box-shadow: 0 0 0 2px rgba(26,115,232,.15) !important;
                outline: none;
            }

            /* Add Option Section */
            .brz-spec-add-option-wrap {
                margin-top: 10px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .brz-new-option-input {
                width: 150px !important;
                height: 28px !important;
                padding: 4px 10px !important;
                font-size: 12px !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 6px !important;
            }
            .brz-new-option-input:focus {
                border-color: var(--brz-brand, #1a73e8) !important;
                box-shadow: 0 0 0 2px rgba(26,115,232,.15) !important;
                outline: none;
            }
            .brz-add-option-btn {
                height: 28px !important;
                line-height: 26px !important;
                padding: 0 12px !important;
                font-size: 12px !important;
                border-radius: 6px !important;
                background: #f1f5f9 !important;
                border: 1px solid #cbd5e1 !important;
                color: #334155 !important;
                cursor: pointer;
                transition: all 0.15s;
            }
            .brz-add-option-btn:hover {
                background: #e2e8f0 !important;
                color: #0f172a !important;
            }

            /* Empty state */
            .brz-empty-specs-msg {
                padding: 40px 20px;
                text-align: center;
                color: #64748b;
                border: 2px dashed #cbd5e1;
                border-radius: 8px;
                font-size: 13px;
                background: #f8fafc;
            }
        </style>

        <div class="brz-specs-container">
            <!-- Selector to add new specifications to this product (AT THE TOP) -->
            <div class="brz-spec-toolbar">
                <span class="brz-toolbar-title">مشخصات فنی فعال این محصول</span>
                <div class="brz-toolbar-action">
                    <label for="brz-spec-add-selector">افزودن مشخصه جدید:</label>
                    <select id="brz-spec-add-selector" class="brz-spec-select-add">
                        <option value="">-- انتخاب مشخصه برای افزودن --</option>
                        <?php foreach ( $inactive_fields as $field ) : ?>
                            <option value="<?php echo esc_attr( $field['key'] ); ?>" id="brz-opt-<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Empty state when no specs are active -->
            <div id="brz-empty-specs-msg" class="brz-empty-specs-msg" style="display: <?php echo empty( $active_fields ) ? 'block' : 'none'; ?>;">
                هیچ مشخصه فنی برای این محصول انتخاب نشده است. از منوی بالای این کادر، مشخصه مورد نظر خود را اضافه کنید.
            </div>

            <div id="brz-active-specs-list">
                <?php
                foreach ( $fields as $field ) {
                    $key       = $field['key'];
                    $type      = $field['type'];
                    $label     = $field['label'];
                    $is_active = in_array( $field, $active_fields, true );
                    
                    $min_val        = '';
                    $max_val        = '';
                    $checked_values = array();
                    $saved_val      = '';

                    if ( 'range' === $type ) {
                        $keys    = self::get_range_meta_keys( $key );
                        $min_val = get_post_meta( $post->ID, $keys[0], true );
                        $max_val = get_post_meta( $post->ID, $keys[1], true );
                    } elseif ( 'array' === $type ) {
                        $saved_val = get_post_meta( $post->ID, '_brz_spec_' . $key, true );
                        if ( ! empty( $saved_val ) ) {
                            $decoded = json_decode( $saved_val, true );
                            if ( ! is_array( $decoded ) ) {
                                $decoded = maybe_unserialize( $saved_val );
                            }
                            if ( is_array( $decoded ) ) {
                                $checked_values = $decoded;
                            }
                        }
                    } else {
                        $saved_val = get_post_meta( $post->ID, '_brz_spec_' . $key, true );
                    }
                    ?>
                    <div class="brz-spec-field-row" id="brz-spec-row-<?php echo esc_attr( $key ); ?>" style="<?php echo $is_active ? '' : 'display:none;'; ?>" data-key="<?php echo esc_attr( $key ); ?>">
                        <input type="hidden" class="brz-spec-is-active-input" name="brz_spec_active[<?php echo esc_attr( $key ); ?>]" value="<?php echo $is_active ? '1' : '0'; ?>" />
                        
                        <div class="brz-spec-label" data-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></div>
                        
                        <div class="brz-spec-input-wrap">
                            <div class="brz-spec-inputs-inner">
                                <?php if ( 'boolean' === $type ) : ?>
                                    <label class="brz-switch">
                                        <input type="hidden" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="0" />
                                        <input type="checkbox" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( '1', $saved_val ); ?> />
                                        <span class="brz-slider"></span>
                                    </label>
                                <?php elseif ( 'range' === $type ) : ?>
                                    <div class="brz-range-row">
                                        <span>حداقل:</span>
                                        <input type="number" class="brz-range-input" name="brz_spec_range[<?php echo esc_attr( $key ); ?>][min]" value="<?php echo esc_attr( $min_val ); ?>" />
                                        <span style="margin-right:10px;">حداکثر:</span>
                                        <input type="number" class="brz-range-input" name="brz_spec_range[<?php echo esc_attr( $key ); ?>][max]" value="<?php echo esc_attr( $max_val ); ?>" />
                                    </div>
                                <?php elseif ( 'array' === $type ) : 
                                    $options = array_map( 'trim', explode( ',', $field['options'] ) );
                                    ?>
                                    <div class="brz-checkbox-list">
                                        <?php foreach ( $options as $opt ) : 
                                            if ( '' === $opt ) continue;
                                            $checked = in_array( $opt, $checked_values, true );
                                            ?>
                                            <label class="brz-checkbox-item">
                                                <input type="checkbox" name="brz_spec_array[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $opt ); ?>" <?php checked( true, $checked ); ?> />
                                                <span><?php echo esc_html( $opt ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="brz-spec-add-option-wrap" style="margin-top: 8px; display: flex; align-items: center; gap: 8px;">
                                        <input type="text" class="brz-new-option-input" placeholder="افزودن گزینه جدید..." style="width: 140px !important; padding: 4px 8px !important; font-size: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                                        <button type="button" class="button brz-add-option-btn" style="padding: 2px 10px; font-size: 11px; height: 26px; line-height: 24px;">+ افزودن به لیست</button>
                                    </div>
                                <?php elseif ( 'integer' === $type ) : ?>
                                    <input type="number" step="1" class="brz-number-input" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $saved_val ); ?>" />
                                <?php elseif ( 'decimal' === $type ) : ?>
                                    <input type="number" step="any" class="brz-number-input" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $saved_val ); ?>" />
                                <?php elseif ( 'string' === $type || 'text' === $type ) : ?>
                                    <input type="text" class="regular-text brz-text-input" style="width: 100%; max-width: 500px; padding: 6px 8px !important; border-radius: 6px !important; border: 1px solid #cbd5e1 !important; color: #1e293b;" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $saved_val ); ?>" />
                                <?php endif; ?>
                            </div>
                            
                            <button type="button" class="brz-spec-remove-btn" title="حذف مشخصه از این محصول" data-key="<?php echo esc_attr( $key ); ?>">
                                <span>✕</span> حذف مشخصه
                            </button>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Convert Persian/Arabic digits to English digits for number inputs
                function convertPersianToEnglish(str) {
                    var persianNumbers = [/۰/g, /۱/g, /۲/g, /۳/g, /۴/g, /۵/g, /۶/g, /۷/g, /۸/g, /۹/g];
                    var arabicNumbers  = [/٠/g, /١/g, /٢/g, /٣/g, /٤/g, /٥/g, /٦/g, /٧/g, /٨/g, /٩/g];
                    for(var i=0; i<10; i++) {
                        str = str.replace(persianNumbers[i], i).replace(arabicNumbers[i], i);
                    }
                    return str;
                }

                $(document).on('input paste keyup', '.brz-number-input, .brz-range-input', function() {
                    var val = $(this).val();
                    var converted = convertPersianToEnglish(val);
                    if (val !== converted) {
                        $(this).val(converted);
                    }
                });

                // Function to toggle empty state message
                function toggleEmptyState() {
                    var activeCount = $('.brz-spec-field-row:visible').length;
                    if (activeCount === 0) {
                        $('#brz-empty-specs-msg').fadeIn(200);
                    } else {
                        $('#brz-empty-specs-msg').hide();
                    }
                }

                // Add option within Array field list
                $('.brz-add-option-btn').on('click', function(e) {
                    e.preventDefault();
                    var $wrap = $(this).closest('.brz-spec-input-wrap');
                    var $input = $wrap.find('.brz-new-option-input');
                    var val = $.trim($input.val());
                    if (val === '') {
                        return;
                    }

                    var key = $wrap.closest('.brz-spec-field-row').find('.brz-spec-label').data('key');

                    var exists = false;
                    $wrap.find('input[type="checkbox"]').each(function() {
                        if ($(this).val() === val) {
                            $(this).prop('checked', true);
                            exists = true;
                        }
                    });

                    if (!exists) {
                        var checkboxHtml = '<label class="brz-checkbox-item">' +
                            '<input type="checkbox" name="brz_spec_array[' + key + '][]" value="' + val + '" checked />' +
                            '<span>' + val + '</span>' +
                            '</label>';
                        $wrap.find('.brz-checkbox-list').append(checkboxHtml);
                    }

                    $input.val('').focus();
                });

                $('.brz-new-option-input').on('keydown', function(e) {
                    if (e.keyCode === 13) {
                        e.preventDefault();
                        $(this).siblings('.brz-add-option-btn').click();
                    }
                });

                // Add spec field to product
                $('#brz-spec-add-selector').on('change', function() {
                    var key = $(this).val();
                    if (key === '') return;

                    var $row = $('#brz-spec-row-' + key);
                    $row.fadeIn(250, function() {
                        toggleEmptyState();
                    });
                    $row.find('.brz-spec-is-active-input').val('1');

                    $(this).find('option[value="' + key + '"]').remove();
                    $(this).val('');
                });

                // Remove spec field from product
                $('.brz-spec-remove-btn').on('click', function(e) {
                    e.preventDefault();
                    var key = $(this).data('key');
                    var $row = $('#brz-spec-row-' + key);
                    var label = $row.find('.brz-spec-label').text().replace('✕ حذف مشخصه', '').trim();

                    // Set values and active flag instantly (synchronously) to prevent double-saving or fast-update bugs
                    $row.find('.brz-spec-is-active-input').val('0');
                    $row.find('input[type="number"], input[type="text"]').val('');
                    $row.find('input[type="checkbox"]').prop('checked', false);

                    $row.fadeOut(200, function() {
                        var $selector = $('#brz-spec-add-selector');
                        if ($selector.find('option[value="' + key + '"]').length === 0) {
                            $selector.append('<option value="' + key + '">' + label + '</option>');
                        }
                        toggleEmptyState();
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Save product metadata values.
     */
    public static function save_metabox( int $post_id ): void {
        if ( ! isset( $_POST['brz_product_specs_nonce'] ) || ! wp_verify_nonce( $_POST['brz_product_specs_nonce'], 'brz_product_specs_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $fields       = self::get_fields();
        $active_flags = isset( $_POST['brz_spec_active'] ) && is_array( $_POST['brz_spec_active'] ) ? $_POST['brz_spec_active'] : array();

        foreach ( $fields as $field ) {
            $key       = $field['key'];
            $type      = $field['type'];
            $is_active = isset( $active_flags[ $key ] ) && $active_flags[ $key ] === '1';

            if ( ! $is_active ) {
                // Delete all meta associated with this field to keep database clean.
                if ( 'range' === $type ) {
                    $keys = self::get_range_meta_keys( $key );
                    delete_post_meta( $post_id, $keys[0] );
                    delete_post_meta( $post_id, $keys[1] );
                    if ( 'manual_age' === $key ) {
                        delete_post_meta( $post_id, '_brz_spec_filter_min_age' );
                        delete_post_meta( $post_id, '_brz_spec_filter_max_age' );
                    }
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
                continue;
            }

            // Save active field values.
            if ( 'boolean' === $type ) {
                $raw_specs = isset( $_POST['brz_spec'] ) && is_array( $_POST['brz_spec'] ) ? $_POST['brz_spec'] : array();
                $val       = isset( $raw_specs[ $key ] ) && $raw_specs[ $key ] === '1' ? '1' : '0';
                update_post_meta( $post_id, '_brz_spec_' . $key, $val );
            } elseif ( 'integer' === $type ) {
                $raw_specs = isset( $_POST['brz_spec'] ) && is_array( $_POST['brz_spec'] ) ? $_POST['brz_spec'] : array();
                if ( isset( $raw_specs[ $key ] ) && '' !== $raw_specs[ $key ] ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, intval( $raw_specs[ $key ] ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            } elseif ( 'decimal' === $type ) {
                $raw_specs = isset( $_POST['brz_spec'] ) && is_array( $_POST['brz_spec'] ) ? $_POST['brz_spec'] : array();
                if ( isset( $raw_specs[ $key ] ) && '' !== $raw_specs[ $key ] ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, floatval( $raw_specs[ $key ] ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            } elseif ( 'range' === $type ) {
                $keys = self::get_range_meta_keys( $key );
                $raw_ranges = isset( $_POST['brz_spec_range'] ) && is_array( $_POST['brz_spec_range'] ) ? $_POST['brz_spec_range'] : array();
                if ( isset( $raw_ranges[ $key ] ) ) {
                    $min = $raw_ranges[ $key ]['min'];
                    $max = $raw_ranges[ $key ]['max'];

                    if ( '' !== $min ) {
                        $int_min = intval( $min );
                        update_post_meta( $post_id, $keys[0], $int_min );
                        if ( 'manual_age' === $key ) {
                            update_post_meta( $post_id, '_brz_spec_filter_min_age', $int_min );
                        }
                    } else {
                        delete_post_meta( $post_id, $keys[0] );
                        if ( 'manual_age' === $key ) {
                            delete_post_meta( $post_id, '_brz_spec_filter_min_age' );
                        }
                    }

                    if ( '' !== $max ) {
                        $int_max = intval( $max );
                        update_post_meta( $post_id, $keys[1], $int_max );
                        if ( 'manual_age' === $key ) {
                            update_post_meta( $post_id, '_brz_spec_filter_max_age', $int_max );
                        }
                    } else {
                        delete_post_meta( $post_id, $keys[1] );
                        if ( 'manual_age' === $key ) {
                            delete_post_meta( $post_id, '_brz_spec_filter_max_age' );
                        }
                    }
                } else {
                    delete_post_meta( $post_id, $keys[0] );
                    delete_post_meta( $post_id, $keys[1] );
                    if ( 'manual_age' === $key ) {
                        delete_post_meta( $post_id, '_brz_spec_filter_min_age' );
                        delete_post_meta( $post_id, '_brz_spec_filter_max_age' );
                    }
                }
            } elseif ( 'array' === $type ) {
                $raw_arrays = isset( $_POST['brz_spec_array'] ) && is_array( $_POST['brz_spec_array'] ) ? $_POST['brz_spec_array'] : array();
                $val        = isset( $raw_arrays[ $key ] ) && is_array( $raw_arrays[ $key ] ) ? $raw_arrays[ $key ] : array();
                if ( ! empty( $val ) ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, wp_json_encode( array_map( 'sanitize_text_field', $val ) ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            } elseif ( 'string' === $type || 'text' === $type ) {
                $raw_specs = isset( $_POST['brz_spec'] ) && is_array( $_POST['brz_spec'] ) ? $_POST['brz_spec'] : array();
                if ( isset( $raw_specs[ $key ] ) && '' !== $raw_specs[ $key ] ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, sanitize_text_field( $raw_specs[ $key ] ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            }
        }
        self::recalculate_product_filter_ages( $post_id );
    }

    /**
     * Render settings page in admin panel.
     */
    public static function render_admin_page(): void {
        $fields = self::get_fields();
        ?>
        <style>
            .brz-spec-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            .brz-spec-table th, .brz-spec-table td {
                padding: 12px 10px;
                text-align: right;
                border-bottom: 1px solid var(--md-outline-variant, #e0e0e0);
            }
            .brz-spec-table th {
                font-weight: 600;
                background-color: #fcfcfc;
            }
            .brz-spec-table input[type="text"], .brz-spec-table select {
                width: 100%;
                box-sizing: border-box;
                padding: 6px 8px;
                border: 1px solid var(--md-outline-variant, #ccc);
                border-radius: 6px;
                font-size: 13px;
            }
            .brz-spec-table input[type="text"]:focus, .brz-spec-table select:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.15);
            }
            .brz-spec-table input.brz-field-error {
                border-color: #d32f2f;
                box-shadow: 0 0 0 2px rgba(211,47,47,.12);
            }
            .brz-spec-options-input {
                font-size: 11px !important;
            }
            /* Tab Navigation */
            .brz-tab-nav {
                display: flex;
                gap: 5px;
                border-bottom: 2px solid #e2e8f0;
                margin-bottom: 20px;
                padding-bottom: 0;
            }
            .brz-tab-btn {
                background: none;
                border: none;
                padding: 12px 24px;
                font-size: 14.5px;
                font-weight: 600;
                color: #64748b;
                cursor: pointer;
                position: relative;
                transition: all 0.15s ease;
                border-bottom: 3px solid transparent;
                margin-bottom: -2px;
            }
            .brz-tab-btn:hover {
                color: var(--brz-brand, #1a73e8);
            }
            .brz-tab-btn.active {
                color: var(--brz-brand, #1a73e8);
                border-bottom-color: var(--brz-brand, #1a73e8);
            }
            .brz-tab-content {
                display: none;
            }
            .brz-tab-content.active {
                display: block;
            }
            
            /* Unified Layout AI Tooling */
            .brz-flow-container {
                display: grid;
                grid-template-columns: 1.1fr 0.9fr;
                gap: 24px;
                margin-top: 15px;
            }
            @media (max-width: 960px) {
                .brz-flow-container {
                    grid-template-columns: 1fr;
                }
            }
            .brz-flow-list-wrapper {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .brz-flow-list {
                display: flex;
                flex-direction: column;
                gap: 8px;
                max-height: 480px;
                overflow-y: auto;
                padding-right: 2px;
            }
            .brz-flow-item {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 10px 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
                transition: transform 0.2s, border-color 0.2s;
            }
            .brz-flow-item:hover {
                transform: translateX(-2px);
                border-color: #cbd5e1;
            }
            .brz-flow-number {
                background: #f1f5f9;
                color: #475569;
                font-size: 11px;
                font-weight: 700;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #e2e8f0;
            }
            .brz-flow-label {
                font-weight: 600;
                color: #1e293b;
                flex-grow: 1;
                font-size: 13.5px;
            }
            .brz-flow-badge {
                font-size: 10.5px;
                font-weight: 600;
                padding: 3px 10px;
                border-radius: 20px;
            }
            .brz-flow-badge.spec {
                background: #f3e8ff;
                color: #6b21a8;
                border: 1px solid #e9d5ff;
            }
            .brz-flow-badge.attr {
                background: #dbeafe;
                color: #1e40af;
                border: 1px solid #bfdbfe;
            }
            .brz-flow-badge.physical {
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #bbf7d0;
            }
            .brz-flow-badge.fallback {
                background: #f1f5f9;
                color: #475569;
                border: 1px solid #e2e8f0;
            }
            .brz-flow-empty-msg {
                padding: 40px 20px;
                text-align: center;
                color: #64748b;
                font-style: italic;
                background: #ffffff;
                border: 1.5px dashed #cbd5e1;
                border-radius: 10px;
            }
            .brz-textarea-input {
                width: 100%;
                box-sizing: border-box;
                font-family: monospace;
                direction: ltr;
                text-align: left;
                border-radius: 10px;
                border: 1.5px solid #cbd5e1;
                padding: 12px;
                font-size: 12px;
                resize: vertical;
                transition: border-color 0.15s;
            }
            .brz-textarea-input:focus {
                outline: none;
                border-color: var(--brz-brand, #1a73e8);
                box-shadow: 0 0 0 2px rgba(26,115,232,.1);
            }
        </style>

        <div class="brz-single-column" dir="rtl">
            <!-- Tabs Navigation -->
            <div class="brz-tab-nav">
                <button type="button" class="brz-tab-btn active" data-tab="brz-tab-builder">سازنده مشخصات فنی (Field Builder)</button>
                <button type="button" class="brz-tab-btn" data-tab="brz-tab-layout">چیدمان جدول مشخصات (Unified Layout)</button>
            </div>

            <!-- TAB 1: Field Builder -->
            <div id="brz-tab-builder" class="brz-tab-content active">
                <form id="brz-product-specs-form">
                    <?php wp_nonce_field( 'brz_product_specs_save_fields', '_wpnonce' ); ?>
                    
                    <div class="brz-card">
                        <div class="brz-card__header">
                            <h3>سازنده مشخصات فنی محصول (Field Builder)</h3>
                        </div>
                        <div class="brz-card__body">
                            <p style="color:var(--md-on-surface-variant, #666); font-size:13px; margin-bottom:15px;">
                                فیلدهای سفارشی مشخصات محصول را ایجاد و تنظیم کنید. تغییر کلید (Key) فیلدهای ثبت شده امکان‌پذیر نیست زیرا باعث گسستگی داده‌های قبلی می‌شود.
                            </p>
                            
                            <table class="brz-spec-table">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;">شناسه یکتا (Key)</th>
                                        <th style="width: 20%;">عنوان نمایشی</th>
                                        <th style="width: 15%;">نوع فیلد</th>
                                        <th style="width: 15%;">پیشوند نمایشی</th>
                                        <th style="width: 15%;">پسوند نمایشی</th>
                                        <th style="width: 15%;">گزینه‌ها / فرمت بازه</th>
                                        <th style="width: 5%;">عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="brz-spec-tbody">
                                    <?php if ( ! empty( $fields ) ) : ?>
                                        <?php foreach ( $fields as $f ) : ?>
                                            <tr class="brz-spec-row" data-key="<?php echo esc_attr( $f['key'] ); ?>">
                                                <td>
                                                    <input type="text" class="brz-spec-key" value="<?php echo esc_attr( $f['key'] ); ?>" disabled />
                                                </td>
                                                <td>
                                                    <input type="text" class="brz-spec-label" value="<?php echo esc_attr( $f['label'] ); ?>" placeholder="مثلاً: رده سنی" maxlength="150" />
                                                </td>
                                                <td>
                                                    <select class="brz-spec-type" disabled>
                                                        <option value="boolean" <?php selected( $f['type'], 'boolean' ); ?>>ساده (بله/خیر)</option>
                                                        <option value="integer" <?php selected( $f['type'], 'integer' ); ?>>عدد صحیح (Integer)</option>
                                                        <option value="decimal" <?php selected( $f['type'], 'decimal' ); ?>>عدد اعشاری (Decimal)</option>
                                                        <option value="range" <?php selected( $f['type'], 'range' ); ?>>بازه عددی (کمینه/بیشینه)</option>
                                                        <option value="array" <?php selected( $f['type'], 'array' ); ?>>آرایه انتخابی (چند گزینه‌ای)</option>
                                                    </select>
                                                </td>
                                                <?php $is_range = ( $f['type'] === 'range' ); ?>
                                                <td>
                                                    <input type="text" class="brz-spec-prefix" value="<?php echo esc_attr( $is_range ? '' : $f['prefix'] ); ?>" placeholder="<?php echo $is_range ? '⚙️ تنظیم پیشوند بازه' : 'پیشوند'; ?>" maxlength="100" <?php echo $is_range ? 'readonly style="cursor:pointer; background:#f8fafc; border-color:#1a73e8; color:#1a73e8; font-weight:600; text-align:center;"' : ''; ?> />
                                                </td>
                                                <td>
                                                    <input type="text" class="brz-spec-suffix" value="<?php echo esc_attr( $is_range ? '' : $f['suffix'] ); ?>" placeholder="<?php echo $is_range ? '⚙️ تنظیم پسوند بازه' : 'پسوند'; ?>" maxlength="100" <?php echo $is_range ? 'readonly style="cursor:pointer; background:#f8fafc; border-color:#1a73e8; color:#1a73e8; font-weight:600; text-align:center;"' : ''; ?> />
                                                </td>
                                                <td>
                                                    <input type="text" class="brz-spec-options" value="<?php echo esc_attr( $f['options'] ); ?>" placeholder="<?php echo $is_range ? 'فرمت ذخیره شده در بازه' : 'فرمت بازه یا گزینه‌ها'; ?>" <?php echo $is_range ? 'disabled style="background:#f2f2f2;"' : ''; ?> />
                                                </td>
                                                <td style="text-align: center;">
                                                    <button type="button" class="brz-spec-delete-btn" title="حذف فیلد">✕</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                                <button type="button" id="brz-spec-add-btn" class="brz-button brz-button--secondary">افزودن فیلد جدید</button>
                                <button type="button" id="brz-spec-save-btn" class="brz-button">ذخیره تغییرات</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Unified Specs Layout -->
            <div id="brz-tab-layout" class="brz-tab-content">
                <?php
                $layout = self::get_unified_layout();
                $categories = get_terms( array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                ) );
                ?>
                <div class="brz-card">
                    <div class="brz-card__header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <h3 class="brz-card__title">مدیریت هوشمند چیدمان مشخصات با هوش مصنوعی (AI-Assisted Layout)</h3>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <select id="brz-layout-switcher" style="padding:6px 12px; border-radius:8px; border:1px solid #ccc; font-size:13px; min-width:240px; height: 32px; box-sizing: border-box;">
                                <option value="global">چیدمان عمومی فروشگاه (Global Layout)</option>
                                <?php if ( is_array( $categories ) && ! is_wp_error( $categories ) ) : ?>
                                    <?php foreach ( $categories as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat->term_id ); ?>">چیدمان اختصاصی: <?php echo esc_html( $cat->name ); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" id="brz-layout-create-override-btn" class="brz-button brz-button--secondary" style="padding: 6px 15px; font-size: 12px; display: none; height: 32px;">ایجاد چیدمان اختصاصی</button>
                            <button type="button" id="brz-layout-delete-override-btn" class="brz-button brz-button--danger" style="padding: 6px 15px; font-size: 12px; display: none; background:#ef4444; color:#fff; border:none; border-radius:6px; cursor:pointer; height: 32px;">حذف چیدمان اختصاصی</button>
                        </div>
                    </div>
                    <div class="brz-card__body">
                        <p style="color:var(--md-on-surface-variant, #666); font-size:13px; margin-bottom:20px;">
                            با این ابزار می‌توانید ترتیب نمایش مشخصه‌ها را بدون نیاز به مرتب‌سازی دستی سنگین و فقط با کمک هوش مصنوعی تنظیم کنید. لیست آیتم‌ها را کپی کنید، به ایجنت بدهید تا بهینه کند و خروجی دریافتی را در کادر پیست نمایید.
                        </p>

                        <div class="brz-flow-container">
                            <!-- Column 1: AI Actions -->
                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:15px;">
                                    <h4 style="margin:0 0 10px 0; color:#1e293b; font-weight:600;">گام اول: کپی لیست برای ایجنت</h4>
                                    <p style="font-size:12px; color:#64748b; margin-bottom:12px; line-height:1.5;">
                                        لیست فیلدها و ترتیبی که در پیش‌نمایش سمت چپ می‌بینید را کپی کرده و به ایجنت بدهید تا با استفاده از روانشناسی کاربر آن را بهینه‌سازی کند.
                                    </p>
                                    <button type="button" id="brz-layout-copy-btn" class="brz-button brz-button--secondary" style="width:100%; justify-content:center;">کپی لیست مشخصات برای هوش مصنوعی</button>
                                </div>

                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:15px;">
                                    <h4 style="margin:0 0 10px 0; color:#1e293b; font-weight:600;">گام دوم: جایگذاری چیدمان بهینه شده</h4>
                                    <p style="font-size:12px; color:#64748b; margin-bottom:12px; line-height:1.5;">
                                        خروجی بهینه‌سازی شده دریافتی از ایجنت را در کادر زیر پیست کنید. پردازشگر به صورت هوشمند شناسه‌ها را استخراج می‌کند.
                                    </p>
                                    <textarea id="brz-layout-paste-input" rows="7" class="brz-textarea-input" placeholder="آیتم‌های دریافتی از هوش مصنوعی را اینجا پیست کنید..."></textarea>
                                    <button type="button" id="brz-layout-apply-btn" class="brz-button" style="width:100%; margin-top:12px; justify-content:center;">بروزرسانی پیش‌نمایش چیدمان</button>
                                </div>
                            </div>

                            <!-- Column 2: Live Flow Preview -->
                            <div class="brz-flow-list-wrapper">
                                <h4 id="brz-flow-preview-title" style="margin:0; color:#0f172a; font-weight:700; font-size:14px;">پیش‌نمایش ترتیب نمایش چیدمان</h4>
                                <div class="brz-flow-list" id="brz-flow-list-container">
                                    <!-- Dynamic Flow Items Rendered Here -->
                                </div>
                                <div id="brz-flow-fallback-msg" class="brz-flow-empty-msg" style="display:none;"></div>
                            </div>
                        </div>

                        <div style="margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 20px; text-align: left;">
                            <button type="button" id="brz-layout-save-btn" class="brz-button">ذخیره نهایی چیدمان</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php self::render_range_format_modal(); ?>
        </div>

        <script>
            jQuery(document).ready(function($) {
                var $tbody = $('#brz-spec-tbody');

                function showSnackbar(message, type) {
                    var $bar = $('#brz-snackbar');
                    $bar.text(message).removeClass('success error').addClass('show ' + type);
                    setTimeout(function() {
                        $bar.removeClass('show');
                    }, 3000);
                }

                // TAB SYSTEM
                $('.brz-tab-btn').on('click', function() {
                    var tabId = $(this).data('tab');
                    $('.brz-tab-btn').removeClass('active');
                    $(this).addClass('active');
                    
                    $('.brz-tab-content').removeClass('active');
                    $('#' + tabId).addClass('active');
                });

                // AI ASSISTED LAYOUT SYSTEM STATE
                let layoutState = <?php echo json_encode( $layout ); ?>;
                const availableItems = <?php echo json_encode( self::get_all_available_layout_items() ); ?>;

                function renderActiveLayout() {
                    const activeKey = $('#brz-layout-switcher').val();
                    const container = $('#brz-flow-list-container');
                    const fallbackMsg = $('#brz-flow-fallback-msg');
                    const deleteBtn = $('#brz-layout-delete-override-btn');
                    const createBtn = $('#brz-layout-create-override-btn');
                    
                    container.empty();
                    fallbackMsg.hide();
                    deleteBtn.hide();
                    createBtn.hide();

                    let order = [];
                    let isOverride = false;

                    if (activeKey === 'global') {
                        order = layoutState.global;
                        $('#brz-flow-preview-title').text('پیش‌نمایش ترتیب نمایش عمومی (Global)');
                    } else {
                        isOverride = layoutState.categories && layoutState.categories.hasOwnProperty(activeKey);
                        if (isOverride) {
                            order = layoutState.categories[activeKey];
                            deleteBtn.show();
                            $('#brz-flow-preview-title').text('پیش‌نمایش ترتیب چیدمان اختصاصی دسته');
                        } else {
                            createBtn.show();
                            fallbackMsg.html('این دسته‌بندی در حال حاضر دارای چیدمان اختصاصی نیست و از <b>چیدمان عمومی فروشگاه (Global Layout)</b> ارث‌بری می‌کند.').show();
                            $('#brz-flow-preview-title').text('ارث‌بری از عمومی (Global)');
                            return;
                        }
                    }

                    // Map keys to available layout metadata
                    const orderedItems = [];
                    const clonedAvailable = { ...availableItems };

                    for (const key of order) {
                        if (clonedAvailable.hasOwnProperty(key)) {
                            orderedItems.push({ key: key, ...clonedAvailable[key] });
                            delete clonedAvailable[key];
                        }
                    }
                    // Append any missing items as fail-safe
                    for (const key in clonedAvailable) {
                        orderedItems.push({ key: key, ...clonedAvailable[key] });
                    }

                    // Render DOM elements
                    orderedItems.forEach((item, index) => {
                        let badgeClass = 'fallback';
                        let badgeLabel = 'ویژگی';

                        if (item.key.startsWith('manual_') || item.key.startsWith('min_') || item.key.startsWith('max_') || item.key.startsWith('best_') || item.key === 'difficulty' || item.key.startsWith('is_') || item.key.startsWith('has_') || item.key.startsWith('needs_') || item.key === 'card_count' || item.key.startsWith('meople_') || item.key.startsWith('pieces_')) {
                            badgeClass = 'spec';
                            badgeLabel = 'مشخصه بایروز';
                        } else if (item.key.startsWith('pa_')) {
                            badgeClass = 'attr';
                            badgeLabel = 'ویژگی ووکامرس';
                        } else if (item.key === 'weight' || item.key === 'dimensions') {
                            badgeClass = 'physical';
                            badgeLabel = 'ویژگی فیزیکی';
                        }

                        const itemHtml = `
                            <div class="brz-flow-item" data-slug="${item.key}">
                                <span class="brz-flow-number">${index + 1}</span>
                                <span class="brz-flow-label">${item.label}</span>
                                <span class="brz-flow-badge ${badgeClass}">${badgeLabel}</span>
                            </div>
                        `;
                        container.append(itemHtml);
                    });
                }

                // Initialize Unified Layout Preview
                renderActiveLayout();

                // Switch Layout Handler
                $('#brz-layout-switcher').on('change', function() {
                    renderActiveLayout();
                    $('#brz-layout-paste-input').val('');
                });

                // Create Override Handler
                $('#brz-layout-create-override-btn').on('click', function() {
                    const activeKey = $('#brz-layout-switcher').val();
                    if (activeKey === 'global') return;
                    
                    // Initialize category override with a clone of global layout order
                    if (!layoutState.categories) {
                        layoutState.categories = {};
                    }
                    layoutState.categories[activeKey] = [...layoutState.global];
                    renderActiveLayout();
                    showSnackbar('چیدمان اختصاصی ایجاد شد. اکنون می‌توانید آن را بهینه‌سازی کنید.', 'success');
                });

                // Delete Override Handler
                $('#brz-layout-delete-override-btn').on('click', function() {
                    const activeKey = $('#brz-layout-switcher').val();
                    if (activeKey === 'global') return;
                    
                    if (confirm('آیا مایل به حذف چیدمان اختصاصی این دسته‌بندی هستید؟ چیدمان این دسته‌بندی به عمومی بازمی‌گردد.')) {
                        if (layoutState.categories && layoutState.categories.hasOwnProperty(activeKey)) {
                            delete layoutState.categories[activeKey];
                        }
                        renderActiveLayout();
                        showSnackbar('چیدمان اختصاصی حذف شد و به چیدمان عمومی بازگشت.', 'success');
                    }
                });

                // Copy for AI Button Click Handler
                $('#brz-layout-copy-btn').on('click', function() {
                    const activeKey = $('#brz-layout-switcher').val();
                    let order = [];

                    if (activeKey === 'global') {
                        order = layoutState.global;
                    } else if (layoutState.categories && layoutState.categories.hasOwnProperty(activeKey)) {
                        order = layoutState.categories[activeKey];
                    } else {
                        // Fallback to global if override not created
                        order = layoutState.global;
                    }

                    // Format text line-by-line with optimized AI prompts
                    let text = "--- ابزار بهینه‌سازی هوشمند چیدمان مشخصات بایروز (Unified Specs Layout) ---\n\n";
                    text += "شما یک متخصص برجسته در زمینه تجربه کاربری (UX)، روانشناسی خریدار و بهینه‌سازی نرخ تبدیل (CRO) در حوزه اسباب‌بازی و بازی فکری هستید.\n";
                    text += "وظیفه شما مرتب‌سازی و اولویت‌بندی مجدد شناسه‌های (Keys) ویژگی‌های محصول زیر بر اساس الگوی ذهنی خریدار است تا بار شناختی کاربر به حداقل رسیده و ترغیب به خرید شود.\n\n";
                    text += "دستورالعمل‌های خروجی بسیار مهم:\n";
                    text += "۱. خروجی شما باید منحصراً شامل یک باکس کپی‌شونده (Code Block) باشد.\n";
                    text += "۲. در داخل باکس کپی‌شونده، فقط شناسه‌ها (مانند manual_min_age یا pa_brand) را به ترتیب اولویت نمایش (از بالا به پایین)، هر کدام در یک خط قرار دهید.\n";
                    text += "۳. هیچ متن اضافی، توضیح، سلام/خداحافظی یا شماره‌گذاری در داخل یا خارج باکس کپی‌شونده قرار ندهید. خروجی باید کاملاً آماده کپی و پیست مستقیم در سایت باشد.\n\n";
                    text += "لیست شناسه‌های فعلی برای بهینه‌سازی:\n";
                    
                    order.forEach((key, index) => {
                        if (availableItems.hasOwnProperty(key)) {
                            text += `${index + 1}. ${key} : ${availableItems[key].label} (${availableItems[key].type})\n`;
                        }
                    });

                    // Add browser clipboard fallback
                    const $temp = $('<textarea>');
                    $('body').append($temp);
                    $temp.val(text).select();
                    document.execCommand('copy');
                    $temp.remove();

                    showSnackbar('لیست مشخصات در کلیپ‌بورد کپی شد. اکنون آن را به ایجنت بدهید.', 'success');
                });

                // Apply Pasted Order Handler (Smart Resilient Parser)
                $('#brz-layout-apply-btn').on('click', function() {
                    const text = $.trim($('#brz-layout-paste-input').val());
                    if (text === '') {
                        alert('لطفاً ابتدا چیدمان جدید دریافتی را در کادر پیست کنید.');
                        return;
                    }

                    const activeKey = $('#brz-layout-switcher').val();
                    
                    // Regex search for valid slugs (alphanumeric and underscore) in the pasted text
                    const pattern = /[a-zA-Z0-9_]+/g;
                    const matches = text.match(pattern) || [];
                    
                    const ordered = [];
                    const seen = new Set();

                    // 1. Process valid matched slugs from paste
                    matches.forEach(match => {
                        if (availableItems.hasOwnProperty(match) && !seen.has(match)) {
                            ordered.push(match);
                            seen.add(match);
                        }
                    });

                    if (ordered.length === 0) {
                        alert('هیچ شناسه معتبری در متن وارد شده یافت نشد. لطفاً ساختار کپی شده از چت ایجنت را چک کنید.');
                        return;
                    }

                    // 2. Append any missing slugs (fail-safe) to the end
                    for (const key in availableItems) {
                        if (!seen.has(key)) {
                            ordered.push(key);
                        }
                    }

                    // 3. Update the state visually
                    if (activeKey === 'global') {
                        layoutState.global = ordered;
                    } else {
                        if (!layoutState.categories) {
                            layoutState.categories = {};
                        }
                        layoutState.categories[activeKey] = ordered;
                    }

                    // 4. Rerender list visual preview
                    renderActiveLayout();
                    showSnackbar('پیش‌نمایش چیدمان بروز شد. برای اعمال قطعی دکمه «ذخیره نهایی چیدمان» را بزنید.', 'success');
                });

                // Save layout configuration to server via AJAX (Option 2)
                $('#brz-layout-save-btn').on('click', function() {
                    const btn = $(this);
                    btn.prop('disabled', true).text('در حال ذخیره چیدمان…');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'brz_save_unified_specs_layout',
                            global: layoutState.global,
                            categories: layoutState.categories || {},
                            _wpnonce: '<?php echo esc_js( wp_create_nonce( "brz_product_specs_save_layout_nonce" ) ); ?>'
                        },
                        success: function(res) {
                            btn.prop('disabled', false).text('ذخیره نهایی چیدمان');
                            if (res.success) {
                                showSnackbar(res.data.message || 'چیدمان با موفقیت ذخیره شد.', 'success');
                            } else {
                                showSnackbar('خطا: ' + res.data.message, 'error');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('ذخیره نهایی چیدمان');
                            showSnackbar('خطای ارتباط با سرور.', 'error');
                        }
                    });
                });

                // Original Field Builder Scripts
                $tbody.on('change', '.brz-spec-type', function() {
                    var $row = $(this).closest('tr');
                    var val  = $(this).val();
                    var $opt = $row.find('.brz-spec-options');
                    var $pref = $row.find('.brz-spec-prefix');
                    var $suff = $row.find('.brz-spec-suffix');
                    
                    // Reset fields
                    $opt.prop('readonly', false).prop('disabled', false).css({ 'cursor': '', 'background': '', 'border-color': '', 'color': '', 'font-weight': '', 'text-align': '' }).val('');
                    $pref.prop('disabled', false).prop('readonly', false).css({ 'cursor': '', 'background': '', 'border-color': '', 'color': '', 'font-weight': '', 'text-align': '' }).attr('placeholder', 'پیشوند').val('');
                    $suff.prop('disabled', false).prop('readonly', false).css({ 'cursor': '', 'background': '', 'border-color': '', 'color': '', 'font-weight': '', 'text-align': '' }).attr('placeholder', 'پسوند').val('');
                    
                    if (val === 'array') {
                        $opt.attr('placeholder', 'گزینه‌ها با کاما جدا شوند');
                    } else if (val === 'range') {
                        $opt.prop('disabled', true).css('background', '#f2f2f2').attr('placeholder', 'فرمت ذخیره شده در بازه').val('');
                        $pref.prop('readonly', true).attr('placeholder', '⚙️ تنظیم پیشوند بازه').css({ 'cursor': 'pointer', 'background': '#f8fafc', 'border-color': '#1a73e8', 'color': '#1a73e8', 'font-weight': '600', 'text-align': 'center' });
                        $suff.prop('readonly', true).attr('placeholder', '⚙️ تنظیم پسوند بازه').css({ 'cursor': 'pointer', 'background': '#f8fafc', 'border-color': '#1a73e8', 'color': '#1a73e8', 'font-weight': '600', 'text-align': 'center' });
                    } else {
                        $opt.prop('disabled', true).css('background', '#f2f2f2').attr('placeholder', '');
                    }
                });

                // Range Format Modal Handling
                var $activeOptionsInput = null;

                function openRangeFormatModal($input) {
                    $activeOptionsInput = $input;
                    var rawVal = $input.val();
                    
                    var parts = rawVal.split(';');
                    var bothFmt = $.trim(parts[0] || '');
                    var minFmt = $.trim(parts[1] || '');
                    var maxFmt = $.trim(parts[2] || '');
                    
                    var bothBefore = '', bothBetween = ' تا ', bothAfter = '';
                    if (bothFmt.indexOf('{min}') !== -1 && bothFmt.indexOf('{max}') !== -1) {
                        bothBefore = bothFmt.substring(0, bothFmt.indexOf('{min}'));
                        bothBetween = bothFmt.substring(bothFmt.indexOf('{min}') + 5, bothFmt.indexOf('{max}'));
                        bothAfter = bothFmt.substring(bothFmt.indexOf('{max}') + 5);
                    } else {
                        var $row = $input.closest('tr');
                        bothAfter = $row.find('.brz-spec-suffix').val() || '';
                    }
                    
                    var minBefore = 'بالای ', minAfter = '';
                    if (minFmt.indexOf('{min}') !== -1) {
                        minBefore = minFmt.substring(0, minFmt.indexOf('{min}'));
                        minAfter = minFmt.substring(minFmt.indexOf('{min}') + 5);
                    } else {
                        var $row = $input.closest('tr');
                        var prefix = $row.find('.brz-spec-prefix').val() || '';
                        var suffix = $row.find('.brz-spec-suffix').val() || '';
                        minBefore = prefix ? prefix + ' ' : 'بالای ';
                        minAfter = suffix;
                    }
                    
                    var maxBefore = 'تا ', maxAfter = '';
                    if (maxFmt.indexOf('{max}') !== -1) {
                        maxBefore = maxFmt.substring(0, maxFmt.indexOf('{max}'));
                        maxAfter = maxFmt.substring(maxFmt.indexOf('{max}') + 5);
                    } else {
                        var $row = $input.closest('tr');
                        var suffix = $row.find('.brz-spec-suffix').val() || '';
                        maxAfter = suffix;
                    }

                    $('#brz-rf-both-before').val(bothBefore);
                    $('#brz-rf-both-between').val(bothBetween);
                    $('#brz-rf-both-after').val(bothAfter);
                    
                    $('#brz-rf-min-before').val(minBefore);
                    $('#brz-rf-min-after').val(minAfter);
                    
                    $('#brz-rf-max-before').val(maxBefore);
                    $('#brz-rf-max-after').val(maxAfter);
                    
                    $('#brz-range-format-modal').css('display', 'flex');
                }

                $tbody.on('focus click', '.brz-spec-prefix, .brz-spec-suffix', function(e) {
                    var $row = $(this).closest('tr');
                    var val = $row.find('.brz-spec-type').val();
                    if (val === 'range') {
                        e.preventDefault();
                        $(this).blur();
                        openRangeFormatModal($row.find('.brz-spec-options'));
                    }
                });

                $('#brz-rf-save').on('click', function() {
                    if (!$activeOptionsInput) return;
                    
                    var bothBefore = $('#brz-rf-both-before').val();
                    var bothBetween = $('#brz-rf-both-between').val();
                    var bothAfter = $('#brz-rf-both-after').val();
                    
                    var minBefore = $('#brz-rf-min-before').val();
                    var minAfter = $('#brz-rf-min-after').val();
                    
                    var maxBefore = $('#brz-rf-max-before').val();
                    var maxAfter = $('#brz-rf-max-after').val();
                    
                    var bothFmt = bothBefore + '{min}' + bothBetween + '{max}' + bothAfter;
                    var minFmt = minBefore + '{min}' + minAfter;
                    var maxFmt = maxBefore + '{max}' + maxAfter;
                    
                    var finalVal = bothFmt + '; ' + minFmt + '; ' + maxFmt;
                    $activeOptionsInput.val(finalVal);
                    
                    $('#brz-range-format-modal').hide();
                    $activeOptionsInput = null;
                });

                $('#brz-rf-cancel').on('click', function() {
                    $('#brz-range-format-modal').hide();
                    $activeOptionsInput = null;
                });

                // Close modal when clicking outside
                $('#brz-range-format-modal').on('click', function(e) {
                    if (e.target === this) {
                        $(this).hide();
                        $activeOptionsInput = null;
                    }
                });

                $('#brz-spec-add-btn').on('click', function() {
                    var rowHtml = '<tr class="brz-spec-row is-new">' +
                        '<td><input type="text" class="brz-spec-key" placeholder="شناسه (مانند: age_range)" /></td>' +
                        '<td><input type="text" class="brz-spec-label" placeholder="عنوان فارسی" /></td>' +
                        '<td><select class="brz-spec-type">' +
                            '<option value="boolean">ساده (بله/خیر)</option>' +
                            '<option value="integer">عدد صحیح (Integer)</option>' +
                            '<option value="decimal">عدد اعشاری (Decimal)</option>' +
                            '<option value="range">بازه عددی (کمینه/بیشینه)</option>' +
                            '<option value="array">آرایه انتخابی (چند گزینه‌ای)</option>' +
                        '</select></td>' +
                        '<td><input type="text" class="brz-spec-prefix" placeholder="پیشوند" /></td>' +
                        '<td><input type="text" class="brz-spec-suffix" placeholder="پسوند" /></td>' +
                        '<td><input type="text" class="brz-spec-options brz-spec-options-input" placeholder="" disabled style="background:#f2f2f2;" /></td>' +
                        '<td style="text-align: center;"><button type="button" class="brz-spec-delete-btn" title="حذف فیلد">✕</button></td>' +
                        '</tr>';
                    $tbody.append(rowHtml);
                });

                $tbody.on('click', '.brz-spec-delete-btn', function() {
                    $(this).closest('tr').remove();
                });

                $('#brz-spec-save-btn').on('click', function() {
                    var $btn     = $(this);
                    var hasError = false;
                    
                    $('.brz-field-error').removeClass('brz-field-error');

                    var fields = [];
                    $tbody.find('.brz-spec-row').each(function() {
                        var $row        = $(this);
                        var isNew       = $row.hasClass('is-new');
                        var keyInput    = $row.find('.brz-spec-key');
                        var labelInput  = $row.find('.brz-spec-label');
                        var typeSelect  = $row.find('.brz-spec-type');
                        var prefixInput = $row.find('.brz-spec-prefix');
                        var suffixInput = $row.find('.brz-spec-suffix');
                        var optionsInput = $row.find('.brz-spec-options');

                        var key     = $.trim(isNew ? keyInput.val() : $row.data('key'));
                        var label   = $.trim(labelInput.val());
                        var type    = typeSelect.val();
                        var prefix  = $.trim(prefixInput.val());
                        var suffix  = $.trim(suffixInput.val());
                        var options = $.trim(optionsInput.val());

                        var keyRegex = /^[a-zA-Z0-9_]+$/;
                        if (key === '' || !keyRegex.test(key)) {
                            keyInput.addClass('brz-field-error');
                            hasError = true;
                        }

                        if (label === '') {
                            labelInput.addClass('brz-field-error');
                            hasError = true;
                        }

                        if (type === 'array' && options === '') {
                            optionsInput.addClass('brz-field-error');
                            hasError = true;
                        }

                        fields.push({
                            key: key,
                            label: label,
                            type: type,
                            prefix: prefix,
                            suffix: suffix,
                            options: options
                        });
                    });

                    if (hasError) {
                        showSnackbar('لطفاً خطاهای فیلدها را برطرف کنید. شناسه فیلد فقط باید شامل حروف، اعداد و underscore باشد.', 'error');
                        return;
                    }

                    $btn.prop('disabled', true).text('در حال ذخیره…');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'brz_save_product_specs_fields',
                            _wpnonce: $('#_wpnonce').val(),
                            fields: fields
                        },
                        success: function(res) {
                            $btn.prop('disabled', false).text('ذخیره تغییرات');
                            if (res.success) {
                                showSnackbar(res.data.message || 'تنظیمات ذخیره شد.', 'success');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                showSnackbar(res.data.message || 'خطا در ذخیره‌سازی.', 'error');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('ذخیره تغییرات');
                            showSnackbar('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', 'error');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX action callback to save custom fields definitions.
     */
    public static function ajax_save_fields(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        if ( ! check_ajax_referer( 'brz_product_specs_save_fields', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست معتبر نیست.' ), 403 );
        }

        $raw_fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? $_POST['fields'] : array();
        $fields        = array();
        $existing_keys = array();

        foreach ( $raw_fields as $raw ) {
            $key = isset( $raw['key'] ) ? sanitize_key( $raw['key'] ) : '';
            if ( empty( $key ) ) {
                continue;
            }

            if ( in_array( $key, $existing_keys, true ) ) {
                continue;
            }
            $existing_keys[] = $key;

            $allowed_types = array( 'boolean', 'integer', 'decimal', 'range', 'array' );
            $type          = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : 'boolean';
            if ( ! in_array( $type, $allowed_types, true ) ) {
                $type = 'boolean';
            }

            $fields[] = array(
                'key'     => $key,
                'label'   => sanitize_text_field( isset( $raw['label'] ) ? $raw['label'] : '' ),
                'type'    => $type,
                'prefix'  => sanitize_text_field( isset( $raw['prefix'] ) ? $raw['prefix'] : '' ),
                'suffix'  => sanitize_text_field( isset( $raw['suffix'] ) ? $raw['suffix'] : '' ),
                'options' => sanitize_text_field( isset( $raw['options'] ) ? $raw['options'] : '' ),
            );
        }

        update_option( 'brz_product_specs_fields', $fields );
        
        wp_send_json_success( array( 'message' => 'تنظیمات مشخصات محصول با موفقیت ذخیره شد.' ) );
    }

    /**
     * Find matching max key for a min key.
     */
    private static function get_matching_max_key( string $min_key, array $all_keys ): ?string {
        if ( strpos( $min_key, 'min_' ) === 0 ) {
            $max_key = 'max_' . substr( $min_key, 4 );
            if ( in_array( $max_key, $all_keys, true ) ) {
                return $max_key;
            }
        }
        if ( strpos( $min_key, 'manual_min_' ) === 0 ) {
            $max_key = 'manual_max_' . substr( $min_key, 11 );
            if ( in_array( $max_key, $all_keys, true ) ) {
                return $max_key;
            }
        }
        if ( substr( $min_key, -4 ) === '_min' ) {
            $max_key = substr( $min_key, 0, -4 ) . '_max';
            if ( in_array( $max_key, $all_keys, true ) ) {
                return $max_key;
            }
        }
        return null;
    }

    /**
     * Frontend injection: Displays specifications inside WooCommerce's additional information tab.
     */
    public static function render_custom_specs( $product ): void {
        if ( ! is_object( $product ) ) {
            global $product;
        }
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return;
        }

        $fields          = self::get_fields();
        $specs_to_render = array();

        $fields_by_key = array();
        foreach ( $fields as $field ) {
            $fields_by_key[ $field['key'] ] = $field;
        }
        $all_keys = array_keys( $fields_by_key );
        $processed_keys = array();

        foreach ( $fields as $field ) {
            $key = $field['key'];
            if ( in_array( $key, $processed_keys, true ) ) {
                continue;
            }

            // Check if this is a min field and has a matching max field in the definitions
            $max_key = self::get_matching_max_key( $key, $all_keys );

            if ( $max_key ) {
                $max_field = $fields_by_key[ $max_key ];
                $processed_keys[] = $key;
                $processed_keys[] = $max_key;

                $min_val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                $max_val = get_post_meta( $product->get_id(), '_brz_spec_' . $max_key, true );

                if ( $min_val === '' && $max_val === '' ) {
                    continue;
                }

                // Determine combined label by stripping out "حداقل" and "حداکثر"
                $combined_label = trim( str_replace( array( 'حداقل', 'حداکثر' ), '', $field['label'] ) );
                if ( empty( $combined_label ) ) {
                    $combined_label = $field['label'];
                }

                if ( $min_val !== '' && $max_val !== '' ) {
                    if ( $min_val === $max_val ) {
                        $value_html = $field['prefix'] . self::to_persian_digits( $min_val ) . $field['suffix'];
                    } else {
                        // Strip out "بالای", "بیشتر از" to build a clean range prefix
                        $clean_prefix = trim( str_replace( array( 'بالای', 'بیشتر از', 'کمتر از', 'زیر' ), '', $field['prefix'] ) );
                        if ( ! empty( $clean_prefix ) && substr( $clean_prefix, -1 ) !== ' ' ) {
                            $clean_prefix .= ' ';
                        }
                        $value_html = $clean_prefix . self::to_persian_digits( $min_val ) . ' تا ' . self::to_persian_digits( $max_val ) . $max_field['suffix'];
                    }
                } elseif ( $min_val !== '' ) {
                    $value_html = $field['prefix'] . self::to_persian_digits( $min_val ) . $field['suffix'];
                } else {
                    $value_html = $max_field['prefix'] . self::to_persian_digits( $max_val ) . $max_field['suffix'];
                }

                if ( ! empty( $value_html ) ) {
                    $specs_to_render[ $combined_label ] = $value_html;
                }
                continue;
            }

            // Normal rendering (non-paired field)
            $processed_keys[] = $key;
            $type       = $field['type'];
            $label      = $field['label'];
            $prefix     = $field['prefix'];
            $suffix     = $field['suffix'];
            $value_html = '';

            if ( 'boolean' === $type ) {
                $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                if ( $val === '' ) {
                    continue;
                }
                
                $is_bakala = self::is_bakala_theme();
                if ( $val === '1' ) {
                    $value_html = $is_bakala ? '<i class="icon icon-green-mark"></i>' : 'بله';
                } else {
                    $value_html = $is_bakala ? '<i class="icon icon-red-close"></i>' : 'خیر';
                }
            } elseif ( 'range' === $type ) {
                $keys = self::get_range_meta_keys( $key );
                $min  = get_post_meta( $product->get_id(), $keys[0], true );
                $max  = get_post_meta( $product->get_id(), $keys[1], true );

                if ( $min === '' && $max === '' ) {
                    continue;
                }

                $raw_options = str_replace( '؛', ';', (string) $field['options'] );
                $formats     = array_map( 'trim', explode( ';', $raw_options ) );
                
                $def_both = '{min} تا {max}' . ( $suffix ? ' ' . $suffix : '' );
                $def_min  = ( $prefix ? $prefix . ' ' : '' ) . '{min}' . ( $suffix ? ' ' . $suffix : '' );
                $def_max  = 'تا {max}' . ( $suffix ? ' ' . $suffix : '' );
                
                $fmt_both = isset( $formats[0] ) && '' !== $formats[0] ? $formats[0] : $def_both;
                $fmt_min  = isset( $formats[1] ) && '' !== $formats[1] ? $formats[1] : $def_min;
                $fmt_max  = isset( $formats[2] ) && '' !== $formats[2] ? $formats[2] : $def_max;

                if ( $min !== '' && $max !== '' ) {
                    if ( $min === $max ) {
                        $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
                    } else {
                        $range_str = str_replace(
                            array( '{min}', '{max}' ),
                            array( self::to_persian_digits( $min ), self::to_persian_digits( $max ) ),
                            $fmt_both
                        );
                    }
                } elseif ( $min !== '' ) {
                    $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
                } else {
                    $range_str = str_replace( '{max}', self::to_persian_digits( $max ), $fmt_max );
                }

                $value_html = $range_str;
            } elseif ( 'array' === $type ) {
                $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                if ( empty( $val ) ) {
                    continue;
                }
                $decoded = json_decode( $val, true );
                if ( ! is_array( $decoded ) ) {
                    $decoded = maybe_unserialize( $val );
                }
                if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                    continue;
                }
                
                $persian_values = array_map( array( __CLASS__, 'to_persian_digits' ), $decoded );
                $value_html     = $prefix . implode( '، ', $persian_values ) . $suffix;
            } elseif ( 'integer' === $type || 'decimal' === $type ) {
                $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                if ( $val === '' ) {
                    continue;
                }
                $value_html = $prefix . self::to_persian_digits( $val ) . $suffix;
            } elseif ( 'string' === $type || 'text' === $type ) {
                $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                if ( $val === '' ) {
                    continue;
                }
                $value_html = $prefix . self::to_persian_digits( sanitize_text_field( $val ) ) . $suffix;
            }

            if ( ! empty( $value_html ) ) {
                $specs_to_render[ $label ] = $value_html;
            }
        }

        if ( empty( $specs_to_render ) ) {
            return;
        }

        if ( self::is_bakala_theme() ) {
            ?>
            <ul class="spec-list brz-custom-specs-list" style="margin-bottom:10px;">
                <?php foreach ( $specs_to_render as $label => $html ) : ?>
                    <li class="clearfix">
                        <span class="technicalspecs-title"><?php echo esc_html( $label ); ?></span>
                        <span class="technicalspecs-value"><span><?php echo wp_kses_post( $html ); ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php
        } else {
            ?>
            <table class="woocommerce-product-attributes shop_attributes brz-custom-specs-table" style="margin-bottom: 20px; width: 100%; border-collapse: collapse;">
                <tbody>
                    <?php foreach ( $specs_to_render as $label => $html ) : ?>
                        <tr class="woocommerce-product-attributes-item">
                            <th class="woocommerce-product-attributes-item__label" style="text-align: right; font-weight: bold; padding: 8px 15px; width: 220px; border-bottom: 1px solid #eee;"><?php echo esc_html( $label ); ?></th>
                            <td class="woocommerce-product-attributes-item__value" style="padding: 8px 15px; border-bottom: 1px solid #eee;"><?php echo wp_kses_post( $html ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
    }

    /**
     * Check if active template/stylesheet is the bakala theme.
     */
    private static function is_bakala_theme(): bool {
        $theme = wp_get_theme();
        return ( 'bakala' === $theme->get_template() || 'bakala' === $theme->get_stylesheet() );
    }

    /**
     * Convert English digits to Persian digits.
     */
    private static function to_persian_digits( $str ): string {
        $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        return str_replace($en, $fa, (string) $str);
    }

    /**
     * Parse age range from WooCommerce term name or description dynamically.
     */
    public static function parse_range_from_term( $term_name, $term_description = '' ): ?array {
        $text = $term_name . ' ' . $term_description;
        
        $persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
        $arabic_digits  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
        $english_digits = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
        
        $text = str_replace( $persian_digits, $english_digits, $text );
        $text = str_replace( $arabic_digits, $english_digits, $text );
        
        if ( preg_match( '/([0-9]+)\s*(?:تا|to|-)\s*([0-9]+)/i', $text, $matches ) ) {
            return array(
                'min' => intval( $matches[1] ),
                'max' => intval( $matches[2] ),
            );
        }
        
        if ( preg_match( '/(?:بالای|بزرگتر از|over|>|\+)\s*([0-9]+)/i', $text, $matches ) ) {
            return array(
                'min' => intval( $matches[1] ),
                'max' => 99,
            );
        }
        if ( preg_match( '/([0-9]+)\s*(?:\+)/i', $text, $matches ) ) {
            return array(
                'min' => intval( $matches[1] ),
                'max' => 99,
            );
        }
        
        if ( preg_match( '/(?:زیر|کمتر از|under|<)\s*([0-9]+)/i', $text, $matches ) ) {
            return array(
                'min' => 0,
                'max' => intval( $matches[1] ),
            );
        }
        
        return null;
    }

    /**
     * Get fallback range from WooCommerce Target Audience attribute dynamically.
     */
    public static function get_audience_fallback_range( int $product_id ): ?array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return null;
        }
        
        $attributes = $product->get_attributes();
        foreach ( $attributes as $attribute_name => $attribute ) {
            if ( ! $attribute->is_taxonomy() ) {
                continue;
            }
            
            $taxonomy = $attribute->get_taxonomy_object();
            $label = $taxonomy ? $taxonomy->attribute_label : '';
            $slug = $taxonomy ? $taxonomy->attribute_name : $attribute_name;
            
            if ( 
                strpos( $label, 'مخاطب' ) !== false || 
                strpos( $label, 'سن' ) !== false || 
                strpos( $slug, 'audience' ) !== false || 
                strpos( $slug, 'target' ) !== false || 
                strpos( $slug, 'age' ) !== false 
            ) {
                $terms = wp_get_post_terms( $product_id, $slug, array( 'fields' => 'all' ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $range = self::parse_range_from_term( $term->name, $term->description );
                        if ( $range ) {
                            return $range;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Render a beautiful visual range formatting modal helper.
     */
    public static function render_range_format_modal(): void {
        ?>
        <div id="brz-range-format-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:999999; justify-content:center; align-items:center; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);">
            <div style="background:#fff; border-radius:14px; width:480px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.2); border:1px solid rgba(255,255,255,0.2); position:relative; box-sizing: border-box; text-align: right;">
                <h4 style="margin:0 0 18px 0; font-size:15px; font-weight:600; color:#1e293b; border-bottom:1px solid #e2e8f0; padding-bottom:12px; display:flex; align-items:center; gap:8px;">⚙️ تنظیمات نمایش بازه عددی (کمینه/بیشینه)</h4>
                
                <!-- State 1: Both values -->
                <div style="margin-bottom:18px;">
                    <div style="font-weight:600; font-size:12.5px; color:#475569; margin-bottom:8px;">۱. قالب نمایش وقتی هر دو مقدار وارد شده‌اند:</div>
                    <div style="display:flex; gap:8px; align-items:center; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; direction: rtl;">
                        <input type="text" id="brz-rf-both-before" placeholder="پیشوند (قبل)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                        <span style="font-size:11px; color:#64748b; font-weight:600; background:#e2e8f0; padding:2px 6px; border-radius:4px;">min</span>
                        <input type="text" id="brz-rf-both-between" placeholder="بین" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                        <span style="font-size:11px; color:#64748b; font-weight:600; background:#e2e8f0; padding:2px 6px; border-radius:4px;">max</span>
                        <input type="text" id="brz-rf-both-after" placeholder="پسوند (بعد)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                    </div>
                </div>

                <!-- State 2: Min value only -->
                <div style="margin-bottom:18px;">
                    <div style="font-weight:600; font-size:12.5px; color:#475569; margin-bottom:8px;">۲. قالب نمایش وقتی فقط مقدار حداقل وارد شده:</div>
                    <div style="display:flex; gap:8px; align-items:center; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; direction: rtl;">
                        <input type="text" id="brz-rf-min-before" placeholder="پیشوند (قبل)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                        <span style="font-size:11px; color:#64748b; font-weight:600; background:#e2e8f0; padding:2px 6px; border-radius:4px;">min</span>
                        <input type="text" id="brz-rf-min-after" placeholder="پسوند (بعد)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                    </div>
                </div>

                <!-- State 3: Max value only -->
                <div style="margin-bottom:20px;">
                    <div style="font-weight:600; font-size:12.5px; color:#475569; margin-bottom:8px;">۳. قالب نمایش وقتی فقط مقدار حداکثر وارد شده:</div>
                    <div style="display:flex; gap:8px; align-items:center; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #e2e8f0; direction: rtl;">
                        <input type="text" id="brz-rf-max-before" placeholder="پیشوند (قبل)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                        <span style="font-size:11px; color:#64748b; font-weight:600; background:#e2e8f0; padding:2px 6px; border-radius:4px;">max</span>
                        <input type="text" id="brz-rf-max-after" placeholder="پسوند (بعد)" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; text-align:center;" />
                    </div>
                </div>

                <!-- Footer Actions -->
                <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:15px;">
                    <button type="button" id="brz-rf-cancel" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; border-radius:8px; cursor:pointer; font-size:12.5px; color:#64748b; font-weight:500;">انصراف</button>
                    <button type="button" id="brz-rf-save" style="padding:8px 16px; border:none; background:#1a73e8; color:#fff; border-radius:8px; cursor:pointer; font-size:12.5px; font-weight:600; box-shadow:0 2px 6px rgba(26,115,232,0.2);">تایید و ثبت</button>
                </div>
            </div>
        </div>
        <?php
    }





    /**
     * Get all available specifications layout items (specs, weight, dimensions, attributes).
     */
    public static function get_all_available_layout_items(): array {
        $items = array();

        $fields = self::get_fields();
        if ( is_array( $fields ) ) {
            foreach ( $fields as $f ) {
                $items[ $f['key'] ] = array(
                    'label' => $f['label'] ? $f['label'] : $f['key'],
                    'type'  => 'مشخصه بایروز (' . $f['type'] . ')'
                );
            }
        }

        // Get WC Core Specs configs if available
        $weight_label = 'وزن محصول';
        $dim_label = 'ابعاد محصول';
        $len_label = 'طول محصول';
        $wid_label = 'عرض محصول';
        $hei_label = 'ارتفاع محصول';
        $gtin_label = 'بارکد (GTIN)';
        $dim_format = 'unified';

        if ( class_exists( 'BRZ_WC_Core_Specs' ) && BRZ_Modules::is_enabled( 'wc_core_specs' ) ) {
            $core_settings = BRZ_WC_Core_Specs::get_settings();
            $weight_label = ! empty( $core_settings['weight']['label'] ) ? $core_settings['weight']['label'] : $weight_label;
            $dim_label = ! empty( $core_settings['dimensions']['label'] ) ? $core_settings['dimensions']['label'] : $dim_label;
            $len_label = ! empty( $core_settings['dimensions']['label_length'] ) ? $core_settings['dimensions']['label_length'] : $len_label;
            $wid_label = ! empty( $core_settings['dimensions']['label_width'] ) ? $core_settings['dimensions']['label_width'] : $wid_label;
            $hei_label = ! empty( $core_settings['dimensions']['label_height'] ) ? $core_settings['dimensions']['label_height'] : $hei_label;
            $gtin_label = ! empty( $core_settings['gtin']['label'] ) ? $core_settings['gtin']['label'] : $gtin_label;
            $dim_format = ! empty( $core_settings['dimensions']['format'] ) ? $core_settings['dimensions']['format'] : 'unified';
        }

        $items['weight'] = array(
            'label' => $weight_label,
            'type'  => 'ویژگی فیزیکی ووکامرس'
        );

        if ( 'separate' === $dim_format ) {
            $items['dimensions_length'] = array(
                'label' => $len_label,
                'type'  => 'ویژگی فیزیکی ووکامرس'
            );
            $items['dimensions_width'] = array(
                'label' => $wid_label,
                'type'  => 'ویژگی فیزیکی ووکامرس'
            );
            $items['dimensions_height'] = array(
                'label' => $hei_label,
                'type'  => 'ویژگی فیزیکی ووکامرس'
            );
        } else {
            $items['dimensions'] = array(
                'label' => $dim_label,
                'type'  => 'ویژگی فیزیکی ووکامرس'
            );
        }

        $items['gtin'] = array(
            'label' => $gtin_label,
            'type'  => 'ویژگی فیزیکی ووکامرس'
        );

        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $taxonomies = wc_get_attribute_taxonomies();
            if ( is_array( $taxonomies ) ) {
                foreach ( $taxonomies as $tax ) {
                    $slug = wc_attribute_taxonomy_name( $tax->attribute_name );
                    $items[ $slug ] = array(
                        'label' => $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name,
                        'type'  => 'ویژگی ووکامرس (Attribute)'
                    );
                }
            }
        }

        return $items;
    }

    /**
     * Retrieve the unified specs layout templates.
     */
    public static function get_unified_layout(): array {
        $layout = get_option( 'brz_unified_specs_layout', null );
        if ( is_array( $layout ) ) {
            if ( ! isset( $layout['global'] ) || ! is_array( $layout['global'] ) ) {
                $layout['global'] = array();
            }
            if ( ! isset( $layout['categories'] ) || ! is_array( $layout['categories'] ) ) {
                $layout['categories'] = array();
            }
            return $layout;
        }

        // Default layout: specs fields, weight, dimensions, then wc attributes
        $global = array();
        $fields = self::get_fields();
        foreach ( $fields as $f ) {
            $global[] = $f['key'];
        }

        $global[] = 'weight';

        $dim_format = 'unified';
        if ( class_exists( 'BRZ_WC_Core_Specs' ) && BRZ_Modules::is_enabled( 'wc_core_specs' ) ) {
            $core_settings = BRZ_WC_Core_Specs::get_settings();
            $dim_format = ! empty( $core_settings['dimensions']['format'] ) ? $core_settings['dimensions']['format'] : 'unified';
        }
        if ( 'separate' === $dim_format ) {
            $global[] = 'dimensions_length';
            $global[] = 'dimensions_width';
            $global[] = 'dimensions_height';
        } else {
            $global[] = 'dimensions';
        }
        $global[] = 'gtin';

        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            $taxonomies = wc_get_attribute_taxonomies();
            foreach ( $taxonomies as $tax ) {
                $global[] = wc_attribute_taxonomy_name( $tax->attribute_name );
            }
        }

        return array(
            'global'     => array_values( array_unique( $global ) ),
            'categories' => array(),
        );
    }

    /**
     * AJAX handler to save layout configurations.
     */
    public static function ajax_save_unified_layout(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }

        if ( ! check_ajax_referer( 'brz_product_specs_save_layout_nonce', '_wpnonce', false ) ) {
            wp_send_json_error( array( 'message' => 'نشست معتبر نیست.' ), 403 );
        }

        $global = isset( $_POST['global'] ) && is_array( $_POST['global'] ) ? array_map( 'sanitize_text_field', $_POST['global'] ) : array();
        $raw_categories = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? $_POST['categories'] : array();
        
        $categories = array();
        foreach ( $raw_categories as $cat_id => $slugs ) {
            $cat_id = strval( intval( $cat_id ) );
            if ( is_array( $slugs ) ) {
                $categories[ $cat_id ] = array_map( 'sanitize_text_field', $slugs );
            }
        }

        $layout = array(
            'global'     => $global,
            'categories' => $categories
        );

        update_option( 'brz_unified_specs_layout', $layout, false );

        wp_send_json_success( array( 'message' => 'چیدمان یکپارچه با موفقیت ذخیره شد.' ) );
    }

    /**
     * Unified specifications tab rendering callback.
     * Merges custom specs, WooCommerce attributes, weight, and dimensions into a single sorted list.
     */
    public static function render_unified_product_specs( $product = null ): void {
        if ( ! is_object( $product ) ) {
            global $product;
        }
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return;
        }

        $specs_values = array();
        $normalized_labels = array();

        // 1. Gather Buyruz custom specs
        $fields = self::get_fields();
        if ( ! empty( $fields ) ) {
            $fields_by_key = array();
            foreach ( $fields as $field ) {
                $fields_by_key[ $field['key'] ] = $field;
            }
            $all_keys = array_keys( $fields_by_key );
            $processed_keys = array();

            foreach ( $fields as $field ) {
                $key = $field['key'];
                if ( in_array( $key, $processed_keys, true ) ) {
                    continue;
                }

                $max_key = self::get_matching_max_key( $key, $all_keys );

                if ( $max_key ) {
                    $max_field = $fields_by_key[ $max_key ];
                    $processed_keys[] = $key;
                    $processed_keys[] = $max_key;

                    $min_val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                    $max_val = get_post_meta( $product->get_id(), '_brz_spec_' . $max_key, true );

                    if ( $min_val === '' && $max_val === '' ) {
                        continue;
                    }

                    $combined_label = trim( str_replace( array( 'حداقل', 'حداکثر' ), '', $field['label'] ) );
                    if ( empty( $combined_label ) ) {
                        $combined_label = $field['label'];
                    }

                    if ( $min_val !== '' && $max_val !== '' ) {
                        if ( $min_val === $max_val ) {
                            $value_html = $field['prefix'] . self::to_persian_digits( $min_val ) . $field['suffix'];
                        } else {
                            $clean_prefix = trim( str_replace( array( 'بالای', 'بیشتر از', 'کمتر از', 'زیر' ), '', $field['prefix'] ) );
                            if ( ! empty( $clean_prefix ) && substr( $clean_prefix, -1 ) !== ' ' ) {
                                $clean_prefix .= ' ';
                            }
                            $value_html = $clean_prefix . self::to_persian_digits( $min_val ) . ' تا ' . self::to_persian_digits( $max_val ) . $max_field['suffix'];
                        }
                    } elseif ( $min_val !== '' ) {
                        $value_html = $field['prefix'] . self::to_persian_digits( $min_val ) . $field['suffix'];
                    } else {
                        $value_html = $max_field['prefix'] . self::to_persian_digits( $max_val ) . $max_field['suffix'];
                    }

                    if ( ! empty( $value_html ) ) {
                        $specs_values[ $key ] = array(
                            'label' => $combined_label,
                            'value' => $value_html
                        );
                        $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $combined_label );
                        $normalized_labels[ $norm ] = $key;
                    }
                    continue;
                }

                $processed_keys[] = $key;
                $type       = $field['type'];
                $label      = $field['label'];
                $prefix     = $field['prefix'];
                $suffix     = $field['suffix'];
                $value_html = '';

                if ( 'boolean' === $type ) {
                    $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                    if ( $val === '' ) {
                        continue;
                    }
                    $is_bakala = self::is_bakala_theme();
                    if ( $val === '1' ) {
                        $value_html = $is_bakala ? '<i class="icon icon-green-mark"></i>' : 'بله';
                    } else {
                        $value_html = $is_bakala ? '<i class="icon icon-red-close"></i>' : 'خیر';
                    }
                } elseif ( 'range' === $type ) {
                    $keys = self::get_range_meta_keys( $key );
                    $min  = get_post_meta( $product->get_id(), $keys[0], true );
                    $max  = get_post_meta( $product->get_id(), $keys[1], true );

                    if ( $min === '' && $max === '' ) {
                        continue;
                    }

                    $raw_options = str_replace( '؛', ';', (string) $field['options'] );
                    $formats     = array_map( 'trim', explode( ';', $raw_options ) );
                    
                    $def_both = '{min} تا {max}' . ( $suffix ? ' ' . $suffix : '' );
                    $def_min  = ( $prefix ? $prefix . ' ' : '' ) . '{min}' . ( $suffix ? ' ' . $suffix : '' );
                    $def_max  = 'تا {max}' . ( $suffix ? ' ' . $suffix : '' );
                    
                    $fmt_both = isset( $formats[0] ) && '' !== $formats[0] ? $formats[0] : $def_both;
                    $fmt_min  = isset( $formats[1] ) && '' !== $formats[1] ? $formats[1] : $def_min;
                    $fmt_max  = isset( $formats[2] ) && '' !== $formats[2] ? $formats[2] : $def_max;

                    if ( $min !== '' && $max !== '' ) {
                        if ( $min === $max ) {
                            $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
                        } else {
                            $range_str = str_replace(
                                array( '{min}', '{max}' ),
                                array( self::to_persian_digits( $min ), self::to_persian_digits( $max ) ),
                                $fmt_both
                            );
                        }
                    } elseif ( $min !== '' ) {
                        $range_str = str_replace( '{min}', self::to_persian_digits( $min ), $fmt_min );
                    } else {
                        $range_str = str_replace( '{max}', self::to_persian_digits( $max ), $fmt_max );
                    }

                    $value_html = $range_str;
                } elseif ( 'array' === $type ) {
                    $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                    if ( empty( $val ) ) {
                        continue;
                    }
                    $decoded = json_decode( $val, true );
                    if ( ! is_array( $decoded ) ) {
                        $decoded = maybe_unserialize( $val );
                    }
                    if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                        continue;
                    }
                    $persian_values = array_map( array( __CLASS__, 'to_persian_digits' ), $decoded );
                    $suffix_display = $suffix;
                    if ( ! empty( $suffix_display ) && ! in_array( substr( $suffix_display, 0, 1 ), array( ' ', '؛', ';', '<' ), true ) ) {
                        $suffix_display = ' ' . $suffix_display;
                    }
                    $value_html     = $prefix . implode( '، ', $persian_values ) . $suffix_display;
                } elseif ( 'integer' === $type || 'decimal' === $type ) {
                    $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                    if ( $val === '' ) {
                        continue;
                    }
                    $suffix_display = $suffix;
                    if ( ! empty( $suffix_display ) && ! in_array( substr( $suffix_display, 0, 1 ), array( ' ', '؛', ';', '<' ), true ) ) {
                        $suffix_display = ' ' . $suffix_display;
                    }
                    $value_html = $prefix . self::to_persian_digits( $val ) . $suffix_display;
                } elseif ( 'string' === $type || 'text' === $type ) {
                    $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                    if ( $val === '' ) {
                        continue;
                    }
                    $suffix_display = $suffix;
                    if ( ! empty( $suffix_display ) && ! in_array( substr( $suffix_display, 0, 1 ), array( ' ', '؛', ';', '<' ), true ) ) {
                        $suffix_display = ' ' . $suffix_display;
                    }
                    $value_html = $prefix . self::to_persian_digits( sanitize_text_field( $val ) ) . $suffix_display;
                }

                if ( ! empty( $value_html ) ) {
                    $specs_values[ $key ] = array(
                        'label' => $label,
                        'value' => $value_html
                    );
                    $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $label );
                    $normalized_labels[ $norm ] = $key;
                }
            }
        }

        // 2. Gather WooCommerce attributes
        $attributes = $product->get_attributes();
        if ( ! empty( $attributes ) ) {
            foreach ( $attributes as $slug => $attr ) {
                if ( ! $attr->get_visible() ) {
                    continue;
                }

                $label = wc_attribute_label( $slug, $product );
                $norm  = str_replace( array( ' ', '-', '_', '‌' ), '', $label );
                if ( isset( $normalized_labels[ $norm ] ) ) {
                    continue;
                }

                if ( $attr->is_taxonomy() ) {
                    $values = wc_get_product_terms( $product->get_id(), $slug, array( 'fields' => 'names' ) );
                } else {
                    $values = $attr->get_options();
                }

                $formatted_values = array();
                $is_bakala = self::is_bakala_theme();
                foreach ( $values as $val ) {
                    $val_clean = mb_strtolower( trim( $val ) );
                    if ( in_array( $val_clean, array( 'yes', 'بله', 'دارد' ), true ) ) {
                        $formatted_values[] = $is_bakala ? '<i class="icon icon-green-mark"></i>' : 'بله';
                    } elseif ( in_array( $val_clean, array( 'no', 'خیر', 'ندارد' ), true ) ) {
                        $formatted_values[] = $is_bakala ? '<i class="icon icon-red-close"></i>' : 'خیر';
                    } else {
                        $formatted_values[] = esc_html( $val );
                    }
                }
                $value_html = implode( '، ', $formatted_values );

                $value_html = trim( $value_html );
                if ( $value_html === '' ) {
                    continue;
                }

                $specs_values[ $slug ] = array(
                    'label' => $label,
                    'value' => $value_html
                );
                $normalized_labels[ $norm ] = $slug;
            }
        }

        // 3. Weight, dimensions, and GTIN
        $weight_enabled = 1;
        $weight_label = 'وزن';
        $weight_unit = 'default';
        
        $dim_enabled = 1;
        $dim_label = 'ابعاد';
        $dim_format = 'unified';
        $dim_label_length = 'طول';
        $dim_label_width = 'عرض';
        $dim_label_height = 'ارتفاع';

        $gtin_enabled = 1;
        $gtin_label = 'بارکد (GTIN)';
        $gtin_link_gs1 = 0;

        if ( class_exists( 'BRZ_WC_Core_Specs' ) && BRZ_Modules::is_enabled( 'wc_core_specs' ) ) {
            $core_settings = BRZ_WC_Core_Specs::get_settings();
            
            $weight_enabled = isset( $core_settings['weight']['enabled'] ) ? intval( $core_settings['weight']['enabled'] ) : 1;
            $weight_label = ! empty( $core_settings['weight']['label'] ) ? $core_settings['weight']['label'] : $weight_label;
            $weight_unit = ! empty( $core_settings['weight']['unit_override'] ) ? $core_settings['weight']['unit_override'] : 'default';

            $dim_enabled = isset( $core_settings['dimensions']['enabled'] ) ? intval( $core_settings['dimensions']['enabled'] ) : 1;
            $dim_label = ! empty( $core_settings['dimensions']['label'] ) ? $core_settings['dimensions']['label'] : $dim_label;
            $dim_format = ! empty( $core_settings['dimensions']['format'] ) ? $core_settings['dimensions']['format'] : 'unified';
            $dim_label_length = ! empty( $core_settings['dimensions']['label_length'] ) ? $core_settings['dimensions']['label_length'] : $dim_label_length;
            $dim_label_width = ! empty( $core_settings['dimensions']['label_width'] ) ? $core_settings['dimensions']['label_width'] : $dim_label_width;
            $dim_label_height = ! empty( $core_settings['dimensions']['label_height'] ) ? $core_settings['dimensions']['label_height'] : $dim_label_height;

            $gtin_enabled = isset( $core_settings['gtin']['enabled'] ) ? intval( $core_settings['gtin']['enabled'] ) : 1;
            $gtin_label = ! empty( $core_settings['gtin']['label'] ) ? $core_settings['gtin']['label'] : $gtin_label;
            $gtin_link_gs1 = isset( $core_settings['gtin']['link_gs1'] ) ? intval( $core_settings['gtin']['link_gs1'] ) : 0;
        }

        // 3.1. Gather Weight
        if ( $weight_enabled && $product->has_weight() ) {
            $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $weight_label );
            if ( ! isset( $normalized_labels[ $norm ] ) ) {
                $raw_weight = floatval( $product->get_weight() );
                $system_unit = get_option( 'woocommerce_weight_unit', 'kg' );
                $display_value = '';

                if ( 'g' === $weight_unit ) {
                    if ( 'kg' === $system_unit ) {
                        $display_value = self::to_persian_digits( $raw_weight * 1000 ) . ' گرم';
                    } else {
                        $display_value = self::to_persian_digits( $raw_weight ) . ' گرم';
                    }
                } elseif ( 'kg' === $weight_unit ) {
                    if ( 'g' === $system_unit ) {
                        $display_value = self::to_persian_digits( $raw_weight / 1000 ) . ' کیلوگرم';
                    } else {
                        $display_value = self::to_persian_digits( $raw_weight ) . ' کیلوگرم';
                    }
                } else {
                    $formatted = wc_format_weight( $product->get_weight() );
                    $formatted = str_replace( array( 'kg', 'g' ), array( 'کیلوگرم', 'گرم' ), $formatted );
                    $display_value = self::to_persian_digits( $formatted );
                }

                $specs_values['weight'] = array(
                    'label' => $weight_label,
                    'value' => $display_value
                );
                $normalized_labels[ $norm ] = 'weight';
            }
        }

        // 3.2. Gather Dimensions
        if ( $dim_enabled && $product->has_dimensions() ) {
            $unit = get_option( 'woocommerce_dimension_unit', 'cm' );
            $unit_translated = ( 'cm' === $unit ) ? 'سانتی‌متر' : ( ( 'm' === $unit ) ? 'متر' : ( ( 'mm' === $unit ) ? 'میلی‌متر' : $unit ) );

            if ( 'separate' === $dim_format ) {
                // Length
                if ( $product->get_length() ) {
                    $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $dim_label_length );
                    if ( ! isset( $normalized_labels[ $norm ] ) ) {
                        $specs_values['dimensions_length'] = array(
                            'label' => $dim_label_length,
                            'value' => self::to_persian_digits( $product->get_length() ) . ' ' . $unit_translated
                        );
                        $normalized_labels[ $norm ] = 'dimensions_length';
                    }
                }
                // Width
                if ( $product->get_width() ) {
                    $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $dim_label_width );
                    if ( ! isset( $normalized_labels[ $norm ] ) ) {
                        $specs_values['dimensions_width'] = array(
                            'label' => $dim_label_width,
                            'value' => self::to_persian_digits( $product->get_width() ) . ' ' . $unit_translated
                        );
                        $normalized_labels[ $norm ] = 'dimensions_width';
                    }
                }
                // Height
                if ( $product->get_height() ) {
                    $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $dim_label_height );
                    if ( ! isset( $normalized_labels[ $norm ] ) ) {
                        $specs_values['dimensions_height'] = array(
                            'label' => $dim_label_height,
                            'value' => self::to_persian_digits( $product->get_height() ) . ' ' . $unit_translated
                        );
                        $normalized_labels[ $norm ] = 'dimensions_height';
                    }
                }
            } else {
                $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $dim_label );
                if ( ! isset( $normalized_labels[ $norm ] ) ) {
                    $formatted = html_entity_decode( wc_format_dimensions( $product->get_dimensions( false ) ), ENT_QUOTES, 'UTF-8' );
                    $formatted = str_replace( array( 'x', 'cm', 'mm', 'm' ), array( '×', 'سانتی‌متر', 'میلی‌متر', 'متر' ), $formatted );
                    $specs_values['dimensions'] = array(
                        'label' => $dim_label,
                        'value' => self::to_persian_digits( $formatted )
                    );
                    $normalized_labels[ $norm ] = 'dimensions';
                }
            }
        }

        // 3.3. Gather GTIN
        if ( $gtin_enabled && class_exists( 'BRZ_WC_Core_Specs' ) ) {
            $gtin_val = BRZ_WC_Core_Specs::get_product_gtin( $product );
            if ( ! empty( $gtin_val ) ) {
                $norm = str_replace( array( ' ', '-', '_', '‌' ), '', $gtin_label );
                if ( ! isset( $normalized_labels[ $norm ] ) ) {
                    $display_html = esc_html( $gtin_val );

                    if ( $gtin_link_gs1 ) {
                        $display_html = sprintf(
                            '<a href="https://gepir.gs1.org/index.php/search-by-gtin?gtin=%s" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color:inherit;" title="استعلام اصالت در سایت جهانی GS1">%s</a>',
                            esc_attr( $gtin_val ),
                            $display_html
                        );
                    }

                    $specs_values['gtin'] = array(
                        'label' => $gtin_label,
                        'value' => $display_html
                    );
                    $normalized_labels[ $norm ] = 'gtin';
                }
            }
        }

        // 4. Resolve active layout
        $layout_config = self::get_unified_layout();
        $layout_order  = $layout_config['global'];

        $cats = get_the_terms( $product->get_id(), 'product_cat' );
        if ( is_array( $cats ) ) {
            foreach ( $cats as $cat ) {
                $cat_id = strval( $cat->term_id );
                if ( isset( $layout_config['categories'][ $cat_id ] ) && ! empty( $layout_config['categories'][ $cat_id ] ) ) {
                    $layout_order = $layout_config['categories'][ $cat_id ];
                    break;
                }
            }
        }

        // 4.5. Gather custom taxonomies specified in the layout order but not in standard attributes (e.g. pwb-brand, yith_product_brand)
        if ( ! empty( $layout_order ) ) {
            foreach ( $layout_order as $slug ) {
                if ( ! isset( $specs_values[ $slug ] ) && taxonomy_exists( $slug ) ) {
                    $tax_obj = get_taxonomy( $slug );
                    $label = $tax_obj ? $tax_obj->labels->singular_name : $slug;
                    $norm  = str_replace( array( ' ', '-', '_', '‌' ), '', $label );
                    if ( isset( $normalized_labels[ $norm ] ) ) {
                        continue;
                    }

                    $terms = get_the_terms( $product->get_id(), $slug );
                    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                        $term_names = wp_list_pluck( $terms, 'name' );
                        
                        $specs_values[ $slug ] = array(
                            'label' => $label,
                            'value' => implode( '، ', $term_names )
                        );
                        $normalized_labels[ $norm ] = $slug;
                    }
                }
            }
        }

        if ( empty( $specs_values ) ) {
            return;
        }

        // 5. Sort specifications
        $sorted_specs = array();
        foreach ( $layout_order as $slug ) {
            if ( isset( $specs_values[ $slug ] ) ) {
                $sorted_specs[ $slug ] = $specs_values[ $slug ];
                unset( $specs_values[ $slug ] );
            }
        }

        // Fail-safe: append any local attributes or leftover fields not registered in the schema at the bottom
        foreach ( $specs_values as $slug => $spec ) {
            $sorted_specs[ $slug ] = $spec;
        }

        if ( empty( $sorted_specs ) ) {
            return;
        }

        // 6. Output HTML
        if ( self::is_bakala_theme() ) {
            ?>
            <ul class="spec-list brz-custom-specs-list" style="margin-bottom:10px;">
                <?php foreach ( $sorted_specs as $slug => $spec ) : ?>
                    <li class="clearfix brz-spec-item-<?php echo esc_attr( $slug ); ?>">
                        <span class="technicalspecs-title"><?php echo esc_html( $spec['label'] ); ?></span>
                        <span class="technicalspecs-value"><span><?php echo wp_kses_post( $spec['value'] ); ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php
        } else {
            ?>
            <table class="woocommerce-product-attributes shop_attributes brz-custom-specs-table" style="margin-bottom: 20px; width: 100%; border-collapse: collapse;">
                <tbody>
                    <?php foreach ( $sorted_specs as $slug => $spec ) : ?>
                        <tr class="woocommerce-product-attributes-item brz-spec-item-<?php echo esc_attr( $slug ); ?>">
                            <th class="woocommerce-product-attributes-item__label" style="text-align: right; font-weight: bold; padding: 8px 15px; width: 220px; border-bottom: 1px solid #eee;"><?php echo esc_html( $spec['label'] ); ?></th>
                            <td class="woocommerce-product-attributes-item__value" style="padding: 8px 15px; border-bottom: 1px solid #eee;"><?php echo wp_kses_post( $spec['value'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
    }

    /**
     * Recalculate filter ages when terms for target-audience taxonomy change.
     */
    public static function on_set_object_terms( int $object_id, $terms, array $tt_ids, string $taxonomy ): void {
        if ( 'pa_target-audience' === $taxonomy ) {
            self::recalculate_product_filter_ages( $object_id );
        }
    }

    /**
     * Recalculates filter min/max ages for a product using target-audience attributes if manual inputs are empty.
     */
    public static function recalculate_product_filter_ages( int $post_id ): void {
        // Avoid infinite loop during recursive saving/updating if hook is triggered multiple times
        static $running = array();
        if ( isset( $running[ $post_id ] ) ) {
            return;
        }
        $running[ $post_id ] = true;

        // 1. Get manual values
        $manual_min = get_post_meta( $post_id, '_brz_spec_manual_min_age', true );
        $manual_max = get_post_meta( $post_id, '_brz_spec_manual_max_age', true );

        $has_manual_min = ( $manual_min !== '' );
        $has_manual_max = ( $manual_max !== '' );

        if ( $has_manual_min && $has_manual_max ) {
            // Both are set manually, so just update filters and return
            update_post_meta( $post_id, '_brz_spec_filter_min_age', intval( $manual_min ) );
            update_post_meta( $post_id, '_brz_spec_filter_max_age', intval( $manual_max ) );
            unset( $running[ $post_id ] );
            return;
        }

        // 2. Fetch selected target-audience terms for this product
        $terms = get_the_terms( $post_id, 'pa_target-audience' );

        $min_candidates = array();
        $max_candidates = array();

        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            foreach ( $terms as $term ) {
                $slug = $term->slug;
                
                // Map slug to age ranges based on matrix
                $min_val = null;
                $max_val = null;

                switch ( $slug ) {
                    case 'toddler-up-to-3':
                        $min_val = 0;
                        $max_val = 3;
                        break;
                    case 'preschool-3-to-5':
                        $min_val = 3;
                        $max_val = 5;
                        break;
                    case 'kids-5-to-8':
                        $min_val = 5;
                        $max_val = 8;
                        break;
                    case 'tweens-8-to-12':
                        $min_val = 8;
                        $max_val = 12;
                        break;
                    case 'teens-12-to-14':
                        $min_val = 12;
                        $max_val = 14;
                        break;
                    case 'adults-14-plus':
                        $min_val = 14;
                        $max_val = 99;
                        break;
                    case 'family-friendly':
                        $min_val = 8;
                        $max_val = 99;
                        break;
                }

                if ( null !== $min_val ) {
                    $min_candidates[] = $min_val;
                }
                if ( null !== $max_val ) {
                    $max_candidates[] = $max_val;
                }
            }
        }

        // Apply Multi-select Rule: lowest min and highest max
        $audience_min = ! empty( $min_candidates ) ? min( $min_candidates ) : null;
        $audience_max = ! empty( $max_candidates ) ? max( $max_candidates ) : null;

        // Apply fallback rules
        if ( $has_manual_min ) {
            update_post_meta( $post_id, '_brz_spec_filter_min_age', intval( $manual_min ) );
        } else {
            if ( null !== $audience_min ) {
                update_post_meta( $post_id, '_brz_spec_filter_min_age', intval( $audience_min ) );
            } else {
                delete_post_meta( $post_id, '_brz_spec_filter_min_age' );
            }
        }

        if ( $has_manual_max ) {
            update_post_meta( $post_id, '_brz_spec_filter_max_age', intval( $manual_max ) );
        } else {
            if ( null !== $audience_max ) {
                update_post_meta( $post_id, '_brz_spec_filter_max_age', intval( $audience_max ) );
            } else {
                delete_post_meta( $post_id, '_brz_spec_filter_max_age' );
            }
        }

        unset( $running[ $post_id ] );
    }
}

