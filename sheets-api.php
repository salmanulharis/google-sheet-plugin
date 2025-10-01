<?php
/**
 * Plugin Name: Sheets API
 * Description: A simple API plugin for WordPress.
 * Version:     1.2
 * Author:      Your Name
 * Text Domain: sheets-api
 *
 * @package Sheets_API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'SHEETS_API_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHEETS_API_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHEETS_API_VERSION', '1.2' );

class Sheets_API_Plugin {

    const REST_NAMESPACE = 'sheets-api/v1';

    public static function init() {
        // Load required files
        self::includes();
        
        // Initialize components
        Sheets_API_Admin::init();
        Sheets_API_Routes::init();
    }

    private static function includes() {
        require_once SHEETS_API_PLUGIN_PATH . 'includes/class-sheets-api-admin.php';
        require_once SHEETS_API_PLUGIN_PATH . 'includes/class-sheets-api-routes.php';
        require_once SHEETS_API_PLUGIN_PATH . 'includes/class-sheets-api-helper.php';
    }

    /**
     * Helper function to get sheet ID
     */
    public static function get_sheet_id() {
        $options = get_option( 'sheets_api_options' );
        return isset( $options['sheet_id'] ) ? $options['sheet_id'] : '';
    }

    /**
     * Helper function to get secret key
     */
    public static function get_secret_key() {
        $options = get_option( 'sheets_api_options' );
        return isset( $options['secret_key'] ) ? $options['secret_key'] : '';
    }

    /**
     * Decrypt sheet ID using secret key
     */
    public static function decrypt_sheet_id( $encrypted_id ) {
        $secret_key = self::get_secret_key();
        if ( empty( $secret_key ) ) {
            return '';
        }

        // Decode from Base64
        $decoded = base64_decode( $encrypted_id );
        if ( $decoded === false ) {
            return '';
        }

        // XOR decryption
        $decrypted = '';
        $key_length = strlen( $secret_key );
        for ( $i = 0; $i < strlen( $decoded ); $i++ ) {
            $decrypted .= chr(
                ord( $decoded[$i] ) ^ ord( $secret_key[$i % $key_length] )
            );
        }

        // Split into [sheet_id | timestamp]
        $parts = explode( '|', $decrypted );
        if ( count( $parts ) !== 2 ) {
            return ''; // invalid format
        }

        $sheet_id  = $parts[0];
        $timestamp = (int) $parts[1];

        // Current time in ms
        $now = (int) round(microtime(true) * 1000);

        // Allow expiry time to be filtered (default: 60,000 ms = 1 minute)
        $expiry_time = apply_filters( 'sheets_api_decrypt_expiry_time', 60000, $sheet_id, $timestamp );

        // Expiry check
        if ( $now - $timestamp > $expiry_time ) {
            return ''; // expired
        }

        return $sheet_id; // return only the key
    }


}

Sheets_API_Plugin::init();