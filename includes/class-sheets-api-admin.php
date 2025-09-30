<?php

class Sheets_API_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'settings_init' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_options_page(
            __( 'Sheets API Settings', 'sheets-api' ),
            __( 'Sheets API', 'sheets-api' ),
            'manage_options',
            'sheets-api-settings',
            [ __CLASS__, 'settings_page' ]
        );
    }

    /**
     * Initialize settings
     */
    public static function settings_init() {
        register_setting( 'sheets_api_settings', 'sheets_api_options' );

        add_settings_section(
            'sheets_api_settings_section',
            __( 'API Configuration', 'sheets-api' ),
            [ __CLASS__, 'settings_section_callback' ],
            'sheets_api_settings'
        );

        add_settings_field(
            'sheet_id',
            __( 'Google Sheet ID', 'sheets-api' ),
            [ __CLASS__, 'sheet_id_render' ],
            'sheets_api_settings',
            'sheets_api_settings_section'
        );

        add_settings_field(
            'secret_key',
            __( 'Secret Key', 'sheets-api' ),
            [ __CLASS__, 'secret_key_render' ],
            'sheets_api_settings',
            'sheets_api_settings_section'
        );
    }

    /**
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts( $hook ) {
        if ( 'settings_page_sheets-api-settings' !== $hook ) {
            return;
        }

        wp_enqueue_script( 
            'sheets-api-admin', 
            SHEETS_API_PLUGIN_URL . 'assets/js/admin-scripts.js', 
            [], 
            SHEETS_API_VERSION, 
            true 
        );
    }

    /**
     * Settings section callback
     */
    public static function settings_section_callback() {
        echo '<p>' . __( 'Configure your Google Sheets API settings below.', 'sheets-api' ) . '</p>';
    }

    /**
     * Sheet ID field render
     */
    public static function sheet_id_render() {
        $options = get_option( 'sheets_api_options' );
        $sheet_id = isset( $options['sheet_id'] ) ? $options['sheet_id'] : '';
        ?>
        <input type="text" name="sheets_api_options[sheet_id]" value="<?php echo esc_attr( $sheet_id ); ?>" class="regular-text" />
        <p class="description"><?php _e( 'Enter your Google Sheet ID (found in the sheet URL)', 'sheets-api' ); ?></p>
        <?php
    }

    /**
     * Secret key field render
     */
    public static function secret_key_render() {
        $options = get_option( 'sheets_api_options' );
        $secret_key = isset( $options['secret_key'] ) ? $options['secret_key'] : '';
        ?>
        <div>
            <input type="text" name="sheets_api_options[secret_key]" value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text" readonly />
            <button type="button" class="button" id="generate-secret-key"><?php _e( 'Generate New Key', 'sheets-api' ); ?></button>
        </div>
        <p class="description"><?php _e( 'This secret key is used for API authentication. Click "Generate New Key" to create a new one.', 'sheets-api' ); ?></p>
        <?php
    }

    /**
     * Settings page HTML
     */
    public static function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'Sheets API Settings', 'sheets-api' ); ?></h1>
            
            <?php
            // Show success message if settings were saved
            if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'Settings saved successfully!', 'sheets-api' ); ?></p>
                </div>
                <?php
            }
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields( 'sheets_api_settings' );
                do_settings_sections( 'sheets_api_settings' );
                submit_button();
                ?>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e( 'API Endpoints', 'sheets-api' ); ?></h2>
                <p><?php _e( 'Use these endpoints to interact with your products:', 'sheets-api' ); ?></p>
                <ul>
                    <li><strong>GET Products:</strong> <code><?php echo home_url( '/wp-json/sheets-api/v1/get_products' ); ?></code></li>
                    <li><strong>POST Update Products:</strong> <code><?php echo home_url( '/wp-json/sheets-api/v1/update_products' ); ?></code></li>
                </ul>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e( 'Current Settings', 'sheets-api' ); ?></h2>
                <?php
                $options = get_option( 'sheets_api_options' );
                $sheet_id = isset( $options['sheet_id'] ) ? $options['sheet_id'] : __( 'Not set', 'sheets-api' );
                $secret_key = isset( $options['secret_key'] ) && !empty( $options['secret_key'] ) ? __( 'Set', 'sheets-api' ) : __( 'Not set', 'sheets-api' );
                ?>
                <p><strong><?php _e( 'Sheet ID:', 'sheets-api' ); ?></strong> <?php echo esc_html( $sheet_id ); ?></p>
                <p><strong><?php _e( 'Secret Key:', 'sheets-api' ); ?></strong> <?php echo esc_html( $secret_key ); ?></p>
            </div>
        </div>
        <?php
    }
}