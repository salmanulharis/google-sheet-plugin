<?php

class Sheets_API_Helper {

    /**
     * Generate a random secret key
     */
    public static function generate_secret_key( $length = 32 ) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $result .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }
        return $result;
    }

    /**
     * Validate product data structure
     */
    public static function validate_product_data( $product_data ) {
        if ( ! is_array( $product_data ) ) {
            return false;
        }

        // Basic validation for required fields
        if ( isset( $product_data['id'] ) && ! is_numeric( $product_data['id'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize product data
     */
    public static function sanitize_product_data( $product_data ) {
        $sanitized = [];

        if ( isset( $product_data['id'] ) ) {
            $sanitized['id'] = intval( $product_data['id'] );
        }

        if ( isset( $product_data['name'] ) ) {
            $sanitized['name'] = sanitize_text_field( $product_data['name'] );
        }

        if ( isset( $product_data['description'] ) ) {
            $sanitized['description'] = wp_kses_post( $product_data['description'] );
        }

        if ( isset( $product_data['short_description'] ) ) {
            $sanitized['short_description'] = wp_kses_post( $product_data['short_description'] );
        }

        // Add more sanitization as needed...

        return $sanitized;
    }
}