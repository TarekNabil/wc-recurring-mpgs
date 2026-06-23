<?php

/**
 * WooCommerce Blocks support.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Checkout block adapter for the merchant gateway.
 */
final class WCRMPGS_Blocks_Support extends AbstractPaymentMethodType {

    /**
     * Payment method id.
     *
     * @var string
     */
    protected $name = 'merchant_payments';

    /**
     * Gateway instance.
     *
     * @var WCRMPGS_Gateway|null
     */
    private $gateway = null;

    /**
     * Initialize gateway settings.
     *
     * @return void
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_merchant_payments_settings', array() );

        if ( class_exists( 'WCRMPGS_Gateway' ) ) {
            $this->gateway = new WCRMPGS_Gateway();
        }
    }

    /**
     * Check whether the gateway is active.
     *
     * @return bool
     */
    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] && $this->gateway instanceof WCRMPGS_Gateway;
    }

    /**
     * Return gateway-supported features for Checkout Blocks.
     *
     * @return array
     */
    public function get_supported_features() {
        if ( ! $this->gateway instanceof WCRMPGS_Gateway ) {
            return array( 'products' );
        }

        if ( ! is_array( $this->gateway->supports ) ) {
            return array( 'products' );
        }

        return array_values( $this->gateway->supports );
    }

    /**
     * Register the client-side script handles.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wcrmpgs-blocks-integration',
            WCRMPGS_PLUGIN_URL . 'assets/js/blocks.js',
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
            WCRMPGS_VERSION,
            true
        );

        return array( 'wcrmpgs-blocks-integration' );
    }

    /**
     * Provide block-visible metadata.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->settings['title'] ?? __( 'Credit Card', 'wc-recurring-mpgs' ),
            'description' => $this->settings['description'] ?? '',
            'supports'    => $this->get_supported_features(),
        );
    }
}