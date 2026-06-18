<?php

/**
 * Plugin bootstrap.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstraps the plugin and integration entry points.
 */
final class WCRMPGS_Plugin {

    /**
     * Singleton instance.
     *
    * @var WCRMPGS_Plugin|null
     */
    private static $instance = null;

    /**
     * Returns the singleton instance.
     *
    * @return WCRMPGS_Plugin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot the plugin.
     *
     * @return void
     */
    public function boot() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
            return;
        }

        require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-api-client.php';
        require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-hosted-checkout-service.php';
        require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-gateway.php';

        add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
        add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
    }

    /**
     * Adds the gateway to WooCommerce.
     *
     * @param array $gateways Existing gateway classes.
     * @return array
     */
    public function register_gateway( $gateways ) {
        $gateways[] = 'WCRMPGS_Gateway';
        return $gateways;
    }

    /**
     * Registers the block checkout integration.
     *
     * @return void
     */
    public function register_blocks_support() {
        if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            return;
        }

        require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-blocks-support.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ( $registry ) {
                $registry->register( new WCRMPGS_Blocks_Support() );
            }
        );
    }

    /**
     * Renders the admin notice when WooCommerce is unavailable.
     *
     * @return void
     */
    public function render_missing_woocommerce_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e( 'WC Recurring MPGS requires WooCommerce to be installed and active.', 'wc-recurring-mpgs' ); ?>
            </p>
        </div>
        <?php
    }
}