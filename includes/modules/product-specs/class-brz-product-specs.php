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
        } else {
            // Frontend: Inject custom attributes before default WC attributes.
            add_action( 'woocommerce_product_additional_information', array( __CLASS__, 'render_custom_specs' ), 9 );
        }

        // Expose specs field in REST API.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_fields' ) );

        // Monitor meta updates from any source (Bridge, REST API, WP Admin) to auto-register new options.
        add_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10, 4 );
        add_action( 'deleted_post_meta', array( __CLASS__, 'monitor_meta_deletions' ), 10, 4 );
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
    public static function sanitize_decimal( $value ) {
        // Return float if it has decimal, integer otherwise.
        $float_val = floatval( $value );
        $int_val   = intval( $value );
        return ( $float_val == $int_val ) ? $int_val : $float_val;
    }

    /**
     * Intercept postmeta additions and updates to automatically register new options for array type fields.
     */
    public static function monitor_meta_deletions( $meta_ids, $object_id, $meta_key, $meta_values ): void {
        if ( '_brz_spec_manual_min_age' === $meta_key ) {
            delete_post_meta( $object_id, '_brz_spec_filter_min_age' );
        } elseif ( '_brz_spec_manual_max_age' === $meta_key ) {
            delete_post_meta( $object_id, '_brz_spec_filter_max_age' );
        }
    }

    public static function monitor_meta_changes( $meta_id, $object_id, $meta_key, $meta_value ): void {
        if ( strpos( $meta_key, '_brz_spec_' ) !== 0 ) {
            return;
        }

        // Avoid infinite loops if we are updating options.
        remove_action( 'added_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10 );
        remove_action( 'updated_post_meta', array( __CLASS__, 'monitor_meta_changes' ), 10 );

        // Auto-generate filter age keys in the background
        if ( '_brz_spec_manual_min_age' === $meta_key ) {
            update_post_meta( $object_id, '_brz_spec_filter_min_age', intval( $meta_value ) );
        } elseif ( '_brz_spec_manual_max_age' === $meta_key ) {
            update_post_meta( $object_id, '_brz_spec_filter_max_age', intval( $meta_value ) );
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
                register_post_meta(
                    'product',
                    '_brz_spec_' . $key . '_min',
                    array(
                        'type'              => 'integer',
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                    )
                );
                register_post_meta(
                    'product',
                    '_brz_spec_' . $key . '_max',
                    array(
                        'type'              => 'integer',
                        'single'            => true,
                        'show_in_rest'      => true,
                        'sanitize_callback' => array( __CLASS__, 'sanitize_integer' ),
                    )
                );
            } else {
                $meta_type   = 'string';
                $sanitize_cb = 'sanitize_text_field';

                if ( 'boolean' === $type ) {
                    $meta_type   = 'boolean';
                    $sanitize_cb = 'rest_sanitize_boolean';
                } elseif ( 'number' === $type ) {
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
                $min  = get_post_meta( $post_id, '_brz_spec_' . $key . '_min', true );
                $max  = get_post_meta( $post_id, '_brz_spec_' . $key . '_max', true );
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
            } else {
                $val = get_post_meta( $post_id, '_brz_spec_' . $key, true );
                if ( $val !== '' ) {
                    $data[ $key ] = self::sanitize_decimal( $val );
                } else {
                    $data[ $key ] = null;
                }
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
                if ( is_array( $val ) ) {
                    if ( isset( $val['min'] ) ) {
                        if ( $val['min'] === null || $val['min'] === '' ) {
                            delete_post_meta( $post_id, '_brz_spec_' . $key . '_min' );
                        } else {
                            update_post_meta( $post_id, '_brz_spec_' . $key . '_min', intval( $val['min'] ) );
                        }
                    }
                    if ( isset( $val['max'] ) ) {
                        if ( $val['max'] === null || $val['max'] === '' ) {
                            delete_post_meta( $post_id, '_brz_spec_' . $key . '_max' );
                        } else {
                            update_post_meta( $post_id, '_brz_spec_' . $key . '_max', intval( $val['max'] ) );
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
            } else {
                if ( $val === null || $val === '' ) {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                } else {
                    update_post_meta( $post_id, '_brz_spec_' . $key, self::sanitize_decimal( $val ) );
                }
            }
        }
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
                $min_val = get_post_meta( $post->ID, '_brz_spec_' . $key . '_min', true );
                $max_val = get_post_meta( $post->ID, '_brz_spec_' . $key . '_max', true );
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
                        $min_val = get_post_meta( $post->ID, '_brz_spec_' . $key . '_min', true );
                        $max_val = get_post_meta( $post->ID, '_brz_spec_' . $key . '_max', true );
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
                                <?php elseif ( 'number' === $type ) : ?>
                                    <input type="number" step="any" class="brz-number-input" name="brz_spec[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $saved_val ); ?>" />
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

                    $row.fadeOut(200, function() {
                        $row.find('.brz-spec-is-active-input').val('0');
                        
                        $row.find('input[type="number"], input[type="text"]').val('');
                        $row.find('input[type="checkbox"]').prop('checked', false);

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
                    delete_post_meta( $post_id, '_brz_spec_' . $key . '_min' );
                    delete_post_meta( $post_id, '_brz_spec_' . $key . '_max' );
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
            } elseif ( 'number' === $type ) {
                $raw_specs = isset( $_POST['brz_spec'] ) && is_array( $_POST['brz_spec'] ) ? $_POST['brz_spec'] : array();
                if ( isset( $raw_specs[ $key ] ) && '' !== $raw_specs[ $key ] ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, self::sanitize_decimal( $raw_specs[ $key ] ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            } elseif ( 'range' === $type ) {
                $raw_ranges = isset( $_POST['brz_spec_range'] ) && is_array( $_POST['brz_spec_range'] ) ? $_POST['brz_spec_range'] : array();
                if ( isset( $raw_ranges[ $key ] ) ) {
                    $min = $raw_ranges[ $key ]['min'];
                    $max = $raw_ranges[ $key ]['max'];

                    if ( '' !== $min ) {
                        update_post_meta( $post_id, '_brz_spec_' . $key . '_min', intval( $min ) );
                    } else {
                        delete_post_meta( $post_id, '_brz_spec_' . $key . '_min' );
                    }

                    if ( '' !== $max ) {
                        update_post_meta( $post_id, '_brz_spec_' . $key . '_max', intval( $max ) );
                    } else {
                        delete_post_meta( $post_id, '_brz_spec_' . $key . '_max' );
                    }
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key . '_min' );
                    delete_post_meta( $post_id, '_brz_spec_' . $key . '_max' );
                }
            } elseif ( 'array' === $type ) {
                $raw_arrays = isset( $_POST['brz_spec_array'] ) && is_array( $_POST['brz_spec_array'] ) ? $_POST['brz_spec_array'] : array();
                $val        = isset( $raw_arrays[ $key ] ) && is_array( $raw_arrays[ $key ] ) ? $raw_arrays[ $key ] : array();
                if ( ! empty( $val ) ) {
                    update_post_meta( $post_id, '_brz_spec_' . $key, wp_json_encode( array_map( 'sanitize_text_field', $val ) ) );
                } else {
                    delete_post_meta( $post_id, '_brz_spec_' . $key );
                }
            }
        }
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
            .brz-spec-delete-btn {
                background: none;
                border: none;
                color: var(--md-error, #d32f2f);
                cursor: pointer;
                font-size: 16px;
                padding: 4px;
                border-radius: 4px;
                transition: background 0.15s;
            }
            .brz-spec-delete-btn:hover {
                background: rgba(211,47,47,.08);
            }
        </style>
        <div class="brz-single-column" dir="rtl">
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
                                    <th style="width: 15%;">گزینه‌ها (برای نوع آرایه‌ای)</th>
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
                                                    <option value="number" <?php selected( $f['type'], 'number' ); ?>>عدد تکی</option>
                                                    <option value="range" <?php selected( $f['type'], 'range' ); ?>>بازه عددی (کمینه/بیشینه)</option>
                                                    <option value="array" <?php selected( $f['type'], 'array' ); ?>>آرایه انتخابی (چند گزینه‌ای)</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="brz-spec-prefix" value="<?php echo esc_attr( $f['prefix'] ); ?>" placeholder="پیشوند" maxlength="100" />
                                            </td>
                                            <td>
                                                <input type="text" class="brz-spec-suffix" value="<?php echo esc_attr( $f['suffix'] ); ?>" placeholder="پسوند" maxlength="100" />
                                            </td>
                                            <td>
                                                <input type="text" class="brz-spec-options" value="<?php echo esc_attr( $f['options'] ); ?>" <?php echo ( $f['type'] === 'array' ) ? '' : 'disabled style="background:#f2f2f2;"'; ?> placeholder="گزینه‌ها با کاما جدا شوند" />
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

                $tbody.on('change', '.brz-spec-type', function() {
                    var $row = $(this).closest('tr');
                    var val  = $(this).val();
                    var $opt = $row.find('.brz-spec-options');
                    if (val === 'array') {
                        $opt.prop('disabled', false).css('background', '').focus();
                    } else {
                        $opt.prop('disabled', true).css('background', '#f2f2f2').val('');
                    }
                });

                $('#brz-spec-add-btn').on('click', function() {
                    var rowHtml = '<tr class="brz-spec-row is-new">' +
                        '<td><input type="text" class="brz-spec-key" placeholder="شناسه (مانند: age_range)" /></td>' +
                        '<td><input type="text" class="brz-spec-label" placeholder="عنوان فارسی" /></td>' +
                        '<td><select class="brz-spec-type">' +
                            '<option value="boolean">ساده (بله/خیر)</option>' +
                            '<option value="number">عدد تکی</option>' +
                            '<option value="range">بازه عددی (کمینه/بیشینه)</option>' +
                            '<option value="array">آرایه انتخابی (چند گزینه‌ای)</option>' +
                        '</select></td>' +
                        '<td><input type="text" class="brz-spec-prefix" placeholder="پیشوند" /></td>' +
                        '<td><input type="text" class="brz-spec-suffix" placeholder="پسوند" /></td>' +
                        '<td><input type="text" class="brz-spec-options brz-spec-options-input" placeholder="مثلاً: 1, 2, 3" disabled style="background:#f2f2f2;" /></td>' +
                        '<td style="text-align: center;"><button type="button" class="brz-spec-delete-btn" title="حذف فیلد">✕</button></td>' +
                        '</tr>';
                    $tbody.append(rowHtml);
                });

                $tbody.on('click', '.brz-spec-delete-btn', function() {
                    var $row = $(this).closest('tr');
                    if ($row.hasClass('is-new')) {
                        $row.remove();
                    } else {
                        if (confirm('آیا از حذف این فیلد مطمئن هستید؟ دیتای این مشخصه در صفحات محصولات پنهان می‌شود ولی متادیتاهای ذخیره شده قبلی در دیتابیس باقی می‌مانند.')) {
                            $row.remove();
                        }
                    }
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

            $allowed_types = array( 'boolean', 'number', 'range', 'array' );
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

        foreach ( $fields as $field ) {
            $key        = $field['key'];
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
                $min  = get_post_meta( $product->get_id(), '_brz_spec_' . $key . '_min', true );
                $max  = get_post_meta( $product->get_id(), '_brz_spec_' . $key . '_max', true );

                if ( $min === '' && $max === '' ) {
                    continue;
                }

                if ( $min !== '' && $max !== '' ) {
                    if ( $min === $max ) {
                        $value_html = $prefix . self::to_persian_digits( $min ) . $suffix;
                    } else {
                        $value_html = $prefix . self::to_persian_digits( $min ) . ' تا ' . self::to_persian_digits( $max ) . $suffix;
                    }
                } elseif ( $min !== '' ) {
                    $value_html = $prefix . 'بالای ' . self::to_persian_digits( $min ) . $suffix;
                } else {
                    $value_html = $prefix . 'زیر ' . self::to_persian_digits( $max ) . $suffix;
                }
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
            } elseif ( 'number' === $type ) {
                $val = get_post_meta( $product->get_id(), '_brz_spec_' . $key, true );
                if ( $val === '' ) {
                    continue;
                }
                $value_html = $prefix . self::to_persian_digits( $val ) . $suffix;
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
}
