<?php
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BRZ_Transfer_Processor {
    
    public function process( $operation_type, $payload ) {
        switch ( $operation_type ) {
            case 'product.sync':
            case 'product.register':
            case 'product.edit':
            case 'product.combined_edit':
                return $this->process_product_sync( $payload );
            
            case 'taxonomy.sync_to_site':
            case 'attribute.sync':
                return $this->process_taxonomy_sync( $payload );

            case 'product.attribute_apply':
                return $this->process_product_attribute_apply( $payload );

            case 'price.batch_send':
            case 'price.batch_apply':
                return $this->process_price_batch( $payload );

            case 'inventory.batch_send':
                return $this->process_price_batch( $payload ); // shares logic

            case 'product.receive':
                return $this->process_product_receive( $payload );
            
            case 'taxonomy.sync_from_site':
                return $this->process_taxonomy_receive( $payload );
                
            case 'price.receive':
                return $this->process_price_receive( $payload );
                
            case 'order.receive':
                return $this->process_order_receive( $payload );
                
            case 'order.process':
                return $this->process_order_action( $payload );

            default:
                throw new Exception( "نوع عملیات ناشناخته: {$operation_type}" );
        }
    }

    private function process_product_sync( $payload ) {
        $results = array();
        $synced = 0;
        $failed = 0;

        $products = isset( $payload['products'] ) ? $payload['products'] : array();

        foreach ( $products as $prod_data ) {
            try {
                // Find existing product by SKU or ID
                $product_id = 0;
                if ( ! empty( $prod_data['sku'] ) ) {
                    $product_id = wc_get_product_id_by_sku( $prod_data['sku'] );
                }
                
                if ( $product_id ) {
                    $product = wc_get_product( $product_id );
                } else {
                    $product = new WC_Product_Simple();
                }

                if ( ! empty( $prod_data['name'] ) ) {
                    $product->set_name( $prod_data['name'] );
                }
                if ( ! empty( $prod_data['sku'] ) ) {
                    $product->set_sku( $prod_data['sku'] );
                }
                if ( isset( $prod_data['price'] ) ) {
                    $price = $prod_data['price'];
                    if ( isset( $price['regular_price'] ) ) {
                        $product->set_regular_price( $price['regular_price'] );
                    }
                    if ( isset( $price['sale_price'] ) ) {
                        $product->set_sale_price( $price['sale_price'] );
                    }
                    if ( isset( $price['stock_quantity'] ) ) {
                        $product->set_manage_stock( true );
                        $product->set_stock_quantity( $price['stock_quantity'] );
                    }
                }
                if ( isset( $prod_data['weight_kg'] ) ) {
                    $product->set_weight( $prod_data['weight_kg'] );
                }
                if ( isset( $prod_data['length_cm'] ) ) {
                    $product->set_length( $prod_data['length_cm'] );
                }
                if ( isset( $prod_data['width_cm'] ) ) {
                    $product->set_width( $prod_data['width_cm'] );
                }
                if ( isset( $prod_data['height_cm'] ) ) {
                    $product->set_height( $prod_data['height_cm'] );
                }

                // Setup categories
                if ( ! empty( $prod_data['category_ids'] ) ) {
                    $product->set_category_ids( $prod_data['category_ids'] );
                }
                
                // Save
                $id = $product->save();

                $results[] = array(
                    'name' => $product->get_name(),
                    'woo_id' => $id,
                    'status' => 'synced'
                );
                $synced++;
            } catch ( Exception $e ) {
                $results[] = array(
                    'name' => isset( $prod_data['name'] ) ? $prod_data['name'] : 'Unknown',
                    'status' => 'error',
                    'error' => $e->getMessage()
                );
                $failed++;
            }
        }

        return array(
            'results' => $results,
            'summary' => array( 'total' => count( $products ), 'synced' => $synced, 'failed' => $failed )
        );
    }

    private function process_taxonomy_sync( $payload ) {
        $results = array( 'categories' => array(), 'tags' => array(), 'attributes' => array() );
        
        if ( ! empty( $payload['categories'] ) ) {
            foreach ( $payload['categories'] as $cat ) {
                $term = term_exists( $cat['name'], 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $cat['name'], 'product_cat', array( 'slug' => isset($cat['slug']) ? $cat['slug'] : '' ) );
                }
                if ( ! is_wp_error( $term ) ) {
                    $results['categories'][] = array( 'name' => $cat['name'], 'woo_id' => $term['term_id'], 'status' => 'synced' );
                }
            }
        }
        
        if ( ! empty( $payload['attributes'] ) ) {
            foreach ( $payload['attributes'] as $attr ) {
                // 1. Create or Find Attribute
                $slug = isset($attr['slug']) ? $attr['slug'] : sanitize_title( $attr['name'] );
                if ( strpos( $slug, 'pa_' ) === 0 ) {
                    $slug = substr( $slug, 3 );
                }
                
                $attr_id = wc_attribute_taxonomy_id_by_name( $slug );
                if ( ! $attr_id ) {
                    $args = array(
                        'name'         => $attr['name'],
                        'slug'         => $slug,
                        'type'         => 'select',
                        'order_by'     => 'menu_order',
                        'has_archives' => false,
                    );
                    $attr_id = wc_create_attribute( $args );
                }

                if ( is_wp_error( $attr_id ) ) {
                    $results['attributes'][] = array( 'name' => $attr['name'], 'status' => 'error', 'error' => $attr_id->get_error_message() );
                    continue;
                }

                // Register taxonomy on the fly so we can insert terms immediately
                $taxonomy_name = 'pa_' . $slug;
                if ( ! taxonomy_exists( $taxonomy_name ) ) {
                    register_taxonomy( $taxonomy_name, apply_filters( 'woocommerce_taxonomy_objects_product_attribute', array( 'product' ) ), apply_filters( 'woocommerce_taxonomy_args_product_attribute', array(
                        'hierarchical' => true,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ) ) );
                }

                $term_results = array();
                if ( ! empty( $attr['terms'] ) ) {
                    foreach ( $attr['terms'] as $term_name ) {
                        $term_slug = sanitize_title( $term_name );
                        $term = term_exists( $term_name, $taxonomy_name );
                        if ( ! $term ) {
                            $term = wp_insert_term( $term_name, $taxonomy_name, array( 'slug' => $term_slug ) );
                        }
                        if ( ! is_wp_error( $term ) ) {
                            $term_results[] = array( 'name' => $term_name, 'woo_id' => $term['term_id'] );
                        }
                    }
                }
                
                $results['attributes'][] = array( 'name' => $attr['name'], 'woo_id' => $attr_id, 'status' => 'synced', 'terms' => $term_results );
            }
        }

        return $results;
    }

    private function process_price_batch( $payload ) {
        $results = array();
        $updated = 0;
        $errors = 0;

        $prices = isset( $payload['prices'] ) ? $payload['prices'] : array();

        foreach ( $prices as $priceData ) {
            try {
                $product_id = 0;
                if ( ! empty( $priceData['sku'] ) ) {
                    $product_id = wc_get_product_id_by_sku( $priceData['sku'] );
                } else if ( ! empty( $priceData['woo_id'] ) ) {
                    $product_id = $priceData['woo_id'];
                }

                if ( ! $product_id ) {
                    $results[] = array( 'identifier' => $priceData['sku'] ?? 'unknown', 'status' => 'not_found' );
                    continue;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    $results[] = array( 'identifier' => $priceData['sku'] ?? 'unknown', 'status' => 'not_found' );
                    continue;
                }

                if ( isset( $priceData['regular_price'] ) ) {
                    $product->set_regular_price( $priceData['regular_price'] );
                }
                if ( isset( $priceData['sale_price'] ) ) {
                    $product->set_sale_price( $priceData['sale_price'] );
                }
                if ( isset( $priceData['stock_quantity'] ) ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $priceData['stock_quantity'] );
                }

                $product->save();
                $results[] = array( 'identifier' => isset( $priceData['sku'] ) ? $priceData['sku'] : $product_id, 'status' => 'updated' );
                $updated++;
            } catch ( Exception $e ) {
                $results[] = array( 'identifier' => isset( $priceData['sku'] ) ? $priceData['sku'] : 'unknown', 'status' => 'error', 'error' => $e->getMessage() );
                $errors++;
            }
        }

        return array( 'results' => $results, 'summary' => array( 'total' => count( $prices ), 'updated' => $updated, 'errors' => $errors ) );
    }

    private function process_product_attribute_apply( $payload ) {
        $results = array();
        
        if ( empty( $payload['products'] ) ) {
            return $results;
        }

        foreach ( $payload['products'] as $prod_data ) {
            $product_id = $prod_data['woo_id'];
            $features = $prod_data['features'];
            
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $results[] = array( 'woo_id' => $product_id, 'status' => 'error', 'error' => 'Product not found' );
                continue;
            }

            $product_attributes = array();
            $position = 0;

            foreach ( $features as $feat ) {
                $attr_name = $feat['attribute'];
                $slug = sanitize_title( $attr_name );
                if ( strpos( $slug, 'pa_' ) === 0 ) {
                    $slug = substr( $slug, 3 );
                }

                $attr_id = wc_attribute_taxonomy_id_by_name( $slug );
                if ( ! $attr_id ) {
                    $args = array( 'name' => $attr_name, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false );
                    $attr_id = wc_create_attribute( $args );
                }
                
                $taxonomy_name = 'pa_' . $slug;
                if ( ! taxonomy_exists( $taxonomy_name ) ) {
                    register_taxonomy( $taxonomy_name, apply_filters( 'woocommerce_taxonomy_objects_product_attribute', array( 'product' ) ), apply_filters( 'woocommerce_taxonomy_args_product_attribute', array(
                        'hierarchical' => true, 'show_ui' => false, 'query_var' => true, 'rewrite' => false,
                    ) ) );
                }

                $term_ids = array();
                foreach ( $feat['options'] as $opt ) {
                    $term = term_exists( $opt, $taxonomy_name );
                    if ( ! $term ) {
                        $term = wp_insert_term( $opt, $taxonomy_name, array( 'slug' => sanitize_title( $opt ) ) );
                    }
                    if ( ! is_wp_error( $term ) ) {
                        $term_ids[] = (int) $term['term_id'];
                    }
                }

                $attribute = new WC_Product_Attribute();
                $attribute->set_id( $attr_id );
                $attribute->set_name( $taxonomy_name );
                $attribute->set_options( $term_ids );
                $attribute->set_position( $position++ );
                $attribute->set_visible( $feat['visible'] );
                $attribute->set_variation( $feat['variation'] );
                
                $product_attributes[] = $attribute;
            }

            $product->set_attributes( $product_attributes );
            $product->save();
            
            $results[] = array( 'woo_id' => $product_id, 'status' => 'synced' );
        }
        
        return array( 'results' => $results );
    }

    private function process_product_receive( $payload ) {
        $products = array();
        
        $args = array( 'limit' => 50, 'status' => 'publish' );
        if ( ! empty( $payload['product_ids'] ) ) {
            $args['include'] = $payload['product_ids'];
        }

        $woo_products = wc_get_products( $args );
        
        foreach ( $woo_products as $p ) {
            $products[] = array(
                'woo_id' => $p->get_id(),
                'name' => $p->get_name(),
                'sku' => $p->get_sku(),
                'regular_price' => $p->get_regular_price(),
                'sale_price' => $p->get_sale_price(),
                'stock_quantity' => $p->get_stock_quantity()
            );
        }

        return array( 'products' => $products );
    }

    private function process_taxonomy_receive( $payload ) {
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
        $cats_formatted = array();
        foreach ( $categories as $c ) {
            $cats_formatted[] = array( 'woo_id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug );
        }
        return array( 'categories' => $cats_formatted );
    }

    private function process_price_receive( $payload ) {
        return $this->process_product_receive( $payload ); // Same output roughly
    }

    private function process_order_receive( $payload ) {
        // Implement order export
        return array( 'orders' => array() );
    }

    private function process_order_action( $payload ) {
        // Implement order update
        return array( 'results' => array() );
    }
}
