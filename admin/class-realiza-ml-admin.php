<?php

/**
 * The admin-specific functionality of the plugin.
 */

class Realiza_ML_Admin {

	private $plugin_name;
	private $version;
    private $api;

	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-realiza-ml-api.php';
        $this->api = new Realiza_ML_API();

	}

	public function enqueue_styles() {
		// wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/realiza-ml-admin.css', array(), $this->version, 'all' );
	}

	public function enqueue_scripts() {
		// wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/realiza-ml-admin.js', array( 'jquery' ), $this->version, false );
	}

    public function add_plugin_admin_menu() {
        add_menu_page(
            'Realiza ML', 
            'Realiza ML', 
            'manage_options', 
            'realiza-ml', 
            array( $this, 'display_plugin_setup_page' ),
            'dashicons-cart',
            56
        );
    }

    public function register_settings() {
        register_setting( 'realiza_ml_options', 'realiza_ml_app_id' );
        register_setting( 'realiza_ml_options', 'realiza_ml_secret_key' );
        register_setting( 'realiza_ml_options', 'realiza_ml_access_token' );
        register_setting( 'realiza_ml_options', 'realiza_ml_refresh_token' );
        register_setting( 'realiza_ml_options', 'realiza_ml_expires_in' );
        register_setting( 'realiza_ml_options', 'realiza_ml_user_id' );
    }

    public function display_plugin_setup_page() {
        // Check for OAuth return
        if ( isset( $_GET['code'] ) && isset( $_GET['page'] ) && $_GET['page'] === 'realiza-ml' ) {
            $this->handle_oauth_callback( $_GET['code'] );
        }

        include_once 'partials/realiza-ml-admin-display.php';
    }

    private function handle_oauth_callback( $code ) {
        $response = $this->api->exchange_code( $code );
        
        if ( is_wp_error( $response ) ) {
            add_settings_error( 'realiza_ml_messages', 'realiza_ml_message', 'Erro ao conectar com Mercado Livre: ' . $response->get_error_message(), 'error' );
        } else {
            update_option( 'realiza_ml_access_token', $response['access_token'] );
            update_option( 'realiza_ml_refresh_token', $response['refresh_token'] );
            update_option( 'realiza_ml_expires_in', time() + $response['expires_in'] );
            update_option( 'realiza_ml_user_id', $response['user_id'] );
            
            add_settings_error( 'realiza_ml_messages', 'realiza_ml_message', 'Conectado com sucesso ao Mercado Livre!', 'updated' );
        }
    }

}
