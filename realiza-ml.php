<?php
/**
 * Plugin Name: Realiza - ML
 * Plugin URI:  https://github.com/alexa/realiza-ml
 * Description: Integração WooCommerce com Mercado Livre.
 * Version:     1.0.0
 * Author:      Alexa
 * Author URI:  https://github.com/alexa
 * License:     GPL-2.0+
 * Text Domain: realiza-ml
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'REALIZA_ML_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 */
function activate_realiza_ml() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-realiza-ml-activator.php';
	Realiza_ML_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_realiza_ml() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-realiza-ml-deactivator.php';
	Realiza_ML_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_realiza_ml' );
register_deactivation_hook( __FILE__, 'deactivate_realiza_ml' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-realiza-ml.php';

/**
 * Begins execution of the plugin.
 */
function run_realiza_ml() {
	$plugin = new Realiza_ML();
	$plugin->run();
}
run_realiza_ml();
