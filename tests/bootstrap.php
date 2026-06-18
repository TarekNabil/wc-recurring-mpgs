<?php

/**
 * Test bootstrap file for WC Recurring MPGS plugin.
 *
 * @package WCRMPGS
 */

// Ensure we're in a test environment.
if ( ! defined( 'WP_TESTS_DIR' ) ) {
	exit( 'This test suite requires WordPress to be installed.' );
}

// Load WordPress test functions.
require_once WP_TESTS_DIR . '/includes/functions.php';

// Load plugin.
tests_add_filter(
	'muplugins_loaded',
	function () {
		// Define plugin constants if not already defined.
		if ( ! defined( 'WCRMPGS_VERSION' ) ) {
			define( 'WCRMPGS_VERSION', '0.1.0' );
		}
		if ( ! defined( 'WCRMPGS_PLUGIN_FILE' ) ) {
			define( 'WCRMPGS_PLUGIN_FILE', dirname( __DIR__ ) . '/wc-recurring-mpgs.php' );
		}
		if ( ! defined( 'WCRMPGS_PLUGIN_DIR' ) ) {
			define( 'WCRMPGS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'WCRMPGS_PLUGIN_URL' ) ) {
			define( 'WCRMPGS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-recurring-mpgs/' );
		}

		// Require plugin file.
		require_once dirname( __DIR__ ) . '/wc-recurring-mpgs.php';
	}
);

// Start WordPress.
require WP_TESTS_DIR . '/includes/bootstrap.php';

// Load WooCommerce helpers if available.
if ( class_exists( 'WC_Unit_Tests_Bootstrap' ) ) {
	// WooCommerce test helpers are available.
}

// Activate necessary plugins.
activate_plugin( 'woocommerce/woocommerce.php' );
activate_plugin( 'wc-recurring-mpgs/wc-recurring-mpgs.php' );
