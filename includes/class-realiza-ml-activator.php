<?php

/**
 * Fired during plugin activation
 */

class Realiza_ML_Activator {

	public static function activate() {
        // Create database tables if needed in the future
        // Check for WooCommerce dependency
        if ( ! class_exists( 'WooCommerce' ) ) {
            // Maybe add an admin notice or deactivate self if Woo is strictly required immediately
        }
	}

}
