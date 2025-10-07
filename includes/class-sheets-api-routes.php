<?php

class Sheets_API_Routes {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route(
            Sheets_API_Plugin::REST_NAMESPACE,
            '/test_connection',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'test_connection' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            Sheets_API_Plugin::REST_NAMESPACE,
            '/get_products',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_products' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            Sheets_API_Plugin::REST_NAMESPACE,
            '/update_products',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'update_products' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'products' => [
                        'required' => true,
                        'type'     => 'array',
                    ],
                ],
            ]
        );
    }

    /**
     * Test Connection Endpoint
     */
    public static function test_connection( \WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $sheet_token = isset( $headers['x_sheet_token'][0] ) ? $headers['x_sheet_token'][0] : '';
        $sheet_id = Sheets_API_Plugin::decrypt_sheet_id( $sheet_token );
        if ( $sheet_id !== Sheets_API_Plugin::get_sheet_id() ) {
            return new \WP_Error( 'invalid_sheet_id', 'Invalid Sheet ID.', [ 'status' => 403 ] );
        }

        $data = [
            'status'  => 'success',
            'message' => __( 'Connection successfull!', 'sheets-api' ),
            'time'    => current_time( 'mysql' ),
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Get Products Endpoint
     */
    public static function get_products( \WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $sheet_token = isset( $headers['x_sheet_token'][0] ) ? $headers['x_sheet_token'][0] : '';
        $sheet_id = Sheets_API_Plugin::decrypt_sheet_id( $sheet_token );
        
        if ( $sheet_id !== Sheets_API_Plugin::get_sheet_id() ) {
            return new \WP_Error( 'invalid_sheet_id', 'Invalid Sheet ID.', [ 'status' => 403 ] );
        }
        
        global $wpdb;
        
        // Single optimized query to get all product IDs with their types and parent IDs
        $product_ids = $wpdb->get_results("
            SELECT p.ID, p.post_type, p.post_parent, 
                pm.meta_value as product_type
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON (
                p.ID = pm.post_id 
                AND pm.meta_key = '_product_type'
            )
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            ORDER BY p.post_parent ASC, p.post_type ASC, p.ID ASC
        ");
        
        $products_data = [];
        $variations_map = [];
        $parent_products = [];

        // Process products in optimal order
        foreach ( $product_ids as $product_info ) {
            $product = wc_get_product( $product_info->ID );
            
            if ( ! $product ) {
                continue;
            }

            $formatted_data = self::format_product_data( $product );

            if ( $product->is_type( 'variation' ) ) {
                $parent_id = $product_info->post_parent;
                $variations_map[ $parent_id ][] = $formatted_data;
            } else {
                $parent_products[ $product_info->ID ] = $formatted_data;
            }
        }

        // Build final array with variations following parents
        foreach ( $parent_products as $parent_id => $parent_product ) {
            $products_data[] = $parent_product;
            
            // Add variations for this parent
            if ( isset( $variations_map[ $parent_id ] ) ) {
                foreach ( $variations_map[ $parent_id ] as $variation ) {
                    $products_data[] = $variation;
                }
            }
        }

        // reverse the order of the products data array
        $products_data = array_reverse( $products_data );

        $data = [
            'status'  => 'success',
            'message' => __( 'Products data retrieved successfully.', 'sheets-api' ),
            'time'    => current_time( 'mysql' ),
            'count'   => count( $products_data ),
            'data'    => $products_data,
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Format product data for API response
     */
    private static function format_product_data( $product ) {
        $attributes_string = '';
        
        if ( $product->get_type() === 'variation' ) {
            // For variations, get the variation attributes
            $variation_attributes = $product->get_variation_attributes();
            $attribute_pairs = [];
            
            foreach ( $variation_attributes as $attribute_name => $attribute_value ) {
                if ( ! empty( $attribute_value ) ) {
                    // Clean up attribute name (remove 'attribute_' prefix and pa_ taxonomy prefix)
                    $clean_name = str_replace( 'attribute_', '', $attribute_name );
                    $clean_name = str_replace( 'pa_', '', $clean_name );
                    $clean_name = ucfirst( str_replace( '-', ' ', $clean_name ) );
                    
                    // Get the actual term name if it's a taxonomy
                    if ( strpos( $attribute_name, 'pa_' ) !== false ) {
                        $term = get_term_by( 'slug', $attribute_value, $attribute_name );
                        if ( $term && ! is_wp_error( $term ) ) {
                            $attribute_value = $term->name;
                        }
                    }
                    
                    $attribute_pairs[] = $clean_name . ':' . ucfirst( $attribute_value );
                }
            }
            
            $attributes_string = implode( ', ', $attribute_pairs );
        } elseif ( $product->get_type() === 'variable' ) {
            // For variable products, show attributes and their readable values
            $attributes_string = '';
            $product_attributes = $product->get_attributes();
            $attribute_pairs = [];

            foreach ( $product_attributes as $attribute ) {
                $attribute_name = wc_attribute_label( $attribute->get_name() ); // Get readable label

                $options = $attribute->get_options();
                $values = [];

                if ( $attribute->is_taxonomy() ) {
                    // Taxonomy-based attribute (like pa_color)
                    foreach ( $options as $term_id ) {
                        $term = get_term( $term_id );
                        if ( ! is_wp_error( $term ) && $term ) {
                            $values[] = $term->name;
                        }
                    }
                } else {
                    // Custom attribute (manual input)
                    $values = $options;
                }

                if ( ! empty( $values ) ) {
                    $attribute_pairs[] = $attribute_name . ':' . implode( '|', $values );
                }
            }

            $attributes_string = implode( ', ', $attribute_pairs);

        }
        else {
            $product_attributes = $product->get_attributes();
            $attribute_pairs = [];
            
            foreach ( $product_attributes as $attribute ) {
                if ( ! $attribute->is_taxonomy() ) {
                    // For custom attributes only
                    $attribute_name = $attribute->get_name();
                    $attribute_values = $attribute->get_options();
                    if ( ! empty( $attribute_values ) ) {
                        foreach ( $attribute_values as $value ) {
                            $attribute_pairs[] = $attribute_name . ':' . $value;
                        }
                    }
                }
            }
            
            $attributes_string = implode( ', ', $attribute_pairs );
        }

        return [
            'id'    => $product->get_id(),
            'type'          => $product->get_type(),
            'parent_id'     => $product->get_parent_id() ?: '',
            'name'          => $product->get_name(),
            'sku'           => $product->get_sku() ?: '',
            'attributes'    => $attributes_string,
            'regular_price' => $product->get_regular_price() ?: '',
            'sale_price'    => $product->get_sale_price() ?: '',
            'stock'         => $product->get_stock_quantity() ?: '',
            'status'        => $product->get_status(),
        ];
    }

    /**
     * Update Products Endpoint
     */
    public static function update_products( \WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $sheet_token = isset( $headers['x_sheet_token'][0] ) ? $headers['x_sheet_token'][0] : '';
        $sheet_id = Sheets_API_Plugin::decrypt_sheet_id( $sheet_token );
        if ( $sheet_id !== Sheets_API_Plugin::get_sheet_id() ) {
            return new \WP_Error( 'invalid_sheet_id', 'Invalid Sheet ID.', [ 'status' => 403 ] );
        }

        $products = $request->get_param( 'products' );

        if ( ! is_array( $products ) ) {
            return new \WP_Error( 'invalid_data', 'Products data must be an array.', [ 'status' => 400 ] );
        }

        // split products, and variation to two lists
        $products_data = [];
        $variations_data = [];

        foreach ( $products as $product_data ) {
            if ( isset( $product_data['type'] ) && $product_data['type'] === 'variation' ) {
                $variations_data[] = $product_data;
            } else {
                $products_data[] = $product_data;
            }
        }

        $processed_products = [];
        $created_products = [];
        $updated_products = [];

        foreach ( $products_data as $product_data ) {
            if ( isset( $product_data['id'] ) && ! empty( $product_data['id'] ) && is_numeric( $product_data['id'] ) ) {
                if ( isset( $product_data['type'] ) && $product_data['type'] === 'variable' ) {
                    $related_variations = array_filter( $variations_data, function( $var ) use ( $product_data ) {
                        return isset( $var['parent_id'] ) && $var['parent_id'] == $product_data['id'];
                    } );
                    $result = self::update_existing_variable_product( $product_data, $related_variations );
                }else {
                    $result = self::update_existing_product( $product_data );
                }
            } else {
                if ( isset( $product_data['type'] ) && $product_data['type'] === 'variable' ) {
                    $related_variations = array_filter( $variations_data, function( $var ) use ( $product_data ) {
                        return isset( $var['parent_id'] ) && $var['parent_id'] == ( isset( $product_data['id'] ) ? $product_data['id'] : 0 );
                    } );
                    $result = self::create_new_variable_product( $product_data, $related_variations );
                } else {
                    $result = self::create_new_product( $product_data );
                }
            }

            if ( is_wp_error( $result ) ) {
                $processed_products[] = $product_data;
            } else {
                if ( $result['status'] === 'created' ) {
                    $created_products[] = $result['product'];
                } elseif ( $result['status'] === 'updated' ) {
                    $updated_products[] = $result['product'];
                }
            }
        }

        if ( empty( $processed_products ) ) {
            return new \WP_Error( 'no_products_processed', 'No products were created or updated.', [ 'status' => 400 ] );
        }

        return rest_ensure_response( [
            'status'  => 'success',
            'message' => sprintf( 
                __( '%d products processed successfully (%d created, %d updated).', 'sheets-api' ),
                count( $processed_products ),
                count( $created_products ),
                count( $updated_products )
            ),
            'data'    => [
                'processed' => $processed_products,
                'created'   => $created_products,
                'updated'   => $updated_products,
            ],
        ] );
    }

    public static function create_new_variable_product($product_data, $related_variations = []) {
        $product = new \WC_Product_Variable();

        // Validate basic product data structure
        if ( ! is_array( $product_data ) ) {
            return new \WP_Error( 'invalid_product_data', 'Invalid product data structure.', [ 'status' => 400 ] );
        }

        // Validate required fields for new products
        if ( ! isset( $product_data['name'] ) || empty( trim( $product_data['name'] ) ) ) {
            return new \WP_Error( 'missing_name', 'Product name is required for new products.', [ 'status' => 400 ] );
        }

        // Check for basic required data for new products
        $has_basic_data = (
            isset( $product_data['name'] ) && ! empty( trim( $product_data['name'] ) )
        );

        // Update/Set product fields if provided (only specified fields)
        if ( isset( $product_data['name'] ) ) {
            $product->set_name( sanitize_text_field( $product_data['name'] ) );
        }
        if ( isset( $product_data['attributes'] ) ) {
            // Example input: "size:Large|Small, color:Red|Blue"
            $attribute_string = sanitize_text_field( $product_data['attributes'] );
            self::create_attributes_for_variable_product($product, $attribute_string);
        }

        // Set status for new products
        $product->set_status( 'publish' );
        // Save the product
        $product_id = $product->save();
        //get new product data
        $product = wc_get_product( $product_id );
        $product_data = self::format_product_data( $product );

        $variation_result = self::create_variations_for_variable_product( $product_id, $related_variations );
        
        $result = [
            'status'  => 'created',
            'product' => $product_data,
        ];
        return $result;
    }

    public static function create_attributes_for_variable_product($product, $attributes_string = '' ) {
        $parsed_attributes = [];
        foreach ( explode( ',', $attributes_string ) as $attr ) {
            $attr = trim( $attr );
            if ( ! $attr ) continue;

            list( $name, $values ) = array_map( 'trim', explode( ':', $attr ) );
            $values_array = array_map( 'trim', explode( '|', $values ) );
            $parsed_attributes[ $name ] = $values_array;
        }

        $product_attributes = [];

        foreach ( $parsed_attributes as $attr_name => $options ) {
            $taxonomy = 'pa_' . sanitize_title( $attr_name );

            // Create taxonomy if it doesn't exist
            if ( ! taxonomy_exists( $taxonomy ) ) {
                register_taxonomy(
                    $taxonomy,
                    'product',
                    [
                        'label'        => ucfirst( $attr_name ),
                        'public'       => false,
                        'hierarchical' => false,
                        'show_ui'      => false,
                        'query_var'    => true,
                        'rewrite'      => false,
                    ]
                );
            }

            // Ensure terms exist
            foreach ( $options as $option ) {
                if ( ! term_exists( $option, $taxonomy ) ) {
                    wp_insert_term( $option, $taxonomy );
                }
            }

            // Assign terms to the product
            wp_set_object_terms( $product->get_id(), $options, $taxonomy );

            // ✅ Build attribute object with assigned values
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
            $attribute->set_name( $taxonomy );
            $attribute->set_options( $options );
            $attribute->set_visible( true );
            $attribute->set_variation( true );

            $product_attributes[ $taxonomy ] = $attribute;
        }

        // ✅ Assign and save attributes to the product
        $product->set_attributes( $product_attributes );
        $product->save();
    }

    public static function create_variations_for_variable_product($parent_id, $variations_data) {
        $created_variations = [];
        $create_variations = [];
        $update_variations = [];

        // Helper to parse attributes string into array
        $parse_attributes = function($variation_data) {
            $variation_attrs = [];
            $variation_string = isset($variation_data['attributes']) ? sanitize_text_field($variation_data['attributes']) : '';
            foreach (explode(',', $variation_string) as $attr) {
                $attr = trim($attr);
                if (!$attr) continue;
                $parts = array_map('trim', explode(':', $attr, 2));
                if (count($parts) === 2) {
                    $variation_attrs[$parts[0]] = $parts[1];
                }
            }
            return $variation_attrs;
        };

        // Helper to format attributes for WooCommerce
        $format_attributes = function($attributes) {
            $formatted = [];
            foreach ($attributes as $attr_name => $attr_value) {
                $taxonomy = 'pa_' . sanitize_title($attr_name);
                $term = get_term_by('name', $attr_value, $taxonomy);
                if ($term) {
                    $formatted[$taxonomy] = $term->slug;
                } else {
                    $formatted[$attr_name] = $attr_value;
                }
            }
            return $formatted;
        };

        foreach ($variations_data as $variation_data) {
            $variation_entry = [
                'id'            => isset($variation_data['id']) && is_numeric($variation_data['id']) ? intval($variation_data['id']) : null,
                'attributes'    => $parse_attributes($variation_data),
                'regular_price' => $variation_data['regular_price'] ?? '',
                'sale_price'    => $variation_data['sale_price'] ?? ''
            ];
            if ($variation_entry['id']) {
                $update_variations[] = $variation_entry;
            } else {
                $create_variations[] = $variation_entry;
            }
        }

        // Create new variations
        foreach ($create_variations as $variation_info) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_attributes($format_attributes($variation_info['attributes']));

            // Set prices if provided
            if (!empty($variation_info['regular_price'])) {
                $variation->set_regular_price((float) $variation_info['regular_price']);
            }
            if (!empty($variation_info['sale_price'])) {
                $variation->set_sale_price((float) $variation_info['sale_price']);
            }

            $variation->set_status('publish');
            $variation_id = $variation->save();
            $created_variations[] = self::format_product_data(wc_get_product($variation_id));
        }

        // Update existing variations
        foreach ($update_variations as $variation_info) {
            $variation = wc_get_product($variation_info['id']);
            if (!$variation || !$variation->is_type('variation')) {
                continue;
            }
            $variation->set_attributes($format_attributes($variation_info['attributes']));

            if (!empty($variation_info['regular_price'])) {
                $variation->set_regular_price((float) $variation_info['regular_price']);
            }
            if (!empty($variation_info['sale_price'])) {
                $variation->set_sale_price((float) $variation_info['sale_price']);
            }

            $variation_id = $variation->save();
            $created_variations[] = self::format_product_data(wc_get_product($variation_id));
        }

        return $created_variations;
    }

    /**
     * Create New Product
     */

    public static function create_new_product($product_data) {
        $product = null;

        // Validate basic product data structure
        if ( ! is_array( $product_data ) ) {
            return new \WP_Error( 'invalid_product_data', 'Invalid product data structure.', [ 'status' => 400 ] );
        }

        // Validate required fields for new products
        if ( ! isset( $product_data['name'] ) || empty( trim( $product_data['name'] ) ) ) {
            return new \WP_Error( 'missing_name', 'Product name is required for new products.', [ 'status' => 400 ] );
        }

        // Check for basic required data for new products
        $has_basic_data = (
            isset( $product_data['name'] ) && ! empty( trim( $product_data['name'] ) )
        );

        // Optional: Check for additional recommended fields
        $has_recommended_data = (
            isset( $product_data['price'] ) || 
            isset( $product_data['regular_price'] ) ||
            isset( $product_data['description'] ) ||
            isset( $product_data['short_description'] )
        );

        // Skip if basic data is missing
        if ( ! $has_basic_data ) {
            return new \WP_Error( 'insufficient_data', 'Insufficient data to create product.', [ 'status' => 400 ] );
        }

        // Determine product type and create appropriate product object
        $product_type = isset( $product_data['type'] ) ? sanitize_text_field( $product_data['type'] ) : 'simple';
        
        switch ( $product_type ) {
            case 'variable':
                $product = new \WC_Product_Variable();
                break;
            case 'variation':
                $product = new \WC_Product_Variation();
                // Set parent ID if provided
                if ( isset( $product_data['parent_id'] ) && ! empty( $product_data['parent_id'] ) ) {
                    $product->set_parent_id( intval( $product_data['parent_id'] ) );
                }
                break;
            case 'grouped':
                $product = new \WC_Product_Grouped();
                break;
            case 'external':
                $product = new \WC_Product_External();
                break;
            case 'simple':
            default:
                $product = new \WC_Product_Simple();
                break;
        }
        // Update/Set product fields if provided (only specified fields)
        if ( isset( $product_data['name'] ) ) {
            $product->set_name( sanitize_text_field( $product_data['name'] ) );
        }
        if ( isset( $product_data['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $product_data['sku'] ) );
        }
        if ( isset( $product_data['regular_price'] ) && ! empty( $product_data['regular_price'] ) ) {
            $product->set_regular_price( floatval( $product_data['regular_price'] ) );
        }
        if ( isset( $product_data['sale_price'] ) && ! empty( $product_data['sale_price'] ) ) {
            $product->set_sale_price( floatval( $product_data['sale_price'] ) );
        }
        if ( isset( $product_data['stock'] ) && ! empty( $product_data['stock'] ) ) {
            $product->set_stock_quantity( intval( $product_data['stock'] ) );
        }
        if ( isset( $product_data['status'] ) ) {
            $product->set_status( sanitize_text_field( $product_data['status'] ) );
        }
        if ( isset( $product_data['parent_id'] ) && ! empty( $product_data['parent_id'] ) ) {
            $product->set_parent_id( intval( $product_data['parent_id'] ) );
        }
        // Set status for new products
        $product->set_status( 'publish' );
        // Save the product
        $product_id = $product->save();
        //get new product data
        $product = wc_get_product( $product_id );
        $product_data = self::format_product_data( $product );

        $result = [
            'status'  => 'created',
            'product' => $product_data,
        ];
        return $result;
    }

    public static function update_existing_product($product_data) {
        $product = null;

        // Validate basic product data structure
        if ( ! is_array( $product_data ) ) {
            return new \WP_Error( 'invalid_product_data', 'Invalid product data structure.', [ 'status' => 400 ] );
        }

        // Check if product ID is provided and exists
        if ( isset( $product_data['id'] ) && ! empty( $product_data['id'] ) && is_numeric( $product_data['id'] ) ) {
            $product_id = intval( $product_data['id'] );
            $product = wc_get_product( $product_id );
        }

        if ( ! $product ) {
            return new \WP_Error( 'product_not_found', 'Product not found.', [ 'status' => 404 ] );
        }

        // Update/Set product fields if provided (only specified fields)
        if ( isset( $product_data['name'] ) ) {
            $product->set_name( sanitize_text_field( $product_data['name'] ) );
        }
        if ( isset( $product_data['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $product_data['sku'] ) );
        }
        if ( isset( $product_data['regular_price'] ) && ! empty( $product_data['regular_price'] ) ) {
            $product->set_regular_price( floatval( $product_data['regular_price'] ) );
        }
        if ( isset( $product_data['sale_price'] ) && ! empty( $product_data['sale_price'] ) ) {
            $product->set_sale_price( floatval( $product_data['sale_price'] ) );
        }
        if ( isset( $product_data['stock'] ) && ! empty( $product_data['stock'] ) ) {
            $product->set_stock_quantity( intval( $product_data['stock'] ) );
        }
        if ( isset( $product_data['status'] ) ) {
            $product->set_status( sanitize_text_field( $product_data['status'] ) );
        }
        if ( isset( $product_data['parent_id'] ) && ! empty( $product_data['parent_id'] ) ) {
            $product->set_parent_id( intval( $product_data['parent_id'] ) );
        }

        // Save the product
        $product_id = $product->save();

        //get new product data
        $product = wc_get_product( $product_id );
        $product_data = self::format_product_data( $product );

        $result = [
            'status'  => 'updated',
            'product' => $product_data,
        ];

        return $result;
    }

    public static function update_existing_variable_product($product_data, $related_variations = []) {
        $result = self::update_existing_product( $product_data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $parent_id = isset( $product_data['id'] ) ? intval( $product_data['id'] ) : 0;
        $variation_result = self::create_variations_for_variable_product( $parent_id, $related_variations );

        return $result;
    }

    /**
     * Update Products Endpoint
     */
    public static function update_products_old( \WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $sheet_token = isset( $headers['x_sheet_token'][0] ) ? $headers['x_sheet_token'][0] : '';
        $sheet_id = Sheets_API_Plugin::decrypt_sheet_id( $sheet_token );
        if ( $sheet_id !== Sheets_API_Plugin::get_sheet_id() ) {
            return new \WP_Error( 'invalid_sheet_id', 'Invalid Sheet ID.', [ 'status' => 403 ] );
        }

        $products = $request->get_param( 'products' );

        if ( ! is_array( $products ) ) {
            return new \WP_Error( 'invalid_data', 'Products data must be an array.', [ 'status' => 400 ] );
        }

        $processed_products = [];
        $created_products = [];
        $updated_products = [];

        foreach ( $products as $product_data ) {
            $product = null;
            $is_new_product = false;

            // Validate basic product data structure
            if ( ! is_array( $product_data ) ) {
                continue; // Skip invalid product data
            }

            // Check if product ID is provided and exists
            if ( isset( $product_data['id'] ) && ! empty( $product_data['id'] ) && is_numeric( $product_data['id'] ) ) {
                $product_id = intval( $product_data['id'] );
                $product = wc_get_product( $product_id );
            }

            // If product doesn't exist or no ID provided, create a new one
            if ( ! $product ) {
                $is_new_product = true;
                
                // Validate required fields for new products
                if ( ! isset( $product_data['name'] ) || empty( trim( $product_data['name'] ) ) ) {
                    continue; // Skip if no name is provided for new product
                }

                // Check for basic required data for new products
                $has_basic_data = (
                    isset( $product_data['name'] ) && ! empty( trim( $product_data['name'] ) )
                );

                // Optional: Check for additional recommended fields
                $has_recommended_data = (
                    isset( $product_data['price'] ) || 
                    isset( $product_data['regular_price'] ) ||
                    isset( $product_data['description'] ) ||
                    isset( $product_data['short_description'] )
                );

                // Skip if basic data is missing
                if ( ! $has_basic_data ) {
                    continue;
                }

                // Determine product type and create appropriate product object
                $product_type = isset( $product_data['type'] ) ? sanitize_text_field( $product_data['type'] ) : 'simple';
                
                switch ( $product_type ) {
                    case 'variable':
                        $product = new \WC_Product_Variable();
                        break;
                    case 'variation':
                        $product = new \WC_Product_Variation();
                        // Set parent ID if provided
                        if ( isset( $product_data['parent_id'] ) && ! empty( $product_data['parent_id'] ) ) {
                            $product->set_parent_id( intval( $product_data['parent_id'] ) );
                        }
                        break;
                    case 'grouped':
                        $product = new \WC_Product_Grouped();
                        break;
                    case 'external':
                        $product = new \WC_Product_External();
                        break;
                    case 'simple':
                    default:
                        $product = new \WC_Product_Simple();
                        break;
                }
            }

            // Update/Set product fields if provided (only specified fields)
            if ( isset( $product_data['name'] ) ) {
                $product->set_name( sanitize_text_field( $product_data['name'] ) );
            }
            if ( isset( $product_data['sku'] ) ) {
                $product->set_sku( sanitize_text_field( $product_data['sku'] ) );
            }
            if ( isset( $product_data['regular_price'] ) && ! empty( $product_data['regular_price'] ) ) {
                $product->set_regular_price( floatval( $product_data['regular_price'] ) );
            }
            if ( isset( $product_data['sale_price'] ) && ! empty( $product_data['sale_price'] ) ) {
                $product->set_sale_price( floatval( $product_data['sale_price'] ) );
            }
            if ( isset( $product_data['stock'] ) && ! empty( $product_data['stock'] ) ) {
                $product->set_stock_quantity( intval( $product_data['stock'] ) );
            }
            if ( isset( $product_data['status'] ) ) {
                $product->set_status( sanitize_text_field( $product_data['status'] ) );
            }
            if ( isset( $product_data['parent_id'] ) && ! empty( $product_data['parent_id'] ) ) {
                $product->set_parent_id( intval( $product_data['parent_id'] ) );
            }

            // Set status for new products
            if ( $is_new_product ) {
                $product->set_status( 'publish' );
            }

            // Save the product
            $product_id = $product->save();
            
            if ( $product_id ) {
                // Handle attributes if provided in 'Color:Red, Size:M' format
                if ( isset( $product_data['attributes'] ) && ! empty( $product_data['attributes'] ) ) {
                    $attributes_string = sanitize_text_field( $product_data['attributes'] );
                    
                    if ( $product->get_type() === 'variation' ) {
                        // For variations, set variation attributes
                        $variation_attributes = [];
                        $attribute_pairs = array_map( 'trim', explode( ',', $attributes_string ) );
                        
                        foreach ( $attribute_pairs as $pair ) {
                            if ( strpos( $pair, ':' ) !== false ) {
                                list( $attr_name, $attr_value ) = array_map( 'trim', explode( ':', $pair, 2 ) );
                                
                                if ( ! empty( $attr_name ) && ! empty( $attr_value ) ) {
                                    // Convert to WooCommerce attribute format
                                    $attr_key = 'attribute_pa_' . sanitize_title( strtolower( $attr_name ) );
                                    $variation_attributes[ $attr_key ] = sanitize_title( strtolower( $attr_value ) );
                                }
                            }
                        }
                        
                        if ( ! empty( $variation_attributes ) ) {
                            foreach ( $variation_attributes as $key => $value ) {
                                update_post_meta( $product->get_id(), $key, $value );
                            }
                        }
                    } else {
                        // For parent products, set product attributes
                        $attribute_pairs = array_map( 'trim', explode( ',', $attributes_string ) );
                        $attributes = [];
                        $grouped_attributes = [];
                        
                        // Parse each attribute pair (e.g., 'Color:Red')
                        foreach ( $attribute_pairs as $pair ) {
                            if ( strpos( $pair, ':' ) !== false ) {
                                list( $attr_name, $attr_value ) = array_map( 'trim', explode( ':', $pair, 2 ) );
                                
                                if ( ! empty( $attr_name ) && ! empty( $attr_value ) ) {
                                    if ( ! isset( $grouped_attributes[ $attr_name ] ) ) {
                                        $grouped_attributes[ $attr_name ] = [];
                                    }
                                    $grouped_attributes[ $attr_name ][] = $attr_value;
                                }
                            }
                        }
                        
                        // Create WC_Product_Attribute objects
                        $position = 0;
                        foreach ( $grouped_attributes as $attr_name => $attr_values ) {
                            $attribute = new \WC_Product_Attribute();
                            $attribute->set_name( sanitize_title( $attr_name ) );
                            $attribute->set_options( array_unique( $attr_values ) );
                            $attribute->set_position( $position++ );
                            $attribute->set_visible( true );
                            $attribute->set_variation( $product->get_type() === 'variable' );
                            $attributes[ sanitize_title( $attr_name ) ] = $attribute;
                        }
                        
                        if ( ! empty( $attributes ) ) {
                            $product->set_attributes( $attributes );
                        }
                    }
                }

                $processed_products[] = $product_id;
                
                if ( $is_new_product ) {
                    $created_products[] = $product_id;
                } else {
                    $updated_products[] = $product_id;
                }
            }
        }

        if ( empty( $processed_products ) ) {
            return new \WP_Error( 'no_products_processed', 'No products were created or updated.', [ 'status' => 400 ] );
        }

        return rest_ensure_response( [
            'status'  => 'success',
            'message' => sprintf( 
                __( '%d products processed successfully (%d created, %d updated).', 'sheets-api' ),
                count( $processed_products ),
                count( $created_products ),
                count( $updated_products )
            ),
            'data'    => [
                'processed' => $processed_products,
                'created'   => $created_products,
                'updated'   => $updated_products,
            ],
        ] );
    }

    
}