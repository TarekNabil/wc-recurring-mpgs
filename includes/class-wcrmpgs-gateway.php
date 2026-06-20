<?php

/**
 * WooCommerce gateway implementation.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main WooCommerce gateway class.
 */
class WCRMPGS_Gateway extends WC_Payment_Gateway {

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
        $this->method_title       = __( 'WC Recurring MPGS', 'wc-recurring-mpgs' );
        $this->method_description = __( 'MasterCard Payment Gateway Services for WooCommerce with hosted checkout and recurring payments foundation.', 'wc-recurring-mpgs' );
        $this->has_fields         = false;
        $this->supports           = array( 'products', 'refunds' );
        $this->icon               = apply_filters( 'wcrmpgs_icon', '' );

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
        add_action( 'woocommerce_api_wcrmpgs_gateway', array( $this, 'process_response' ) );
    }

    /**
     * Define settings fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'               => array(
                'title'   => __( 'Enable/Disable', 'wc-recurring-mpgs' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable WC Recurring MPGS', 'wc-recurring-mpgs' ),
                'default' => 'no',
            ),
            'title'                 => array(
                'title'       => __( 'Title', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => __( 'WC Recurring MPGS Card', 'wc-recurring-mpgs' ),
                'desc_tip'    => true,
                'description' => __( 'Title shown to customers during checkout.', 'wc-recurring-mpgs' ),
            ),
            'description'           => array(
                'title'       => __( 'Description', 'wc-recurring-mpgs' ),
                'type'        => 'textarea',
                'default'     => __( 'Pay securely through the hosted checkout page.', 'wc-recurring-mpgs' ),
                'desc_tip'    => true,
                'description' => __( 'Description shown to customers during checkout.', 'wc-recurring-mpgs' ),
            ),
            'debug_mode'            => array(
                'title'       => __( 'Debug Log', 'wc-recurring-mpgs' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable debug logging', 'wc-recurring-mpgs' ),
                'default'     => 'no',
                'description' => __( 'Log gateway activity to WooCommerce logs.', 'wc-recurring-mpgs' ),
            ),
            'service_host'          => array(
                'title'       => __( 'Service Host', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => true,
                'description' => __( 'Base gateway host URL, including a trailing slash.', 'wc-recurring-mpgs' ),
            ),
            'merchant_id'           => array(
                'title'       => __( 'Merchant ID', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Merchant identifier provided by the active payment provider.', 'wc-recurring-mpgs' ),
            ),
            'authentication_password' => array(
                'title'       => __( 'API Password', 'wc-recurring-mpgs' ),
                'type'        => 'password',
                'default'     => '',
                'description' => __( 'Integration API password generated in the provider portal.', 'wc-recurring-mpgs' ),
            ),
            'checkout_api_version'  => array(
                'title'       => __( 'Checkout API Version', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '100',
                'description' => __( 'API version for hosted checkout CIT flows.', 'wc-recurring-mpgs' ),
            ),
            'recurring_api_version' => array(
                'title'       => __( 'Recurring API Version', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '100',
                'description' => __( 'API version for MIT recurring charges.', 'wc-recurring-mpgs' ),
            ),
            'merchant_name'         => array(
                'title'       => __( 'Merchant Name', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Merchant name shown on hosted checkout.', 'wc-recurring-mpgs' ),
            ),
            'merchant_address1'     => array(
                'title'       => __( 'Merchant Address Line 1', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'merchant_address2'     => array(
                'title'       => __( 'Merchant Address Line 2', 'wc-recurring-mpgs' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'recurring_enabled'     => array(
                'title'       => __( 'Recurring Payments', 'wc-recurring-mpgs' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable recurring architecture scaffolding', 'wc-recurring-mpgs' ),
                'default'     => 'no',
                'description' => __( 'This only enables the new recurring configuration path. Actual MIT charging is not implemented in this first scaffold.', 'wc-recurring-mpgs' ),
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
            'wcrmpgs-hosted-checkout',
            WCRMPGS_PLUGIN_URL . 'assets/js/checkout.js',
            array(),
            WCRMPGS_VERSION,
            true
        );

        wp_localize_script(
            'wcrmpgs-hosted-checkout',
            'wcrmpgsCheckoutConfig',
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
            wc_add_notice( __( 'Invalid order.', 'wc-recurring-mpgs' ), 'error' );
            return array( 'result' => 'failure' );
        }

        if ( ! $this->has_required_credentials() ) {
            $order->add_order_note( __( 'MPGS checkout session failed: gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'Gateway credentials are incomplete.', 'wc-recurring-mpgs' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $response = $this->get_hosted_checkout_service()->create_checkout_session( $order );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Hosted checkout session creation failed: ' . $response->get_error_message(), 'error' );
            $order->add_order_note( __( 'MPGS checkout session could not be created. See gateway logs for details.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'Failed to create the hosted checkout session.', 'wc-recurring-mpgs' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || 'SUCCESS' !== ( $body['result'] ?? '' ) || empty( $body['session']['id'] ) ) {
            $message = $body['error']['explanation'] ?? __( 'Unexpected gateway response.', 'wc-recurring-mpgs' );
            $this->log( 'Hosted checkout session rejected: ' . wp_json_encode( $body ), 'error' );
            $order->add_order_note( sprintf( __( 'MPGS checkout session rejected: %s', 'wc-recurring-mpgs' ), wp_strip_all_tags( (string) $message ) ) );
            wc_add_notice( $message, 'error' );
            return array( 'result' => 'failure' );
        }

        $order->update_meta_data( '_wcrmpgs_success_indicator', $body['successIndicator'] ?? '' );
        $order->update_meta_data( '_wcrmpgs_session_id', $body['session']['id'] ?? '' );
        $order->update_meta_data( '_wcrmpgs_session_version', $body['session']['version'] ?? '' );
        $order->add_order_note( sprintf( __( 'MPGS checkout session created successfully. Session ID: %s', 'wc-recurring-mpgs' ), $body['session']['id'] ) );
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
     * Handle provider callback after hosted checkout.
     *
     * @return void
     */
    public function process_response() {
        $order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order ) {
            $this->log( 'Callback rejected: missing or invalid order id.', 'error' );
            wp_die( esc_html__( 'Invalid order callback.', 'wc-recurring-mpgs' ) );
        }

        if ( $this->id !== $order->get_payment_method() ) {
            $this->log( 'Callback rejected: payment method mismatch for order ' . $order->get_id() . '.', 'error' );
            wp_die( esc_html__( 'Invalid payment method callback.', 'wc-recurring-mpgs' ) );
        }

        if ( ! $this->is_valid_callback_nonce() ) {
            $this->log( 'Callback rejected: invalid nonce for order ' . $order->get_id() . '.', 'error' );
            wp_die( esc_html__( 'Invalid callback request.', 'wc-recurring-mpgs' ) );
        }

        if ( isset( $_GET['key'] ) ) {
            $incoming_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
            if ( ! hash_equals( (string) $order->get_order_key(), $incoming_key ) ) {
                $this->log( 'Callback rejected: invalid order key for order ' . $order->get_id() . '.', 'error' );
                wp_die( esc_html__( 'Invalid callback key.', 'wc-recurring-mpgs' ) );
            }
        }

        if ( ! $this->has_required_credentials() ) {
            $this->log( 'Callback failed: missing credentials for order ' . $order->get_id() . '.', 'error' );
            wc_add_notice( __( 'Payment verification is currently unavailable. Please contact support.', 'wc-recurring-mpgs' ), 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        $verification = $this->verify_order_payment( $order );

        if ( is_wp_error( $verification ) ) {
            $this->log( 'Callback verification failed for order ' . $order->get_id() . ': ' . $verification->get_error_message(), 'error' );
            $order->add_order_note( __( 'MPGS callback verification failed. See gateway logs for details.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'We could not verify your payment. Please try again or contact support.', 'wc-recurring-mpgs' ), 'error' );
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        }

        $callback_indicator = isset( $_GET['resultIndicator'] ) ? sanitize_text_field( wp_unslash( $_GET['resultIndicator'] ) ) : '';
        $verified_indicator = isset( $verification['resultIndicator'] ) ? (string) $verification['resultIndicator'] : '';
        $expected_indicator = (string) $order->get_meta( '_wcrmpgs_success_indicator', true );
        $result_indicator   = $verified_indicator ? $verified_indicator : $callback_indicator;
        $result_code        = isset( $verification['result'] ) ? strtoupper( (string) $verification['result'] ) : '';

        $order->update_meta_data( '_wcrmpgs_result_indicator', $result_indicator );
        $order->update_meta_data( '_wcrmpgs_result', $result_code );
        $order->update_meta_data( '_wcrmpgs_callback_payload', wp_json_encode( $verification ) );

        $transaction_id = $this->extract_transaction_id( $verification );
        if ( $transaction_id ) {
            $order->set_transaction_id( $transaction_id );
            $order->update_meta_data( '_wcrmpgs_transaction_id', $transaction_id );
        }

        $indicator_matches = $expected_indicator && $result_indicator && hash_equals( $expected_indicator, $result_indicator );
        $is_success        = $indicator_matches && 'SUCCESS' === $result_code;

        if ( $is_success ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete( $transaction_id );
            }

            $order->add_order_note( __( 'MPGS payment verified successfully.', 'wc-recurring-mpgs' ) );
            $order->save();
            $this->log( 'Order ' . $order->get_id() . ' payment verified successfully.', 'info' );

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        $failure_message = __( 'Payment was not completed or verification failed.', 'wc-recurring-mpgs' );

        if ( $expected_indicator && ! $indicator_matches ) {
            $failure_message = __( 'Payment verification failed due to mismatched indicator.', 'wc-recurring-mpgs' );
        }

        if ( ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
            $order->update_status( 'failed', $failure_message );
        }

        $order->add_order_note( $failure_message );
        $order->save();

        $this->log( 'Order ' . $order->get_id() . ' payment verification failed. Result: ' . $result_code . '.', 'warning' );

        wc_add_notice( $failure_message, 'error' );
        wp_safe_redirect( $order->get_checkout_payment_url() );
        exit;
    }

    /**
     * Process refund/void request through provider API.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount Refund amount.
     * @param string     $reason Reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'wcrmpgs_refund_invalid_order', __( 'Invalid order.', 'wc-recurring-mpgs' ) );
        }

        if ( ! $this->has_required_credentials() ) {
            $order->add_order_note( __( 'MPGS refund failed: gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
            return new WP_Error( 'wcrmpgs_refund_missing_credentials', __( 'Gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
        }

        $refund_amount = null === $amount ? (float) $order->get_total() : (float) $amount;

        if ( $refund_amount <= 0 ) {
            $order->add_order_note( __( 'MPGS refund failed: invalid refund amount.', 'wc-recurring-mpgs' ) );
            return new WP_Error( 'wcrmpgs_refund_invalid_amount', __( 'Refund amount must be greater than zero.', 'wc-recurring-mpgs' ) );
        }

        $transaction_id = (string) $order->get_transaction_id();

        if ( ! $transaction_id ) {
            $transaction_id = (string) $order->get_meta( '_wcrmpgs_transaction_id', true );
        }

        if ( ! $transaction_id ) {
            $order->add_order_note( __( 'MPGS refund failed: missing original transaction ID.', 'wc-recurring-mpgs' ) );
            return new WP_Error( 'wcrmpgs_refund_missing_transaction', __( 'Original transaction ID is missing.', 'wc-recurring-mpgs' ) );
        }

        $operation = strtoupper( (string) apply_filters( 'wcrmpgs_refund_operation', 'REFUND', $order, $refund_amount, $reason ) );
        if ( ! in_array( $operation, array( 'REFUND', 'VOID' ), true ) ) {
            $operation = 'REFUND';
        }

        $response = $this->send_refund_request( $order, $transaction_id, $refund_amount, $reason, $operation );

        if ( is_wp_error( $response ) ) {
            $this->log( 'MPGS ' . strtolower( $operation ) . ' request failed for order ' . $order->get_id() . ': ' . $response->get_error_message(), 'error' );
            $order->add_order_note( sprintf( __( 'MPGS %1$s request failed: %2$s', 'wc-recurring-mpgs' ), strtolower( $operation ), $response->get_error_message() ) );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            $order->add_order_note( sprintf( __( 'MPGS %s request failed: invalid provider response.', 'wc-recurring-mpgs' ), strtolower( $operation ) ) );
            return new WP_Error( 'wcrmpgs_refund_invalid_response', __( 'Invalid refund response from provider.', 'wc-recurring-mpgs' ) );
        }

        $result = strtoupper( (string) ( $body['result'] ?? '' ) );

        if ( 'SUCCESS' !== $result ) {
            $provider_message = $body['error']['explanation'] ?? __( 'Provider rejected the request.', 'wc-recurring-mpgs' );
            $safe_message     = wp_strip_all_tags( (string) $provider_message );

            $this->log( 'MPGS ' . strtolower( $operation ) . ' rejected for order ' . $order->get_id() . ': ' . wp_json_encode( $body ), 'warning' );
            $order->add_order_note( sprintf( __( 'MPGS %1$s rejected: %2$s', 'wc-recurring-mpgs' ), strtolower( $operation ), $safe_message ) );

            return new WP_Error( 'wcrmpgs_refund_rejected', $safe_message );
        }

        $provider_refund_id = $this->extract_transaction_id( $body );
        $order->update_meta_data( '_wcrmpgs_last_refund_payload', wp_json_encode( $body ) );
        if ( $provider_refund_id ) {
            $order->update_meta_data( '_wcrmpgs_last_refund_transaction_id', $provider_refund_id );
        }
        $order->save();

        $note = sprintf(
            __( 'MPGS %1$s successful. Amount: %2$s %3$s. Reason: %4$s', 'wc-recurring-mpgs' ),
            strtolower( $operation ),
            wc_format_decimal( $refund_amount, 2 ),
            $order->get_currency(),
            $reason ? wp_strip_all_tags( (string) $reason ) : __( 'N/A', 'wc-recurring-mpgs' )
        );
        $order->add_order_note( $note );

        $this->log( 'MPGS ' . strtolower( $operation ) . ' successful for order ' . $order->get_id() . '.', 'info' );

        return true;
    }

    /**
     * Output the receipt screen placeholder.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function receipt_page( $order_id ) {
        if ( empty( $_GET['sessionId'] ) ) {
            wc_add_notice( __( 'Payment session not found.', 'wc-recurring-mpgs' ), 'error' );
            return;
        }

        echo '<p>' . esc_html__( 'Redirecting to the hosted checkout page.', 'wc-recurring-mpgs' ) . '</p>';
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
     * Validate callback nonce.
     *
     * @return bool
     */
    protected function is_valid_callback_nonce() {
        if ( empty( $_GET['wcrmpgs_nonce'] ) ) {
            return false;
        }

        return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wcrmpgs_nonce'] ) ), 'wcrmpgs_process_response' );
    }

    /**
     * Verify order state with provider API.
     *
     * @param WC_Order $order Order object.
     * @return array|WP_Error
     */
    protected function verify_order_payment( WC_Order $order ) {
        $request_url = $this->get_api_client()->build_endpoint(
            $this->get_option( 'checkout_api_version', '100' ),
            'order/' . rawurlencode( (string) $order->get_id() )
        );

        $response = $this->get_api_client()->get( $request_url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            return new WP_Error( 'wcrmpgs_invalid_verification_response', __( 'Invalid verification response.', 'wc-recurring-mpgs' ) );
        }

        return $body;
    }

    /**
     * Send provider refund/void request for a specific transaction.
     *
     * @param WC_Order $order Order object.
     * @param string   $transaction_id Original transaction ID.
     * @param float    $amount Refund amount.
     * @param string   $reason Refund reason.
     * @param string   $operation Operation type REFUND|VOID.
     * @return array|WP_Error
     */
    protected function send_refund_request( WC_Order $order, $transaction_id, $amount, $reason, $operation ) {
        $request_url = $this->get_api_client()->build_endpoint(
            $this->get_option( 'checkout_api_version', '100' ),
            'order/' . rawurlencode( (string) $order->get_id() ) . '/transaction/' . rawurlencode( (string) $transaction_id )
        );

        $payload = array(
            'apiOperation' => $operation,
            'transaction'  => array(
                'amount'    => number_format( (float) $amount, 2, '.', '' ),
                'currency'  => $order->get_currency(),
                'reference' => 'REFUND-' . $order->get_id() . '-' . gmdate( 'YmdHis' ),
            ),
            'order'        => array(
                'id'       => (string) $order->get_id(),
                'amount'   => number_format( (float) $amount, 2, '.', '' ),
                'currency' => $order->get_currency(),
            ),
        );

        if ( $reason ) {
            $payload['transaction']['receipt'] = wp_strip_all_tags( (string) $reason );
        }

        return $this->get_api_client()->post( $request_url, $payload );
    }

    /**
     * Extract transaction id from order verification payload.
     *
     * @param array $verification Verification payload.
     * @return string
     */
    protected function extract_transaction_id( array $verification ) {
        if ( ! empty( $verification['transaction']['id'] ) ) {
            return (string) $verification['transaction']['id'];
        }

        if ( ! empty( $verification['transaction'][0]['id'] ) ) {
            return (string) $verification['transaction'][0]['id'];
        }

        if ( ! empty( $verification['id'] ) ) {
            return (string) $verification['id'];
        }

        return '';
    }

    /**
     * Build shared API client instance.
     *
     * @return WCRMPGS_Api_Client
     */
    protected function get_api_client() {
        return new WCRMPGS_Api_Client(
            $this->get_option( 'service_host' ),
            $this->get_option( 'merchant_id' ),
            $this->get_option( 'authentication_password' )
        );
    }

    /**
     * Build the hosted checkout service.
     *
    * @return WCRMPGS_Hosted_Checkout_Service
     */
    protected function get_hosted_checkout_service() {
        return new WCRMPGS_Hosted_Checkout_Service(
            $this->get_api_client(),
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
            $this->logger->log( $level, $message, array( 'source' => 'wc-recurring-mpgs' ) );
        }
    }
}