<?php

/**
 * Plugin Name: Merchant Payments for WooCommerce
 * Plugin URI: https://example.com/
 * Description: Provider-neutral hosted checkout and recurring payments foundation for WooCommerce.
 * Version: 0.1.0
 * Author: Tarek
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: merchant-payments-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 * Requires Plugins: woocommerce
 *
 * @package MPFW
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MPFW_VERSION', '0.1.0' );
define( 'MPFW_PLUGIN_FILE', __FILE__ );
define( 'MPFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        }
    }
);

require_once MPFW_PLUGIN_DIR . 'includes/class-mpfw-plugin.php';

add_action(
    'plugins_loaded',
    static function () {
        MPFW_Plugin::instance()->boot();
    }
);