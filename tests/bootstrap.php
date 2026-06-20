<?php
/**
 * Bootstrap for integration tests.
 *
 * wp-env exposes the WP test suite at /tmp/wordpress-tests-lib inside the
 * tests-cli container. The WP_TESTS_DIR env var overrides that path if needed.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    die(
        "ERROR: WordPress test suite not found at: {$_tests_dir}\n" .
        "Make sure you ran: npm run env:start\n"
    );
}

/**
 * Load plugin before the WP test environment boots.
 */
function _load_wcrmpgs_plugin() {
    $woocommerce_plugin = dirname( dirname( __DIR__ ) ) . '/woocommerce/woocommerce.php';

    if ( file_exists( $woocommerce_plugin ) ) {
        require_once $woocommerce_plugin;
    }

    require dirname( __DIR__ ) . '/wc-recurring-mpgs.php';
}

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
    require dirname( __DIR__ ) . '/vendor/autoload.php';
}

require $_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', '_load_wcrmpgs_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
