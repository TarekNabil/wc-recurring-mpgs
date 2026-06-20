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
     * Initialize gateway settings.
     *
     * @return void
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_merchant_payments_settings', array() );
    }

    /**
     * Check whether the gateway is active.
     *
     * @return bool
     */
    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
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