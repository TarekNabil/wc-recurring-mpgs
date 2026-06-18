<?php

/**
 * WooCommerce gateway implementation.
 *
 * @package MPFW
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main WooCommerce gateway class.
 */
class MPFW_Gateway extends WC_Payment_Gateway {

    /**
     * Debug logging flag.
     *
     * @var bool
     */
    protected $debug_mode = false;

    /**
     * WooCommerce logger.
     *
     * @var WC_Logger|null
     */
    protected $logger = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                 = 'merchant_payments';
        $this->method_title       = __( 'Merchant Payments', 'merchant-payments-for-woocommerce' );
        $this->method_description = __( 'Hosted checkout and recurring payments foundation.', 'merchant-payments-for-woocommerce' );
        $this->has_fields         = false;
        $this->supports           = array( 'products', 'refunds' );
        $this->icon               = apply_filters( 'mpfw_icon', '' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled', 'no' );
        $this->debug_mode  = 'yes' === $this->get_option( 'debug_mode', 'no' );
        $this->logger      = wc_get_logger();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_action( 'woocommerce_api_mpfw_gateway', array( $this, 'process_response' ) );
    }

    /**
     * Define settings fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'               => array(
                'title'   => __( 'Enable/Disable', 'merchant-payments-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Merchant Payments', 'merchant-payments-for-woocommerce' ),
                'default' => 'no',
            ),
            'title'                 => array(
                'title'       => __( 'Title', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => __( 'Credit Card', 'merchant-payments-for-woocommerce' ),
                'desc_tip'    => true,
                'description' => __( 'Title shown to customers during checkout.', 'merchant-payments-for-woocommerce' ),
            ),
            'description'           => array(
                'title'       => __( 'Description', 'merchant-payments-for-woocommerce' ),
                'type'        => 'textarea',
                'default'     => __( 'Pay securely through the hosted checkout page.', 'merchant-payments-for-woocommerce' ),
                'desc_tip'    => true,
                'description' => __( 'Description shown to customers during checkout.', 'merchant-payments-for-woocommerce' ),
            ),
            'debug_mode'            => array(
                'title'       => __( 'Debug Log', 'merchant-payments-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable debug logging', 'merchant-payments-for-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'Log gateway activity to WooCommerce logs.', 'merchant-payments-for-woocommerce' ),
            ),
            'service_host'          => array(
                'title'       => __( 'Service Host', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => true,
                'description' => __( 'Base gateway host URL, including a trailing slash.', 'merchant-payments-for-woocommerce' ),
            ),
            'merchant_id'           => array(
                'title'       => __( 'Merchant ID', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Merchant identifier provided by the active payment provider.', 'merchant-payments-for-woocommerce' ),
            ),
            'authentication_password' => array(
                'title'       => __( 'API Password', 'merchant-payments-for-woocommerce' ),
                'type'        => 'password',
                'default'     => '',
                'description' => __( 'Integration API password generated in the provider portal.', 'merchant-payments-for-woocommerce' ),
            ),
            'checkout_api_version'  => array(
                'title'       => __( 'Checkout API Version', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '100',
                'description' => __( 'API version for hosted checkout CIT flows.', 'merchant-payments-for-woocommerce' ),
            ),
            'recurring_api_version' => array(
                'title'       => __( 'Recurring API Version', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '100',
                'description' => __( 'API version for MIT recurring charges.', 'merchant-payments-for-woocommerce' ),
            ),
            'merchant_name'         => array(
                'title'       => __( 'Merchant Name', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Merchant name shown on hosted checkout.', 'merchant-payments-for-woocommerce' ),
            ),
            'merchant_address1'     => array(
                'title'       => __( 'Merchant Address Line 1', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'merchant_address2'     => array(
                'title'       => __( 'Merchant Address Line 2', 'merchant-payments-for-woocommerce' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'recurring_enabled'     => array(
                'title'       => __( 'Recurring Payments', 'merchant-payments-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable recurring architecture scaffolding', 'merchant-payments-for-woocommerce' ),
                'default'     => 'no',
                'description' => __( 'This only enables the new recurring configuration path. Actual MIT charging is not implemented in this first scaffold.', 'merchant-payments-for-woocommerce' ),
            ),
        );
    }

    /**
     * Enqueue hosted checkout assets.
     *
     * @return void
     */
    public function payment_scripts() {
        if ( ! is_checkout() && ! is_checkout_pay_page() && ! isset( $_GET['sessionId'] ) ) {
            return;
        }

        if ( 'yes' !== $this->enabled ) {
            return;
        }

        $session_id = isset( $_GET['sessionId'] ) ? sanitize_text_field( wp_unslash( $_GET['sessionId'] ) ) : '';

        if ( ! $session_id ) {
            return;
        }

        wp_enqueue_script(
            'mpfw-hosted-checkout',
            MPFW_PLUGIN_URL . 'assets/js/checkout.js',
            array(),
            MPFW_VERSION,
            true
        );

        wp_localize_script(
            'mpfw-hosted-checkout',
            'mpfwCheckoutConfig',
            array(
                'sessionId' => $session_id,
            )
        );
    }

