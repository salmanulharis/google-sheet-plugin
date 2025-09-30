<?php

class Sheets_API_Routes {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
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

    public static function update_products( \WP_REST_Request $request ) {
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
                $product_type = isset( $product_data['product_type'] ) ? sanitize_text_field( $product_data['product_type'] ) : 'simple';
                
                switch ( $product_type ) {
                    case 'variable':
                        $product = new \WC_Product_Variable();
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

            // Update/Set product fields if provided
            if ( isset( $product_data['name'] ) ) {
                $product->set_name( sanitize_text_field( $product_data['name'] ) );
            }
            if ( isset( $product_data['description'] ) ) {
                $product->set_description( wp_kses_post( $product_data['description'] ) );
            }
            if ( isset( $product_data['short_description'] ) ) {
                $product->set_short_description( wp_kses_post( $product_data['short_description'] ) );
            }
            if ( isset( $product_data['price'] ) ) {
                $product->set_price( floatval( $product_data['price'] ) );
            }
            if ( isset( $product_data['regular_price'] ) ) {
                $product->set_regular_price( floatval( $product_data['regular_price'] ) );
            }
            if ( isset( $product_data['sale_price'] ) && ! empty( $product_data['sale_price'] ) ) {
                $product->set_sale_price( floatval( $product_data['sale_price'] ) );
            }
            if ( isset( $product_data['stock_quantity'] ) && ! empty( $product_data['stock_quantity'] ) ) {
                $product->set_stock_quantity( intval( $product_data['stock_quantity'] ) );
            }
            if ( isset( $product_data['stock_status'] ) ) {
                $product->set_stock_status( sanitize_text_field( $product_data['stock_status'] ) );
            }
            if ( isset( $product_data['sku'] ) ) {
                $product->set_sku( sanitize_text_field( $product_data['sku'] ) );
            }

            // Set status for new products
            if ( $is_new_product ) {
                $product->set_status( 'publish' );
            }

            // Save the product
            $product_id = $product->save();
            
            if ( $product_id ) {
                // Handle categories if provided
                if ( isset( $product_data['categories'] ) && is_array( $product_data['categories'] ) ) {
                    $category_ids = [];
                    foreach ( $product_data['categories'] as $category_name ) {
                        $term = get_term_by( 'name', $category_name, 'product_cat' );
                        if ( ! $term ) {
                            // Create category if it doesn't exist
                            $term = wp_insert_term( $category_name, 'product_cat' );
                            if ( ! is_wp_error( $term ) ) {
                                $category_ids[] = $term['term_id'];
                            }
                        } else {
                            $category_ids[] = $term->term_id;
                        }
                    }
                    if ( ! empty( $category_ids ) ) {
                        wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
                    }
                }

                // Handle tags if provided
                if ( isset( $product_data['tags'] ) && is_array( $product_data['tags'] ) ) {
                    wp_set_object_terms( $product_id, $product_data['tags'], 'product_tag' );
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

    /**
     * Get Products Endpoint
     */
    public static function get_products( \WP_REST_Request $request ) {
        // Debug log
        if ( function_exists( 'write_log' ) ) {
            write_log('Get Products API called');
        }
        
        $products = wc_get_products( array(
            'limit' => -1,
        ) );

        $products_data = [];

        foreach ( $products as $product ) {
            $post = get_post( $product->get_id() );
            $categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
            $tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
            $featured_image = get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' );

            $products_data[] = [
                'id'                => $product->get_id(),
                'name'              => $post->post_title,
                'description'       => $post->post_content,
                'short_description' => $post->post_excerpt,
                'product_type'      => $product->get_type(),
                'price'             => $product->get_price(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'stock_quantity'    => $product->get_stock_quantity(),
                'stock_status'      => $product->get_stock_status(),
                'sku'               => $product->get_sku(),
                'categories'        => $categories,
                'tags'              => $tags,
                'featured_image'    => $featured_image,
            ];
        }

        $data = [
            'status'  => 'success',
            'message' => __( 'Here is some sample data from Sheets API plugin!', 'sheets-api' ),
            'time'    => current_time( 'mysql' ),
            'data'    => $products_data,
        ];

        return rest_ensure_response( $data );
    }
}