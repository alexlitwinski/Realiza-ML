<?php

/**
 * Define the internationalization functionality
 */

class Realiza_ML_i18n {

	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'realiza-ml',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}

}
