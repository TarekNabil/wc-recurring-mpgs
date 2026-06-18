<?php

/**
 * Plugin Name: WC Recurring MPGS
 * Plugin URI: https://example.com/
 * Description: WC Recurring MPGS (MasterCard Payment Gateway Services for WooCommerce) with hosted checkout and recurring payments foundation.
 * Version: 0.1.0
 * Author: Tarek
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-recurring-mpgs
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 * Requires Plugins: woocommerce
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCRMPGS_VERSION', '0.1.0' );
define( 'WCRMPGS_PLUGIN_FILE', __FILE__ );
define( 'WCRMPGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCRMPGS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        }
    }
);

require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-plugin.php';

add_action(
    'plugins_loaded',
    static function () {
        WCRMPGS_Plugin::instance()->boot();
    }
);