    /**
     * Create the hosted checkout session and redirect to the pay page.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Invalid order.', 'merchant-payments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        if ( ! $this->has_required_credentials() ) {
            wc_add_notice( __( 'Gateway credentials are incomplete.', 'merchant-payments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $response = $this->get_hosted_checkout_service()->create_checkout_session( $order );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Hosted checkout session creation failed: ' . $response->get_error_message(), 'error' );
            wc_add_notice( __( 'Failed to create the hosted checkout session.', 'merchant-payments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || 'SUCCESS' !== ( $body['result'] ?? '' ) || empty( $body['session']['id'] ) ) {
            $message = $body['error']['explanation'] ?? __( 'Unexpected gateway response.', 'merchant-payments-for-woocommerce' );
            $this->log( 'Hosted checkout session rejected: ' . wp_json_encode( $body ), 'error' );
            wc_add_notice( $message, 'error' );
            return array( 'result' => 'failure' );
        }

        $order->update_meta_data( '_mpfw_success_indicator', $body['successIndicator'] ?? '' );
        $order->update_meta_data( '_mpfw_session_version', $body['session']['version'] ?? '' );
        $order->save();

        return array(
            'result'   => 'success',
            'redirect' => add_query_arg(
                array(
                    'sessionId' => $body['session']['id'],
                    'key'       => $order->get_order_key(),
                ),
                $order->get_checkout_payment_url()
            ),
        );
    }

    /**
     * Placeholder callback handler.
     *
     * @return void
     */
    public function process_response() {
        wp_die( esc_html__( 'Gateway callback handling is not implemented in this scaffold yet.', 'merchant-payments-for-woocommerce' ) );
    }

    /**
     * Placeholder refund handler.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount Refund amount.
     * @param string     $reason Reason.
     * @return WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return new WP_Error(
            'mpfw_refund_not_implemented',
            __( 'Refund support is not implemented in this scaffold yet.', 'merchant-payments-for-woocommerce' )
        );
    }

    /**
     * Output the receipt screen placeholder.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function receipt_page( $order_id ) {
        if ( empty( $_GET['sessionId'] ) ) {
            wc_add_notice( __( 'Payment session not found.', 'merchant-payments-for-woocommerce' ), 'error' );
            return;
        }

        echo '<p>' . esc_html__( 'Redirecting to the hosted checkout page.', 'merchant-payments-for-woocommerce' ) . '</p>';
    }

    /**
     * Check credentials presence.
     *
     * @return bool
     */
    protected function has_required_credentials() {
        return (bool) ( $this->get_option( 'service_host' ) && $this->get_option( 'merchant_id' ) && $this->get_option( 'authentication_password' ) );
    }

    /**
     * Build the hosted checkout service.
     *
    * @return MPFW_Hosted_Checkout_Service
     */
    protected function get_hosted_checkout_service() {
        return new MPFW_Hosted_Checkout_Service(
            new MPFW_Api_Client(
                $this->get_option( 'service_host' ),
                $this->get_option( 'merchant_id' ),
                $this->get_option( 'authentication_password' )
            ),
            array(
                'checkout_api_version' => $this->get_option( 'checkout_api_version', '100' ),
                'merchant_name'        => $this->get_option( 'merchant_name', '' ),
                'merchant_address1'    => $this->get_option( 'merchant_address1', '' ),
                'merchant_address2'    => $this->get_option( 'merchant_address2', '' ),
            )
        );
    }

    /**
     * Write a debug log entry when enabled.
     *
     * @param string $message Message.
     * @param string $level Log level.
     * @return void
     */
    protected function log( $message, $level = 'info' ) {
        if ( $this->debug_mode && $this->logger ) {
            $this->logger->log( $level, $message, array( 'source' => 'merchant-payments-for-woocommerce' ) );
        }
    }
}