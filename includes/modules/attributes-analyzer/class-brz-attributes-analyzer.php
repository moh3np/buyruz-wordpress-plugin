<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

/**
 * Buyruz Attributes Analyzer module.
 *
 * Analyzes global WooCommerce taxonomies, custom local product attributes, and Buyruz product specifications,
 * calculating usage stats per option to assist in database cleanup and AI-driven decision making.
 */
class BRZ_Attributes_Analyzer {
    const DOWNLOAD_ACTION = 'brz_download_attributes_stats';
    const MODULE_SLUG     = 'attributes_analyzer';

    /**
     * Bootstrap the module.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( __CLASS__, 'handle_download_stats' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'buyruz-attributes-stats', array( __CLASS__, 'cli_attributes_stats' ) );
        }
    }

    /**
     * Register REST API routes.
     */
    public static function register_rest_routes() {
        register_rest_route(
            'buyruz/v1',
            '/attributes-stats',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_get_stats' ),
                    'permission_callback' => array( __CLASS__, 'check_permissions' ),
                ),
            )
        );
    }

    /**
     * Check API request permissions.
     */
    public static function check_permissions( WP_REST_Request $request ) {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Try checking local_api_key from Smart Linker settings
        if ( class_exists( 'BRZ_Smart_Linker' ) ) {
            $settings = BRZ_Smart_Linker::get_settings();
            $local_key = isset( $settings['local_api_key'] ) ? $settings['local_api_key'] : '';
            if ( ! empty( $local_key ) ) {
                $provided = $request->get_param( 'api_key' );
                if ( empty( $provided ) ) {
                    $provided = $request->get_header( 'X-BRZ-API-Key' );
                }
                if ( ! empty( $provided ) && hash_equals( $local_key, (string) $provided ) ) {
                    return true;
                }
            }
        }

        // Try checking API key from BI Exporter settings
        if ( class_exists( 'BRZ_BI_Exporter' ) ) {
            $bi_settings = get_option( 'brz_bi_exporter', array() );
            $bi_key = isset( $bi_settings['api_key'] ) ? $bi_settings['api_key'] : '';
            if ( ! empty( $bi_key ) ) {
                $provided = $request->get_param( 'api_key' );
                if ( empty( $provided ) ) {
                    $provided = $request->get_header( 'X-BRZ-API-Key' );
                    if ( empty( $provided ) ) {
                        $provided = $request->get_header( 'x-buyruz-key' );
                    }
                }
                if ( ! empty( $provided ) && hash_equals( $bi_key, (string) $provided ) ) {
                    return true;
                }
            }
        }

        return new WP_Error(
            'rest_forbidden',
            'Unauthorized. Requires manage_options permission or a valid api_key.',
            array( 'status' => 403 )
        );
    }

    /**
     * REST endpoint callback.
     */
    public static function rest_get_stats( WP_REST_Request $request ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'brz_wc_inactive', 'WooCommerce is not active.', array( 'status' => 400 ) );
        }

        $stats = self::generate_stats();
        return rest_ensure_response( $stats );
    }

    /**
     * Generate complete attributes and options stats.
     *
     * @return array
     */
    public static function generate_stats() {
        global $wpdb;

        $schema_enabled = array();
        if ( class_exists( 'BRZ_AI_Schema' ) ) {
            $schema_enabled = BRZ_AI_Schema::get_enabled_attributes();
        }

        $attribute_taxonomies = class_exists( 'WooCommerce' ) ? wc_get_attribute_taxonomies() : array();
        
        $stats = array(
            'metadata' => array(
                'woocommerce_global_attributes' => array(
                    'description'    => 'ویژگی‌های سراسری استاندارد ووکامرس که به عنوان تاکسونومی اختصاصی در سیستم وردپرس (با پیشوند pa_) تعریف شده‌اند.',
                    'storage_type'   => 'WordPress Taxonomies & Terms. Products linked via wp_term_relationships table.',
                    'cleanup_action' => 'حذف از بخش محصولات > ویژگی‌ها در پیشخوان مدیریت وردپرس.',
                ),
                'woocommerce_custom_local_attributes' => array(
                    'description'    => 'ویژگی‌های محلی تعریف شده مستقیم روی خود محصول. این ویژگی‌ها سراسری نیستند و در بخش ویژگی‌های ووکامرس ثبت نشده‌اند.',
                    'storage_type'   => 'Serialized array stored inside _product_attributes meta field on each product.',
                    'cleanup_action' => 'ویرایش متای محصول یا حذف دستی از تب ویژگی‌ها در صفحه ویرایش محصول.',
                ),
                'buyruz_product_specs' => array(
                    'description'    => 'مشخصات فنی اختصاصی بایروز که به صورت فیلدهای مستقل و داینامیک در سازنده مشخصات بایروز تعریف شده‌اند.',
                    'storage_type'   => 'Direct postmeta fields in wp_postmeta table. Boolean/Numeric values stored as plain values; Arrays stored as JSON/serialized arrays; Ranges stored as twin min/max fields.',
                    'cleanup_action' => 'حذف فیلد از بخش تنظیمات بایروز > سازنده مشخصات فنی محصول و پاکسازی متای پست‌ها.',
                ),
            ),
            'summary' => array(
                'generated_at'                 => current_time( 'mysql' ),
                'site_url'                     => site_url(),
                'total_global_attributes'      => count( $attribute_taxonomies ),
                'active_global_attributes'     => 0,
                'unused_global_attributes'     => 0,
                'total_custom_local_attributes'=> 0,
                'total_buyruz_product_specs'   => 0,
                'active_buyruz_product_specs'   => 0,
                'unused_buyruz_product_specs'   => 0,
            ),
            'global_attributes'       => array(),
            'custom_local_attributes'  => array(),
            'buyruz_product_specs'     => array(),
        );

        // 1. Process Global WooCommerce Attributes
        foreach ( $attribute_taxonomies as $tax_obj ) {
            $taxonomy_name = wc_attribute_taxonomy_name( $tax_obj->attribute_name );
            
            if ( ! taxonomy_exists( $taxonomy_name ) ) {
                continue;
            }

            $terms = get_terms( array(
                'taxonomy'   => $taxonomy_name,
                'hide_empty' => false,
            ) );

            if ( is_wp_error( $terms ) ) {
                $terms = array();
            }

            $total_terms        = count( $terms );
            $used_terms_count   = 0;
            $unused_terms_count = 0;
            $terms_data         = array();

            // Find all unique product IDs associated with this taxonomy
            $term_taxonomy_ids = wp_list_pluck( $terms, 'term_taxonomy_id' );
            $taxonomy_product_ids = array();
            
            if ( ! empty( $term_taxonomy_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
                $taxonomy_product_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT tr.object_id 
                     FROM {$wpdb->term_relationships} tr 
                     INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
                     WHERE tr.term_taxonomy_id IN ($placeholders) 
                       AND p.post_type = 'product' 
                       AND p.post_status NOT IN ('trash', 'auto-draft')",
                    $term_taxonomy_ids
                ) );
            }
            $total_products_count = count( $taxonomy_product_ids );

            // Gather all product variations explicitly matching terms of this taxonomy
            $variation_query_result = $wpdb->get_results( $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value 
                 FROM {$wpdb->postmeta} pm 
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                 WHERE pm.meta_key = %s 
                   AND p.post_type = 'product_variation' 
                   AND p.post_status NOT IN ('trash', 'auto-draft')",
                'attribute_' . $taxonomy_name
            ) );

            $total_variations_count = count( $variation_query_result );
            $variation_slugs = array();
            foreach ( $variation_query_result as $row ) {
                if ( ! empty( $row->meta_value ) ) {
                    $variation_slugs[ $row->meta_value ][] = (int) $row->post_id;
                }
            }

            // Loop terms to construct detailed statistics per option
            foreach ( $terms as $term ) {
                // Products direct relations
                $term_product_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT tr.object_id 
                     FROM {$wpdb->term_relationships} tr 
                     INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
                     WHERE tr.term_taxonomy_id = %d 
                       AND p.post_type = 'product' 
                       AND p.post_status NOT IN ('trash', 'auto-draft')",
                    $term->term_taxonomy_id
                ) );

                $product_count   = count( $term_product_ids );
                $term_var_ids    = isset( $variation_slugs[ $term->slug ] ) ? $variation_slugs[ $term->slug ] : array();
                $variation_count = count( $term_var_ids );

                $total_usages = $product_count + $variation_count;
                $is_used      = $total_usages > 0;

                if ( $is_used ) {
                    $used_terms_count++;
                } else {
                    $unused_terms_count++;
                }

                // Sample Products list (Max 5)
                $sample_products = array();
                if ( ! empty( $term_product_ids ) ) {
                    $sample_ids = array_slice( $term_product_ids, 0, 5 );
                    foreach ( $sample_ids as $pid ) {
                        $sample_products[] = array(
                            'id'     => (int) $pid,
                            'title'  => get_the_title( $pid ),
                            'status' => get_post_status( $pid ),
                        );
                    }
                }

                // Parent products for variations (if no direct product references exist)
                $sample_parent_products = array();
                if ( empty( $sample_products ) && ! empty( $term_var_ids ) ) {
                    $sample_var_ids = array_slice( $term_var_ids, 0, 5 );
                    foreach ( $sample_var_ids as $vid ) {
                        $parent_id = wp_get_post_parent_id( $vid );
                        if ( $parent_id ) {
                            $sample_parent_products[] = array(
                                'parent_id'     => (int) $parent_id,
                                'parent_title'  => get_the_title( $parent_id ),
                                'variation_id'  => (int) $vid,
                                'status'        => get_post_status( $parent_id ),
                            );
                        }
                    }
                }

                $terms_data[] = array(
                    'term_id'                => (int) $term->term_id,
                    'name'                   => $term->name,
                    'slug'                   => $term->slug,
                    'description'            => $term->description,
                    'product_count'          => $product_count,
                    'variation_count'        => $variation_count,
                    'total_usages'           => $total_usages,
                    'is_used'                => $is_used,
                    'sample_products'        => $sample_products,
                    'sample_parent_products' => $sample_parent_products,
                );
            }

            $has_any_usage = ( $total_products_count > 0 || $total_variations_count > 0 );

            if ( $has_any_usage ) {
                $stats['summary']['active_global_attributes']++;
            } else {
                $stats['summary']['unused_global_attributes']++;
            }

            $stats['global_attributes'][] = array(
                'attribute_id'           => (int) $tax_obj->attribute_id,
                'attribute_name'         => $tax_obj->attribute_name,
                'attribute_label'        => $tax_obj->attribute_label,
                'taxonomy'               => $taxonomy_name,
                'attribute_type'         => $tax_obj->attribute_type,
                'has_usage'              => $has_any_usage,
                'total_products_count'   => $total_products_count,
                'total_variations_count' => $total_variations_count,
                'total_terms'            => $total_terms,
                'used_terms_count'       => $used_terms_count,
                'unused_terms_count'     => $unused_terms_count,
                'in_schema'              => in_array( $taxonomy_name, $schema_enabled, true ),
                'terms'                  => $terms_data,
            );
        }

        // 2. Process Custom Local Attributes (defined directly on products metadata)
        $custom_attrs_query = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = '_product_attributes' 
               AND meta_value != '' 
               AND meta_value != 'a:0:{}'"
        );

        $custom_attributes_stats = array();
        foreach ( $custom_attrs_query as $row ) {
            $meta = maybe_unserialize( $row->meta_value );
            if ( is_array( $meta ) ) {
                foreach ( $meta as $key => $attr_data ) {
                    $is_taxonomy = isset( $attr_data['is_taxonomy'] ) ? $attr_data['is_taxonomy'] : 0;
                    if ( ! $is_taxonomy ) {
                        $name  = isset( $attr_data['name'] ) ? $attr_data['name'] : $key;
                        $value = isset( $attr_data['value'] ) ? $attr_data['value'] : '';
                        
                        if ( ! isset( $custom_attributes_stats[ $name ] ) ) {
                            $custom_attributes_stats[ $name ] = array(
                                'name'            => $name,
                                'usage_count'     => 0,
                                'values'          => array(),
                                'sample_products' => array(),
                            );
                        }

                        $custom_attributes_stats[ $name ]['usage_count']++;
                        
                        $val_parts = array_map( 'trim', explode( '|', $value ) );
                        foreach ( $val_parts as $part ) {
                            if ( ! empty( $part ) ) {
                                if ( ! isset( $custom_attributes_stats[ $name ]['values'][ $part ] ) ) {
                                    $custom_attributes_stats[ $name ]['values'][ $part ] = 0;
                                }
                                $custom_attributes_stats[ $name ]['values'][ $part ]++;
                            }
                        }

                        if ( count( $custom_attributes_stats[ $name ]['sample_products'] ) < 5 ) {
                            $custom_attributes_stats[ $name ]['sample_products'][] = array(
                                'id'    => (int) $row->post_id,
                                'title' => get_the_title( $row->post_id ),
                            );
                        }
                    }
                }
            }
        }

        // Format custom attributes stats into list
        $formatted_custom = array();
        foreach ( $custom_attributes_stats as $name => $data ) {
            arsort( $data['values'] );
            
            $formatted_custom[] = array(
                'name'                => $data['name'],
                'usage_count'         => $data['usage_count'],
                'unique_values_count' => count( $data['values'] ),
                'top_values'          => array_slice( $data['values'], 0, 10, true ),
                'sample_products'     => $data['sample_products'],
            );
        }

        $stats['custom_local_attributes']                 = $formatted_custom;
        $stats['summary']['total_custom_local_attributes'] = count( $formatted_custom );

        // 3. Process Buyruz Custom Product Specs (Dynamic Meta fields)
        if ( class_exists( 'BRZ_Product_Specs' ) ) {
            $spec_fields = BRZ_Product_Specs::get_fields();
            $stats['summary']['total_buyruz_product_specs'] = count( $spec_fields );

            foreach ( $spec_fields as $field ) {
                $key   = $field['key'];
                $label = $field['label'];
                $type  = $field['type'];

                $spec_info = array(
                    'key'                  => $key,
                    'label'                => $label,
                    'type'                 => $type,
                    'total_products_count' => 0,
                    'in_schema'            => in_array( 'spec_' . $key, $schema_enabled, true ),
                    'sample_products'      => array(),
                );

                $product_ids = array();

                if ( 'range' === $type ) {
                    $keys = BRZ_Product_Specs::get_range_meta_keys( $key );
                    $product_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT pm.post_id 
                         FROM {$wpdb->postmeta} pm 
                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                         WHERE pm.meta_key IN (%s, %s) 
                           AND pm.meta_value != '' 
                           AND p.post_type = 'product' 
                           AND p.post_status NOT IN ('trash', 'auto-draft')",
                        $keys[0],
                        $keys[1]
                    ) );

                    $spec_info['total_products_count'] = count( $product_ids );

                    // Get min/max range statistics
                    $min_vals = $wpdb->get_col( $wpdb->prepare(
                        "SELECT CAST(pm.meta_value AS SIGNED) FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                         WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
                        $keys[0]
                    ) );
                    $max_vals = $wpdb->get_col( $wpdb->prepare(
                        "SELECT CAST(pm.meta_value AS SIGNED) FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                         WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
                        $keys[1]
                    ) );

                    $spec_info['min_stats'] = array(
                        'min' => ! empty( $min_vals ) ? min( $min_vals ) : null,
                        'max' => ! empty( $min_vals ) ? max( $min_vals ) : null,
                        'avg' => ! empty( $min_vals ) ? round( array_sum( $min_vals ) / count( $min_vals ), 2 ) : null,
                    );
                    $spec_info['max_stats'] = array(
                        'min' => ! empty( $max_vals ) ? min( $max_vals ) : null,
                        'max' => ! empty( $max_vals ) ? max( $max_vals ) : null,
                        'avg' => ! empty( $max_vals ) ? round( array_sum( $max_vals ) / count( $max_vals ), 2 ) : null,
                    );

                } elseif ( 'array' === $type ) {
                    $meta_key = '_brz_spec_' . $key;
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT pm.post_id, pm.meta_value 
                         FROM {$wpdb->postmeta} pm 
                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                         WHERE pm.meta_key = %s 
                           AND pm.meta_value != '' 
                           AND p.post_type = 'product' 
                           AND p.post_status NOT IN ('trash', 'auto-draft')",
                        $meta_key
                    ) );

                    $options_usage   = array();
                    $defined_options = array_map( 'trim', explode( ',', isset( $field['options'] ) ? $field['options'] : '' ) );
                    $defined_options = array_filter( $defined_options );

                    foreach ( $rows as $row ) {
                        $val = $row->meta_value;
                        $decoded = json_decode( $val, true );
                        if ( ! is_array( $decoded ) ) {
                            $decoded = maybe_unserialize( $val );
                        }

                        if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                            $product_ids[] = (int) $row->post_id;
                            foreach ( $decoded as $item ) {
                                $item = trim( $item );
                                if ( '' !== $item ) {
                                    if ( ! isset( $options_usage[ $item ] ) ) {
                                        $options_usage[ $item ] = 0;
                                    }
                                    $options_usage[ $item ]++;
                                }
                            }
                        }
                    }

                    $product_ids = array_values( array_unique( $product_ids ) );
                    $spec_info['total_products_count'] = count( $product_ids );

                    // Categorize defined vs unregistered options
                    $unused_defined = array();
                    foreach ( $defined_options as $opt ) {
                        if ( ! isset( $options_usage[ $opt ] ) ) {
                            $unused_defined[] = $opt;
                        }
                    }

                    $unregistered_options = array();
                    foreach ( $options_usage as $opt => $count ) {
                        if ( ! in_array( $opt, $defined_options, true ) ) {
                            $unregistered_options[ $opt ] = $count;
                        }
                    }

                    $spec_info['array_stats'] = array(
                        'defined_options'            => $defined_options,
                        'options_usage'              => $options_usage,
                        'unused_defined_options'     => $unused_defined,
                        'unregistered_options_found' => $unregistered_options,
                    );

                } else {
                    // boolean, integer, decimal
                    $meta_key = '_brz_spec_' . $key;
                    $product_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT pm.post_id 
                         FROM {$wpdb->postmeta} pm 
                         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                         WHERE pm.meta_key = %s 
                           AND pm.meta_value != '' 
                           AND p.post_type = 'product' 
                           AND p.post_status NOT IN ('trash', 'auto-draft')",
                        $meta_key
                    ) );

                    $spec_info['total_products_count'] = count( $product_ids );

                    if ( 'boolean' === $type ) {
                        $true_count = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                             WHERE pm.meta_key = %s AND pm.meta_value IN ('1', 'true') AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
                            $meta_key
                        ) );
                        $spec_info['boolean_stats'] = array(
                            'true_count'  => $true_count,
                            'false_count' => max( 0, count( $product_ids ) - $true_count ),
                        );
                    } elseif ( 'integer' === $type || 'decimal' === $type ) {
                        $vals = $wpdb->get_col( $wpdb->prepare(
                            "SELECT CAST(pm.meta_value AS DECIMAL(10,2)) FROM {$wpdb->postmeta} pm
                             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                             WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
                            $meta_key
                        ) );
                        $spec_info['numeric_stats'] = array(
                            'min' => ! empty( $vals ) ? min( $vals ) : null,
                            'max' => ! empty( $vals ) ? max( $vals ) : null,
                            'avg' => ! empty( $vals ) ? round( array_sum( $vals ) / count( $vals ), 2 ) : null,
                        );
                    }
                }

                // Sample products mapping (up to 5)
                if ( ! empty( $product_ids ) ) {
                    $sample_ids = array_slice( $product_ids, 0, 5 );
                    foreach ( $sample_ids as $pid ) {
                        $spec_info['sample_products'][] = array(
                            'id'     => (int) $pid,
                            'title'  => get_the_title( $pid ),
                            'status' => get_post_status( $pid ),
                        );
                    }
                }

                if ( $spec_info['total_products_count'] > 0 ) {
                    $stats['summary']['active_buyruz_product_specs']++;
                } else {
                    $stats['summary']['unused_buyruz_product_specs']++;
                }

                $stats['buyruz_product_specs'][] = $spec_info;
            }
        }

        return $stats;
    }

    /**
     * Admin-post callback to download stats file.
     */
    public static function handle_download_stats() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'buyruz-settings' ) );
        }

        check_admin_referer( self::DOWNLOAD_ACTION );

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_die( esc_html__( 'WooCommerce is not active.', 'buyruz-settings' ) );
        }

        ignore_user_abort( true );
        @set_time_limit( 120 );

        $stats = self::generate_stats();

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="buyruz-attributes-stats.json"' );

        echo wp_json_encode( $stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * WP-CLI command callback.
     */
    public static function cli_attributes_stats( $args, $assoc_args ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            WP_CLI::error( 'WooCommerce is not active.' );
        }

        $stats = self::generate_stats();
        WP_CLI::log( wp_json_encode( $stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * Render the admin page content.
     */
    public static function render_admin_page(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            ?>
            <div class="notice notice-error">
                <p>برای اجرای این ماژول، افزونه WooCommerce باید فعال باشد.</p>
            </div>
            <?php
            return;
        }

        $stats = self::generate_stats();
        $summary = $stats['summary'];

        // Get API endpoint example URLs
        $api_url = rest_url( 'buyruz/v1/attributes-stats' );
        
        // Find if we have an API key configured
        $api_key = '';
        if ( class_exists( 'BRZ_Smart_Linker' ) ) {
            $sl_settings = BRZ_Smart_Linker::get_settings();
            $api_key = isset( $sl_settings['local_api_key'] ) ? $sl_settings['local_api_key'] : '';
        }
        if ( empty( $api_key ) && class_exists( 'BRZ_BI_Exporter' ) ) {
            $bi_settings = get_option( 'brz_bi_exporter', array() );
            $api_key = isset( $bi_settings['api_key'] ) ? $bi_settings['api_key'] : '';
        }

        $api_url_with_key = ! empty( $api_key ) ? add_query_arg( 'api_key', $api_key, $api_url ) : $api_url;
        ?>
        <style>
            .brz-analyzer-stat-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px;
                margin-bottom: 25px;
            }
            .brz-analyzer-stat-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .brz-analyzer-stat-value {
                font-size: 32px;
                font-weight: bold;
                color: var(--brz-brand, #1a73e8);
                margin: 10px 0;
            }
            .brz-analyzer-stat-title {
                color: #555;
                font-size: 14px;
            }
            .brz-analyzer-box {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 25px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .brz-analyzer-box h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
                color: #23282d;
            }
            .brz-analyzer-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
                text-align: right;
            }
            .brz-analyzer-table th, .brz-analyzer-table td {
                padding: 12px 10px;
                border-bottom: 1px solid #f0f0f0;
            }
            .brz-analyzer-table th {
                background: #f9f9f9;
                font-weight: 600;
                color: #333;
            }
            .brz-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
            }
            .brz-status-badge--success {
                background: #e6f4ea;
                color: #137333;
            }
            .brz-status-badge--warning {
                background: #fce8e6;
                color: #c5221f;
            }
            .brz-status-badge--info {
                background: #e8f0fe;
                color: #1a73e8;
            }
            .brz-code-block {
                background: #f7f7f9;
                border: 1px solid #e1e1e8;
                padding: 10px;
                border-radius: 4px;
                font-family: monospace;
                direction: ltr;
                text-align: left;
                overflow-x: auto;
                margin: 10px 0;
            }
            .brz-action-bar {
                margin: 20px 0;
                display: flex;
                gap: 15px;
                align-items: center;
            }
        </style>

        <div class="brz-single-column" dir="rtl">
            <div class="brz-analyzer-box">
                <h3>آنالیز و پاکسازی ویژگی‌ها و مشخصات محصولات</h3>
                <p>این ابزار آمار کاملی از ویژگی‌های ووکامرس (سراسری و محلی) و مشخصات فنی اختصاصی بایروز استخراج می‌کند تا متوجه شوید کدام مشخصات بی‌استفاده بوده و قابل حذف هستند.</p>
                
                <div class="brz-action-bar">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION ), self::DOWNLOAD_ACTION ) ); ?>" class="button button-primary button-large">
                        دانلود گزارش کامل JSON برای هوش مصنوعی
                    </a>
                </div>
            </div>

            <div class="brz-analyzer-stat-grid">
                <div class="brz-analyzer-stat-card">
                    <div class="brz-analyzer-stat-title">ویژگی‌های سراسری ووکامرس</div>
                    <div class="brz-analyzer-stat-value"><?php echo esc_html( $summary['total_global_attributes'] ); ?></div>
                    <div class="description"><?php echo esc_html( $summary['active_global_attributes'] ); ?> فعال / <?php echo esc_html( $summary['unused_global_attributes'] ); ?> بدون استفاده</div>
                </div>
                <div class="brz-analyzer-stat-card">
                    <div class="brz-analyzer-stat-title">مشخصات فنی اختصاصی بایروز</div>
                    <div class="brz-analyzer-stat-value" style="color: #1a73e8;"><?php echo esc_html( $summary['total_buyruz_product_specs'] ); ?></div>
                    <div class="description"><?php echo esc_html( $summary['active_buyruz_product_specs'] ); ?> فعال / <?php echo esc_html( $summary['unused_buyruz_product_specs'] ); ?> بدون استفاده</div>
                </div>
                <div class="brz-analyzer-stat-card">
                    <div class="brz-analyzer-stat-title">ویژگی‌های محلی محصول</div>
                    <div class="brz-analyzer-stat-value" style="color: #e37400;"><?php echo esc_html( $summary['total_custom_local_attributes'] ); ?></div>
                    <div class="description">تعریف شده مستقیم درون محصولات</div>
                </div>
            </div>

            <div class="brz-analyzer-box">
                <h3>لینک دسترسی API جهت استفاده ایجنت‌ها</h3>
                <p>ایجنت هوش مصنوعی شما می‌تواند با فراخوانی لینک زیر، آمار کامل ویژگی‌ها را در قالب JSON برای تصمیم‌گیری دریافت کند:</p>
                <div class="brz-code-block"><?php echo esc_html( $api_url_with_key ); ?></div>
                <?php if ( empty( $api_key ) ) : ?>
                    <p class="description" style="color: #c5221f;">توجه: هیچ کلید API سراسری ثبت نشده است. درخواست‌های API خارج از نشست ادمین نیاز به احراز هویت خواهند داشت. کلید API محلی را می‌توانید در بخش تنظیمات > ارتباطات مدیریت کنید.</p>
                <?php endif; ?>
            </div>

            <div class="brz-analyzer-box">
                <h3>خلاصه مشخصات فنی بایروز (Buyruz Product Specs)</h3>
                <p>مشخصاتی که در ماژول مشخصات فنی بایروز ساخته شده‌اند و به صورت فیلدهای مستقیم متادیتای محصول ذخیره می‌شوند:</p>
                <table class="brz-analyzer-table">
                    <thead>
                        <tr>
                            <th>نام مشخصه (برچسب)</th>
                            <th>کلید متادیتا (Meta Key)</th>
                            <th>نوع فیلد</th>
                            <th>تعداد محصولات دارای مقدار</th>
                            <th>ارسال به اسکیما</th>
                            <th>وضعیت استفاده</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $stats['buyruz_product_specs'] ) ) : ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">هیچ مشخصه فنی در بایروز ساخته نشده است.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $stats['buyruz_product_specs'] as $spec ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $spec['label'] ); ?></strong></td>
                                    <td dir="ltr" style="text-align: right;">
                                        <?php 
                                        if ( 'range' === $spec['type'] ) {
                                            $keys = BRZ_Product_Specs::get_range_meta_keys( $spec['key'] );
                                            echo esc_html( $keys[0] . ' / ' . $keys[1] );
                                        } else {
                                            echo esc_html( '_brz_spec_' . $spec['key'] );
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        switch ( $spec['type'] ) {
                                            case 'boolean': echo 'ساده (بله/خیر)'; break;
                                            case 'integer': echo 'عدد صحیح'; break;
                                            case 'decimal': echo 'عدد اعشاری'; break;
                                            case 'range': echo 'بازه عددی'; break;
                                            case 'array': echo 'آرایه انتخابی (چند گزینه‌ای)'; break;
                                            default: echo esc_html( $spec['type'] );
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( $spec['total_products_count'] ); ?> محصول</td>
                                    <td>
                                        <?php if ( ! empty( $spec['in_schema'] ) ) : ?>
                                            <span class="brz-status-badge brz-status-badge--info">بله</span>
                                        <?php else : ?>
                                            <span class="brz-status-badge" style="background:#f0f0f0;color:#666;">خیر</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $spec['total_products_count'] > 0 ) : ?>
                                            <span class="brz-status-badge brz-status-badge--success">فعال</span>
                                        <?php else : ?>
                                            <span class="brz-status-badge brz-status-badge--warning">بدون استفاده</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="brz-analyzer-box">
                <h3>خلاصه ویژگی‌های سراسری ووکامرس (WooCommerce Global Attributes)</h3>
                <p>ویژگی‌های سراسری ووکامرس که به عنوان تاکسونومی مجزا ذخیره شده و گزینه‌های آن‌ها ترم‌های دیتابیس هستند:</p>
                <table class="brz-analyzer-table">
                    <thead>
                        <tr>
                            <th>نام ویژگی</th>
                            <th>شناسه (Taxonomy)</th>
                            <th>تعداد گزینه‌ها</th>
                            <th>محصولات مرتبط</th>
                            <th>تنوع‌های مرتبط</th>
                            <th>ارسال به اسکیما</th>
                            <th>وضعیت استفاده</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $stats['global_attributes'] ) ) : ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">هیچ ویژگی سراسری ثبت نشده است.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $stats['global_attributes'] as $attr ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $attr['attribute_label'] ); ?></strong> (<?php echo esc_html( $attr['attribute_name'] ); ?>)</td>
                                    <td dir="ltr" style="text-align: right;"><?php echo esc_html( $attr['taxonomy'] ); ?></td>
                                    <td><?php echo esc_html( $attr['total_terms'] ); ?> (<?php echo esc_html( $attr['used_terms_count'] ); ?> استفاده شده)</td>
                                    <td><?php echo esc_html( $attr['total_products_count'] ); ?> محصول</td>
                                    <td><?php echo esc_html( $attr['total_variations_count'] ); ?> تنوع</td>
                                    <td>
                                        <?php if ( ! empty( $attr['in_schema'] ) ) : ?>
                                            <span class="brz-status-badge brz-status-badge--info">بله</span>
                                        <?php else : ?>
                                            <span class="brz-status-badge" style="background:#f0f0f0;color:#666;">خیر</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $attr['has_usage'] ) : ?>
                                            <span class="brz-status-badge brz-status-badge--success">فعال</span>
                                        <?php else : ?>
                                            <span class="brz-status-badge brz-status-badge--warning">بدون استفاده</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( ! empty( $stats['custom_local_attributes'] ) ) : ?>
                <div class="brz-analyzer-box">
                    <h3>خلاصه ویژگی‌های محلی (سفارشی در محصول)</h3>
                    <p>این ویژگی‌ها به صورت سراسری در ووکامرس وجود ندارند و فقط در داخل متای محصولات خاص تعریف شده‌اند:</p>
                    <table class="brz-analyzer-table">
                        <thead>
                            <tr>
                                <th>نام ویژگی</th>
                                <th>تعداد محصولات استفاده‌کننده</th>
                                <th>تعداد مقادیر یکتا</th>
                                <th>نمونه مقادیر رایج</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $stats['custom_local_attributes'] as $custom_attr ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $custom_attr['name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $custom_attr['usage_count'] ); ?> محصول</td>
                                    <td><?php echo esc_html( $custom_attr['unique_values_count'] ); ?> مورد</td>
                                    <td dir="ltr" style="text-align: right;">
                                        <?php
                                        $samples = array();
                                        foreach ( $custom_attr['top_values'] as $val => $count ) {
                                            $samples[] = esc_html( $val ) . ' (' . esc_html( $count ) . ')';
                                        }
                                        echo implode( '، ', array_slice( $samples, 0, 5 ) );
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
