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
     * Session key used to gate checkout auto-resume behavior.
     */
    const SESSION_AUTO_RESUME_ORDER_ID = 'wcrmpgs_auto_resume_order_id';

    /**
     * Session key storing auto-resume creation timestamp.
     */
    const SESSION_AUTO_RESUME_CREATED_AT = 'wcrmpgs_auto_resume_created_at';

    /**
     * Auto-resume validity window (seconds).
     */
    const AUTO_RESUME_TTL = 300;

    /**
     * Hosted checkout session reuse window (seconds).
     */
    const CHECKOUT_SESSION_REUSE_TTL = 900;

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
        $this->supports           = array(
            'products',
            'refunds',
            'subscriptions',
            'subscription_resubscribe',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_admin',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_delayed_change',
            'multiple_subscriptions',
            'gateway_scheduled_payments',
        );
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
        add_action( 'wp', array( $this, 'maybe_add_retry_notice' ) );
        add_action( 'woocommerce_api_wcrmpgs_gateway', array( $this, 'process_response' ) );
        add_filter( 'script_loader_tag', array( $this, 'filter_checkout_sdk_script_tag' ), 10, 3 );
        add_filter( 'woocommerce_order_actions', array( $this, 'register_manual_mit_order_action' ), 10, 2 );
        add_action( 'woocommerce_order_action_wcrmpgs_manual_mit_charge', array( $this, 'handle_manual_mit_order_action' ) );
        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_scheduled_subscription_payment' ), 10, 2 );
        add_action( 'wp_ajax_wcrmpgs_client_log', array( $this, 'handle_client_log' ) );
        add_action( 'wp_ajax_nopriv_wcrmpgs_client_log', array( $this, 'handle_client_log' ) );
        add_action( 'template_redirect', array( $this, 'maybe_resume_pending_payment_page' ), 20 );
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

        $order_id  = isset( $_GET['order-pay'] ) ? absint( wp_unslash( $_GET['order-pay'] ) ) : 0;

        if ( ! $order_id && is_checkout() && ! is_checkout_pay_page() && ! empty( WC()->session ) ) {
            $awaiting_order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
            if ( $awaiting_order_id && $this->has_valid_auto_resume_intent( $awaiting_order_id ) ) {
                $order_id = $awaiting_order_id;
                $this->clear_auto_resume_intent();
                $this->flow_log(
                    'payment_scripts_awaiting_order_fallback',
                    array(
                        'awaiting_order_id' => $awaiting_order_id,
                        'request_uri'       => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                    )
                );
            } elseif ( $awaiting_order_id ) {
                WC()->session->set( 'order_awaiting_payment', 0 );
                $this->clear_auto_resume_intent();
                $this->flow_log(
                    'payment_scripts_fallback_blocked_no_intent',
                    array(
                        'awaiting_order_id' => $awaiting_order_id,
                        'request_uri'       => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                    )
                );
            }
        }

        $order     = $order_id ? wc_get_order( $order_id ) : false;
        $session_id = isset( $_GET['sessionId'] ) ? sanitize_text_field( wp_unslash( $_GET['sessionId'] ) ) : '';
        $retry_url  = '';

        if ( $order && $this->id === $order->get_payment_method() ) {
            if ( ! $session_id ) {
                $session_id = (string) $order->get_meta( '_wcrmpgs_session_id', true );
            }

            $retry_url = $this->get_retry_payment_url( $order );
        }

        $this->flow_log(
            'payment_scripts_context',
            array(
                'is_checkout'          => is_checkout() ? 'yes' : 'no',
                'is_checkout_pay_page' => is_checkout_pay_page() ? 'yes' : 'no',
                'order_id'             => $order ? $order->get_id() : 0,
                'session_id_present'   => '' !== $session_id ? 'yes' : 'no',
                'request_uri'          => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                'query_args'           => array_map( 'sanitize_text_field', wp_unslash( $_GET ) ),
            )
        );

        if ( ! $session_id ) {
            return;
        }

        $sdk_url = $this->get_checkout_sdk_url();

        if ( ! $sdk_url ) {
            $this->log( 'Hosted checkout SDK was not enqueued: invalid service host configuration.', 'error' );
            $this->flow_log( 'payment_scripts_sdk_url_missing', array( 'order_id' => $order ? $order->get_id() : 0 ) );
            return;
        }

        $this->flow_log(
            'payment_scripts_sdk_ready',
            array(
                'order_id'   => $order ? $order->get_id() : 0,
                'sdk_url'    => $sdk_url,
                'retry_url'  => $retry_url,
                'session_id' => $session_id,
            )
        );

        wp_enqueue_script(
            'wcrmpgs-checkout-sdk',
            $sdk_url,
            array(),
            $this->get_option( 'checkout_api_version', '100' ),
            true
        );

        wp_enqueue_script(
            'wcrmpgs-hosted-checkout',
            WCRMPGS_PLUGIN_URL . 'assets/js/checkout.js',
            array( 'wcrmpgs-checkout-sdk' ),
            WCRMPGS_VERSION,
            true
        );

        wp_localize_script(
            'wcrmpgs-hosted-checkout',
            'wcrmpgsCheckoutConfig',
            array(
                'sessionId' => $session_id,
                'retryUrl'  => $retry_url,
                'logEndpoint' => admin_url( 'admin-ajax.php' ),
                'logNonce'    => wp_create_nonce( 'wcrmpgs_client_log' ),
            )
        );
    }

    /**
     * Persist client-side checkout logs to WooCommerce log storage.
     *
     * @return void
     */
    public function handle_client_log() {
        if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcrmpgs_client_log' ) ) {
            wp_send_json_error( array( 'message' => 'invalid_nonce' ), 403 );
        }

        $message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
        if ( '' === $message ) {
            wp_send_json_error( array( 'message' => 'missing_message' ), 400 );
        }

        $level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : 'error';
        if ( ! in_array( $level, array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ), true ) ) {
            $level = 'error';
        }

        $context = array();
        if ( isset( $_POST['context'] ) ) {
            $raw_context = wp_unslash( $_POST['context'] );
            $decoded     = json_decode( $raw_context, true );
            if ( is_array( $decoded ) ) {
                $context = $decoded;
            }
        }

        if ( isset( $_POST['url'] ) ) {
            $context['url'] = esc_url_raw( wp_unslash( $_POST['url'] ) );
        }

        if ( isset( $_POST['userAgent'] ) ) {
            $context['user_agent'] = sanitize_text_field( wp_unslash( $_POST['userAgent'] ) );
        }

        $client_logger = wc_get_logger();
        $client_logger->log(
            $level,
            $message,
            array(
                'source'  => 'wc-recurring-mpgs-client',
                'context' => $context,
            )
        );

        wp_send_json_success( array( 'logged' => true ) );
    }

    /**
     * Add a notice when a hosted checkout attempt returns for retry.
     *
     * @return void
     */
    public function maybe_add_retry_notice() {
        if ( ! is_checkout_pay_page() || empty( $_GET['wcrmpgs_retry'] ) ) {
            return;
        }

        $order_id = isset( $_GET['order-pay'] ) ? absint( wp_unslash( $_GET['order-pay'] ) ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order || $this->id !== $order->get_payment_method() ) {
            return;
        }

        if ( ! empty( $_GET['wcrmpgs_sdk_error'] ) ) {
            $order->delete_meta_data( '_wcrmpgs_session_id' );
            $order->delete_meta_data( '_wcrmpgs_session_version' );
            $order->delete_meta_data( '_wcrmpgs_session_created_at' );
            $order->save();
            wc_add_notice( __( 'Hosted checkout could not be initialized. Please verify service host/API version settings and check browser console logs.', 'wc-recurring-mpgs' ), 'error' );
            return;
        }

        if ( ! empty( $_GET['wcrmpgs_cancelled'] ) ) {
            wc_add_notice( __( 'Payment was canceled by the customer. Please try again.', 'wc-recurring-mpgs' ), 'error' );
            return;
        }

        wc_add_notice( __( 'Payment was canceled or could not be completed. Please try again.', 'wc-recurring-mpgs' ), 'error' );
    }

    /**
     * If checkout bounces back without order-pay args, resume pending payment URL.
     *
     * @return void
     */
    public function maybe_resume_pending_payment_page() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        if ( ! is_checkout() || is_checkout_pay_page() ) {
            return;
        }

        if ( empty( WC()->session ) ) {
            return;
        }

        $awaiting_order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
        if ( ! $awaiting_order_id ) {
            return;
        }

        if ( ! $this->has_valid_auto_resume_intent( $awaiting_order_id ) ) {
            WC()->session->set( 'order_awaiting_payment', 0 );
            $this->clear_auto_resume_intent();
            $this->flow_log(
                'resume_pending_payment_blocked_no_intent',
                array(
                    'awaiting_order_id' => $awaiting_order_id,
                    'request_uri'       => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
                )
            );
            return;
        }

        $order = wc_get_order( $awaiting_order_id );
        if ( ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() ) {
            $this->clear_auto_resume_intent();
            return;
        }

        if ( ! $order->needs_payment() ) {
            $this->clear_auto_resume_intent();
            return;
        }

        $resume_lock_key = 'wcrmpgs_resume_lock_' . $awaiting_order_id;
        $resume_locked   = WC()->session->get( $resume_lock_key );
        if ( $resume_locked ) {
            return;
        }

        WC()->session->set( $resume_lock_key, 1 );

        $resume_url = add_query_arg( 'wcrmpgs_resume', '1', $order->get_checkout_payment_url() );

        $this->flow_log(
            'resume_pending_payment_redirect',
            array(
                'order_id'    => $awaiting_order_id,
                'resume_url'  => $resume_url,
                'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
            )
        );

        wp_safe_redirect( $resume_url );
        exit;
    }

    /**
     * Inject MPGS hosted checkout callbacks into the SDK script tag.
     *
     * @param string $tag Script tag.
     * @param string $handle Script handle.
     * @param string $src Script source.
     * @return string
     */
    public function filter_checkout_sdk_script_tag( $tag, $handle, $src ) {
        if ( 'wcrmpgs-checkout-sdk' !== $handle ) {
            return $tag;
        }

        return sprintf(
            '<script src="%1$s" data-error="wcrmpgsErrorCallback" data-cancel="wcrmpgsCancelCallback"></script>',
            esc_url( $src )
        );
    }

    /**
     * Build MPGS Checkout SDK URL from service host and API version.
     *
     * @return string
     */
    protected function get_checkout_sdk_url() {
        $service_host = trailingslashit( (string) $this->get_option( 'service_host', '' ) );

        if ( ! $service_host || ! wp_http_validate_url( $service_host ) ) {
            return '';
        }

        $api_version = (int) $this->get_option( 'checkout_api_version', '100' );

        if ( $api_version >= 63 ) {
            return $service_host . 'static/checkout/checkout.min.js';
        }

        return $service_host . 'checkout/version/' . $api_version . '/checkout.js';
    }

    /**
     * Build a clean pay-for-order URL that does not relaunch the current session.
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    protected function get_retry_payment_url( WC_Order $order ) {
        return add_query_arg(
            array(
                'pay_for_order' => 'true',
                'key'           => $order->get_order_key(),
                'wcrmpgs_retry' => '1',
            ),
            $order->get_checkout_payment_url()
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

        $this->flow_log( 'process_payment_start', array( 'order_id' => (int) $order_id ) );

        if ( ! $order ) {
            $this->clear_auto_resume_intent();
            wc_add_notice( __( 'Invalid order.', 'wc-recurring-mpgs' ), 'error' );
            $this->flow_log( 'process_payment_invalid_order', array( 'order_id' => (int) $order_id ) );
            return array( 'result' => 'failure' );
        }

        if ( ! $this->has_required_credentials() ) {
            $this->clear_auto_resume_intent();
            $order->add_order_note( __( 'MPGS checkout session failed: gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'Gateway credentials are incomplete.', 'wc-recurring-mpgs' ), 'error' );
            $this->flow_log( 'process_payment_missing_credentials', array( 'order_id' => $order->get_id() ) );
            return array( 'result' => 'failure' );
        }

        $existing_session_id = (string) $order->get_meta( '_wcrmpgs_session_id', true );
        $session_created_at = (string) $order->get_meta( '_wcrmpgs_session_created_at', true );
        $session_fresh      = $this->is_checkout_session_fresh( $session_created_at );

        if ( $existing_session_id && $order->needs_payment() && $session_fresh ) {
            $this->set_auto_resume_intent( $order->get_id() );

            $this->flow_log(
                'process_payment_reusing_existing_session',
                array(
                    'order_id'   => $order->get_id(),
                    'session_id' => $existing_session_id,
                )
            );

            $order->add_order_note( sprintf( __( 'MPGS checkout session reused. Session ID: %s', 'wc-recurring-mpgs' ), $existing_session_id ) );

            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(),
            );
        }

        if ( $existing_session_id && ! $session_fresh ) {
            $order->delete_meta_data( '_wcrmpgs_session_id' );
            $order->delete_meta_data( '_wcrmpgs_session_version' );
            $order->delete_meta_data( '_wcrmpgs_session_created_at' );
            $order->save();

            $this->flow_log(
                'process_payment_stale_session_cleared',
                array(
                    'order_id'   => $order->get_id(),
                    'session_id' => $existing_session_id,
                    'created_at' => $session_created_at,
                )
            );
        }

        $response = $this->get_hosted_checkout_service()->create_checkout_session( $order );

        if ( is_wp_error( $response ) ) {
            $this->clear_auto_resume_intent();
            $this->log( 'Hosted checkout session creation failed: ' . $response->get_error_message(), 'error' );
            $order->add_order_note( __( 'MPGS checkout session could not be created. See gateway logs for details.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'Failed to create the hosted checkout session.', 'wc-recurring-mpgs' ), 'error' );
            $this->flow_log(
                'process_payment_session_create_error',
                array(
                    'order_id' => $order->get_id(),
                    'error'    => $response->get_error_message(),
                )
            );
            return array( 'result' => 'failure' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || 'SUCCESS' !== ( $body['result'] ?? '' ) || empty( $body['session']['id'] ) ) {
            $this->clear_auto_resume_intent();
            $message = $body['error']['explanation'] ?? __( 'Unexpected gateway response.', 'wc-recurring-mpgs' );
            $this->log( 'Hosted checkout session rejected: ' . wp_json_encode( $body ), 'error' );
            $order->add_order_note( sprintf( __( 'MPGS checkout session rejected: %s', 'wc-recurring-mpgs' ), wp_strip_all_tags( (string) $message ) ) );
            wc_add_notice( $message, 'error' );
            $this->flow_log(
                'process_payment_session_rejected',
                array(
                    'order_id' => $order->get_id(),
                    'message'  => wp_strip_all_tags( (string) $message ),
                    'body'     => $body,
                )
            );
            return array( 'result' => 'failure' );
        }

        $order->update_meta_data( '_wcrmpgs_success_indicator', $body['successIndicator'] ?? '' );
        $order->update_meta_data( '_wcrmpgs_session_id', $body['session']['id'] ?? '' );
        $order->update_meta_data( '_wcrmpgs_session_version', $body['session']['version'] ?? '' );
        $order->update_meta_data( '_wcrmpgs_session_created_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->add_order_note( sprintf( __( 'MPGS checkout session created successfully. Session ID: %s', 'wc-recurring-mpgs' ), $body['session']['id'] ) );
        $order->save();
        $this->set_auto_resume_intent( $order->get_id() );

        $this->flow_log(
            'process_payment_session_created',
            array(
                'order_id'    => $order->get_id(),
                'session_id'  => $body['session']['id'],
                'session_ver' => $body['session']['version'] ?? '',
                'redirect'    => $order->get_checkout_payment_url(),
            )
        );

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url(),
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
            wp_safe_redirect( $this->get_retry_payment_url( $order ) );
            exit;
        }

        $verification = $this->verify_order_payment( $order );

        if ( is_wp_error( $verification ) ) {
            $this->log( 'Callback verification failed for order ' . $order->get_id() . ': ' . $verification->get_error_message(), 'error' );
            $order->add_order_note( __( 'MPGS callback verification failed. See gateway logs for details.', 'wc-recurring-mpgs' ) );
            wc_add_notice( __( 'We could not verify your payment. Please try again or contact support.', 'wc-recurring-mpgs' ), 'error' );
            wp_safe_redirect( $this->get_retry_payment_url( $order ) );
            exit;
        }

        $callback_indicator = isset( $_GET['resultIndicator'] ) ? sanitize_text_field( wp_unslash( $_GET['resultIndicator'] ) ) : '';
        $result            = $this->finalize_callback_result( $order, $verification, $callback_indicator );

        if ( $result['success'] ) {
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        wc_add_notice( $result['message'], 'error' );
        wp_safe_redirect( $this->get_retry_payment_url( $order ) );
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
     * Register manual MIT admin order action when order is eligible.
     *
     * @param array         $actions Existing order actions.
     * @param WC_Order|bool $order Order object when available.
     * @return array
     */
    public function register_manual_mit_order_action( $actions, $order = false ) {
        if ( ! $order instanceof WC_Order ) {
            return $actions;
        }

        if ( $this->id !== $order->get_payment_method() ) {
            return $actions;
        }

        $token = (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_TOKEN, true );
        if ( '' === $token ) {
            return $actions;
        }

        $actions['wcrmpgs_manual_mit_charge'] = __( 'MPGS: Run Manual MIT Charge', 'wc-recurring-mpgs' );

        return $actions;
    }

    /**
     * Handle manual MIT order action from WooCommerce admin.
     *
     * @param WC_Order $order Order object.
     * @return void
     */
    public function handle_manual_mit_order_action( WC_Order $order ) {
        $validation = $this->validate_manual_mit_admin_request( $order );

        if ( is_wp_error( $validation ) ) {
            $error_message = $validation->get_error_message();
            $order->add_order_note( sprintf( __( 'Manual MIT charge blocked: %s', 'wc-recurring-mpgs' ), $error_message ) );
            $this->log( 'Manual MIT action blocked for order ' . $order->get_id() . ': ' . $error_message, 'warning' );
            return;
        }

        $result = $this->process_manual_mit_charge( $order );

        if ( is_wp_error( $result ) ) {
            $error_message = $result->get_error_message();
            $order->add_order_note( sprintf( __( 'Manual MIT charge failed: %s', 'wc-recurring-mpgs' ), $error_message ) );
            $this->log( 'Manual MIT charge failed for order ' . $order->get_id() . ': ' . $error_message, 'error' );
            return;
        }

        $order->add_order_note(
            sprintf(
                __( 'Manual MIT charge successful. Transaction ID: %s', 'wc-recurring-mpgs' ),
                $result['transaction_id'] ? $result['transaction_id'] : __( 'N/A', 'wc-recurring-mpgs' )
            )
        );
        $this->log( 'Manual MIT charge successful for order ' . $order->get_id() . '.', 'info' );
    }

    /**
     * Validate admin action security checks for manual MIT.
     *
     * @param WC_Order $order Order object.
     * @return true|WP_Error
     */
    protected function validate_manual_mit_admin_request( WC_Order $order ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
            return new WP_Error( 'wcrmpgs_manual_mit_forbidden', __( 'You are not allowed to run manual MIT charges.', 'wc-recurring-mpgs' ) );
        }

        if ( empty( $_REQUEST['_wpnonce'] ) ) {
            return new WP_Error( 'wcrmpgs_manual_mit_missing_nonce', __( 'Missing admin nonce for manual MIT action.', 'wc-recurring-mpgs' ) );
        }

        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
        $valid = wp_verify_nonce( $nonce, 'woocommerce-mark-order-status' ) || wp_verify_nonce( $nonce, 'wcrmpgs_manual_mit_charge_' . $order->get_id() );

        if ( ! $valid ) {
            return new WP_Error( 'wcrmpgs_manual_mit_invalid_nonce', __( 'Invalid admin nonce for manual MIT action.', 'wc-recurring-mpgs' ) );
        }

        return true;
    }

    /**
     * Execute manual MIT charge attempt for an order.
     *
     * @param WC_Order $order Order object.
     * @return array|WP_Error
     */
    protected function process_manual_mit_charge( WC_Order $order ) {
        if ( ! $this->has_required_credentials() ) {
            return new WP_Error( 'wcrmpgs_manual_mit_missing_credentials', __( 'Gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
        }

        if ( 'yes' !== $this->get_option( 'recurring_enabled', 'no' ) ) {
            return new WP_Error( 'wcrmpgs_manual_mit_not_enabled', __( 'Recurring payments are not enabled in gateway settings.', 'wc-recurring-mpgs' ) );
        }

        $token = (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_TOKEN, true );
        if ( '' === $token ) {
            return new WP_Error( 'wcrmpgs_manual_mit_missing_token', __( 'Recurring token is missing for this order.', 'wc-recurring-mpgs' ) );
        }

        $merchant_transaction_id = 'mit-manual-' . $order->get_id() . '-' . gmdate( 'YmdHis' );
        $base_transaction_id     = (string) $order->get_transaction_id();

        if ( '' === $base_transaction_id ) {
            $base_transaction_id = (string) $order->get_meta( '_wcrmpgs_transaction_id', true );
        }

        if ( '' === $base_transaction_id ) {
            $base_transaction_id = 'cit-' . $order->get_id();
        }

        $service = $this->get_recurring_service();

        $request_input = array(
            'order_id'                => (string) $order->get_id(),
            'amount'                  => (string) $order->get_total(),
            'currency'                => (string) $order->get_currency(),
            'token'                   => $token,
            'transaction_id'          => $base_transaction_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'order_reference'         => (string) $order->get_id(),
            'agreement_id'            => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_ID, true ),
            'agreement_type'          => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_TYPE, true ),
            'agreement_source'        => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_SOURCE, true ),
            'agreement_number_of_payments' => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS, true ),
            'agreement_amount_variability' => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY, true ),
            'agreement_expiry_date'        => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE, true ),
            'agreement_payment_frequency'  => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY, true ),
            'agreement_minimum_days_between_payments' => (string) $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS, true ),
        );

        $payload = $service->build_mit_pay_request( $request_input );

        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $endpoint = $service->build_mit_endpoint( (string) $order->get_id(), $merchant_transaction_id );
        $response = $this->get_api_client()->put( $endpoint, $payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'wcrmpgs_manual_mit_invalid_response', __( 'Invalid provider response for manual MIT charge.', 'wc-recurring-mpgs' ) );
        }

        $normalized = $service->normalize_mit_response( $body );

        $order->update_meta_data( '_wcrmpgs_last_mit_attempted_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->update_meta_data( '_wcrmpgs_last_mit_request', wp_json_encode( $payload ) );
        $order->update_meta_data( '_wcrmpgs_last_mit_response', wp_json_encode( $body ) );

        if ( ! empty( $normalized['transaction_id'] ) ) {
            $order->update_meta_data( '_wcrmpgs_last_mit_transaction_id', (string) $normalized['transaction_id'] );
        }

        if ( $normalized['success'] ) {
            if ( ! empty( $normalized['transaction_id'] ) ) {
                $order->set_transaction_id( (string) $normalized['transaction_id'] );
                $order->update_meta_data( '_wcrmpgs_transaction_id', (string) $normalized['transaction_id'] );
            }

            $order->save();

            return $normalized;
        }

        $order->save();

        return new WP_Error(
            'wcrmpgs_manual_mit_rejected',
            ! empty( $normalized['message'] ) ? (string) $normalized['message'] : __( 'Manual MIT charge was rejected.', 'wc-recurring-mpgs' )
        );
    }

    /**
     * Run a scheduled subscription renewal charge through MIT path.
     *
     * @param float    $amount_to_charge Renewal amount.
     * @param WC_Order $renewal_order Renewal order.
     * @return void
     */
    public function process_scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
        if ( ! $renewal_order instanceof WC_Order ) {
            return;
        }

        $result = $this->process_renewal_mit_charge( $renewal_order, (float) $amount_to_charge );

        if ( is_wp_error( $result ) ) {
            $message = $result->get_error_message();

            if ( ! $renewal_order->is_paid() && ! $renewal_order->has_status( 'failed' ) ) {
                $renewal_order->update_status( 'failed', $message );
            }

            $renewal_order->add_order_note( sprintf( __( 'Renewal MIT charge failed: %s', 'wc-recurring-mpgs' ), $message ) );
            $this->log( 'Renewal MIT charge failed for order ' . $renewal_order->get_id() . ': ' . $message, 'error' );
            return;
        }

        if ( ! $renewal_order->is_paid() ) {
            $renewal_order->payment_complete( $result['transaction_id'] );
        }

        $renewal_order->add_order_note(
            sprintf(
                __( 'Renewal MIT charge successful. Transaction ID: %s', 'wc-recurring-mpgs' ),
                ! empty( $result['transaction_id'] ) ? $result['transaction_id'] : __( 'N/A', 'wc-recurring-mpgs' )
            )
        );
        $this->log( 'Renewal MIT charge successful for order ' . $renewal_order->get_id() . '.', 'info' );
    }

    /**
     * Execute MIT renewal charge for a renewal order.
     *
     * @param WC_Order $renewal_order Renewal order.
     * @param float    $amount_to_charge Renewal amount.
     * @return array|WP_Error
     */
    protected function process_renewal_mit_charge( WC_Order $renewal_order, $amount_to_charge ) {
        if ( ! $this->has_required_credentials() ) {
            return new WP_Error( 'wcrmpgs_renewal_missing_credentials', __( 'Gateway credentials are incomplete.', 'wc-recurring-mpgs' ) );
        }

        if ( 'yes' !== $this->get_option( 'recurring_enabled', 'no' ) ) {
            return new WP_Error( 'wcrmpgs_renewal_not_enabled', __( 'Recurring payments are not enabled in gateway settings.', 'wc-recurring-mpgs' ) );
        }

        $amount = (float) $amount_to_charge;
        if ( $amount <= 0 ) {
            return new WP_Error( 'wcrmpgs_renewal_invalid_amount', __( 'Renewal amount must be greater than zero.', 'wc-recurring-mpgs' ) );
        }

        $attempt_key = 'renewal-order-' . $renewal_order->get_id();
        $guard       = $this->assert_renewal_attempt_allowed( $renewal_order, $attempt_key );

        if ( is_wp_error( $guard ) ) {
            return $guard;
        }

        $token = $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_TOKEN );
        if ( '' === $token ) {
            return new WP_Error( 'wcrmpgs_renewal_missing_token', __( 'Recurring token is missing for renewal order.', 'wc-recurring-mpgs' ) );
        }

        $merchant_transaction_id = 'mit-renewal-' . $renewal_order->get_id() . '-' . gmdate( 'YmdHis' );
        $base_transaction_id     = (string) $renewal_order->get_transaction_id();

        if ( '' === $base_transaction_id ) {
            $base_transaction_id = (string) $renewal_order->get_meta( '_wcrmpgs_transaction_id', true );
        }

        if ( '' === $base_transaction_id && $renewal_order->get_parent_id() ) {
            $parent_order = wc_get_order( $renewal_order->get_parent_id() );
            if ( $parent_order instanceof WC_Order ) {
                $base_transaction_id = (string) $parent_order->get_transaction_id();

                if ( '' === $base_transaction_id ) {
                    $base_transaction_id = (string) $parent_order->get_meta( '_wcrmpgs_transaction_id', true );
                }
            }
        }

        if ( '' === $base_transaction_id ) {
            $base_transaction_id = 'cit-' . $renewal_order->get_id();
        }

        $service = $this->get_recurring_service();

        $request_input = array(
            'order_id'                => (string) $renewal_order->get_id(),
            'amount'                  => wc_format_decimal( $amount, 2 ),
            'currency'                => (string) $renewal_order->get_currency(),
            'token'                   => $token,
            'transaction_id'          => $base_transaction_id,
            'merchant_transaction_id' => $merchant_transaction_id,
            'order_reference'         => (string) $renewal_order->get_id(),
            'agreement_id'            => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_ID ),
            'agreement_type'          => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_TYPE ),
            'agreement_source'        => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_SOURCE ),
            'agreement_number_of_payments' => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS ),
            'agreement_amount_variability' => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY ),
            'agreement_expiry_date'        => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE ),
            'agreement_payment_frequency'  => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY ),
            'agreement_minimum_days_between_payments' => $this->get_renewal_contract_meta( $renewal_order, WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS ),
        );

        $payload = $service->build_mit_pay_request( $request_input );

        if ( is_wp_error( $payload ) ) {
            $this->persist_renewal_attempt_meta( $renewal_order, $attempt_key, $amount, $payload->get_error_message() );
            return $payload;
        }

        $endpoint = $service->build_mit_endpoint( (string) $renewal_order->get_id(), $merchant_transaction_id );
        $response = $this->get_api_client()->put( $endpoint, $payload );

        if ( is_wp_error( $response ) ) {
            $this->persist_renewal_attempt_meta( $renewal_order, $attempt_key, $amount, $response->get_error_message(), wp_json_encode( $payload ) );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            $error = new WP_Error( 'wcrmpgs_renewal_invalid_response', __( 'Invalid provider response for renewal MIT charge.', 'wc-recurring-mpgs' ) );
            $this->persist_renewal_attempt_meta( $renewal_order, $attempt_key, $amount, $error->get_error_message(), wp_json_encode( $payload ) );
            return $error;
        }

        $normalized = $service->normalize_mit_response( $body );

        $this->persist_renewal_attempt_meta(
            $renewal_order,
            $attempt_key,
            $amount,
            (string) $normalized['message'],
            wp_json_encode( $payload ),
            wp_json_encode( $body ),
            $normalized
        );

        if ( $normalized['success'] ) {
            if ( ! empty( $normalized['transaction_id'] ) ) {
                $renewal_order->set_transaction_id( (string) $normalized['transaction_id'] );
                $renewal_order->update_meta_data( '_wcrmpgs_transaction_id', (string) $normalized['transaction_id'] );
            }

            $renewal_order->save();

            return $normalized;
        }

        return new WP_Error(
            'wcrmpgs_renewal_rejected',
            ! empty( $normalized['message'] ) ? (string) $normalized['message'] : __( 'Renewal MIT charge was rejected.', 'wc-recurring-mpgs' )
        );
    }

    /**
     * Prevent duplicate successful renewal charge attempts on same order.
     *
     * @param WC_Order $renewal_order Renewal order.
     * @param string   $attempt_key Attempt key.
     * @return true|WP_Error
     */
    protected function assert_renewal_attempt_allowed( WC_Order $renewal_order, $attempt_key ) {
        $last_key    = (string) $renewal_order->get_meta( '_wcrmpgs_renewal_attempt_key', true );
        $last_result = (string) $renewal_order->get_meta( '_wcrmpgs_renewal_attempt_result', true );

        if ( $last_key && hash_equals( $last_key, (string) $attempt_key ) && 'success' === $last_result ) {
            return new WP_Error( 'wcrmpgs_renewal_duplicate_attempt', __( 'Renewal charge already succeeded for this renewal order.', 'wc-recurring-mpgs' ) );
        }

        return true;
    }

    /**
     * Read renewal contract meta from renewal order, then parent order fallback.
     *
     * @param WC_Order $renewal_order Renewal order.
     * @param string   $meta_key Meta key.
     * @return string
     */
    protected function get_renewal_contract_meta( WC_Order $renewal_order, $meta_key ) {
        $value = (string) $renewal_order->get_meta( $meta_key, true );

        if ( '' !== $value ) {
            return $value;
        }

        if ( $renewal_order->get_parent_id() ) {
            $parent_order = wc_get_order( $renewal_order->get_parent_id() );
            if ( $parent_order instanceof WC_Order ) {
                return (string) $parent_order->get_meta( $meta_key, true );
            }
        }

        return '';
    }

    /**
     * Persist renewal attempt metadata for observability and retries.
     *
     * @param WC_Order     $renewal_order Renewal order.
     * @param string       $attempt_key Attempt key.
     * @param float        $amount Renewal amount.
     * @param string       $message Attempt message.
     * @param string       $request_json Request payload JSON.
     * @param string       $response_json Response payload JSON.
     * @param array<string,mixed> $normalized Normalized response.
     * @return void
     */
    protected function persist_renewal_attempt_meta( WC_Order $renewal_order, $attempt_key, $amount, $message, $request_json = '', $response_json = '', array $normalized = array() ) {
        $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempted_at', gmdate( 'Y-m-d H:i:s' ) );
        $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_key', (string) $attempt_key );
        $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_amount', wc_format_decimal( (float) $amount, 2 ) );
        $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_message', (string) $message );

        if ( '' !== $request_json ) {
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_request', $request_json );
        }

        if ( '' !== $response_json ) {
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_response', $response_json );
        }

        if ( ! empty( $normalized ) ) {
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_result', ! empty( $normalized['success'] ) ? 'success' : 'failed' );
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_result_code', (string) ( $normalized['result_code'] ?? '' ) );
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_gateway_code', (string) ( $normalized['gateway_code'] ?? '' ) );
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_error_code', (string) ( $normalized['error_code'] ?? '' ) );

            if ( ! empty( $normalized['transaction_id'] ) ) {
                $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_transaction_id', (string) $normalized['transaction_id'] );
            }
        } else {
            $renewal_order->update_meta_data( '_wcrmpgs_renewal_attempt_result', 'failed' );
        }

        $renewal_order->save();
    }

    /**
     * Output the receipt screen placeholder.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function receipt_page( $order_id ) {
        $session_id = isset( $_GET['sessionId'] ) ? sanitize_text_field( wp_unslash( $_GET['sessionId'] ) ) : '';

        if ( ! $session_id ) {
            $order = wc_get_order( $order_id );

            if ( $order instanceof WC_Order && $this->id === $order->get_payment_method() ) {
                $session_id = (string) $order->get_meta( '_wcrmpgs_session_id', true );
            }
        }

        if ( ! $session_id ) {
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
     * Finalize callback verification result and persist order outcome.
     *
     * @param WC_Order $order Order object.
     * @param array    $verification Verification payload.
     * @param string   $callback_indicator Callback result indicator.
     * @return array{success:bool,message:string,result_code:string,result_indicator:string,transaction_id:string}
     */
    protected function finalize_callback_result( WC_Order $order, array $verification, $callback_indicator = '' ) {
        $verified_indicator = isset( $verification['resultIndicator'] ) ? (string) $verification['resultIndicator'] : '';
        $expected_indicator = (string) $order->get_meta( '_wcrmpgs_success_indicator', true );
        $result_indicator   = $verified_indicator ? $verified_indicator : (string) $callback_indicator;
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

            $this->persist_recurring_contract_data( $order, $verification );

            $order->add_order_note( __( 'MPGS payment verified successfully.', 'wc-recurring-mpgs' ) );
            $order->save();
            $this->log( 'Order ' . $order->get_id() . ' payment verified successfully.', 'info' );

            return array(
                'success'          => true,
                'message'          => '',
                'result_code'      => $result_code,
                'result_indicator' => $result_indicator,
                'transaction_id'   => $transaction_id,
            );
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

        return array(
            'success'          => false,
            'message'          => $failure_message,
            'result_code'      => $result_code,
            'result_indicator' => $result_indicator,
            'transaction_id'   => $transaction_id,
        );
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
        $api_version = $this->get_option( 'checkout_api_version', '100' );
        $base_path   = 'order/' . rawurlencode( (string) $order->get_id() ) . '/transaction/';
        $base_url    = $this->get_api_client()->build_endpoint( $api_version, $base_path );

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode( 'merchant.' . $this->get_option( 'merchant_id' ) . ':' . $this->get_option( 'authentication_password' ) ),
            'Accept'        => 'application/json',
        );

        if ( 'VOID' === $operation ) {
            $request_url = $base_url . rawurlencode( (string) $transaction_id );

            return wp_remote_request(
                $request_url,
                array(
                    'method'  => 'DELETE',
                    'headers' => $headers,
                    'timeout' => 45,
                )
            );
        }

        $refund_transaction_id = 'refund-' . gmdate( 'YmdHis' );
        $request_url           = $base_url . rawurlencode( $refund_transaction_id );

        $payload = array(
            'apiOperation' => 'REFUND',
            'transaction'  => array(
                'amount'   => number_format( (float) $amount, 2, '.', '' ),
                'currency' => $order->get_currency(),
            ),
        );

        if ( $reason ) {
            $payload['transaction']['receipt'] = wp_strip_all_tags( (string) $reason );
        }

        $headers['Content-Type'] = 'application/json';

        return wp_remote_request(
            $request_url,
            array(
                'method'  => 'PUT',
                'headers' => $headers,
                'body'    => wp_json_encode( $payload ),
                'timeout' => 45,
            )
        );
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
     * Persist recurring token/agreement contract from first successful CIT.
     *
     * @param WC_Order $order Order object.
     * @param array    $verification Verification payload.
     * @return void
     */
    protected function persist_recurring_contract_data( WC_Order $order, array $verification ) {
        $contract = new WCRMPGS_Recurring_Contract();

        $meta_map = $contract->build_meta_map(
            $contract->extract( $verification ),
            gmdate( 'Y-m-d H:i:s' )
        );

        if ( empty( $meta_map ) ) {
            return;
        }

        foreach ( $meta_map as $meta_key => $meta_value ) {
            $order->update_meta_data( $meta_key, $meta_value );
        }

        $token = isset( $meta_map[ WCRMPGS_Recurring_Contract::META_TOKEN ] ) ? (string) $meta_map[ WCRMPGS_Recurring_Contract::META_TOKEN ] : '';
        if ( $token ) {
            $order->add_order_note( __( 'MPGS recurring contract captured for future MIT charges.', 'wc-recurring-mpgs' ) );
        }

        $this->persist_recurring_contract_on_subscriptions( $order, $meta_map );
    }

    /**
     * Mirror recurring contract data to linked subscriptions when available.
     *
     * @param WC_Order $order Parent order.
     * @param array    $meta_map Normalized recurring meta key map.
     * @return void
     */
    protected function persist_recurring_contract_on_subscriptions( WC_Order $order, array $meta_map ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) );
        if ( ! is_array( $subscriptions ) || empty( $subscriptions ) ) {
            return;
        }

        foreach ( $subscriptions as $subscription ) {
            if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) || ! method_exists( $subscription, 'save' ) ) {
                continue;
            }

            foreach ( $meta_map as $meta_key => $meta_value ) {
                $subscription->update_meta_data( $meta_key, $meta_value );
            }

            $subscription->save();
        }
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
     * Build recurring MIT service instance.
     *
     * @return WCRMPGS_Recurring_Service
     */
    protected function get_recurring_service() {
        return new WCRMPGS_Recurring_Service(
            $this->get_api_client(),
            array(
                'recurring_api_version' => $this->get_option( 'recurring_api_version', '100' ),
            )
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

    /**
     * Persist checkout flow diagnostics regardless of debug mode.
     *
     * @param string $event Flow event key.
     * @param array  $context Structured context.
     * @return void
     */
    protected function flow_log( $event, array $context = array() ) {
        wc_get_logger()->info(
            $event,
            array(
                'source'  => 'wc-recurring-mpgs-flow',
                'context' => $context,
            )
        );
    }

    /**
     * Mark the current session as eligible for one auto-resume cycle.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    protected function set_auto_resume_intent( $order_id ) {
        if ( empty( WC()->session ) ) {
            return;
        }

        WC()->session->set( self::SESSION_AUTO_RESUME_ORDER_ID, absint( $order_id ) );
        WC()->session->set( self::SESSION_AUTO_RESUME_CREATED_AT, time() );
    }

    /**
     * Clear auto-resume session markers.
     *
     * @return void
     */
    protected function clear_auto_resume_intent() {
        if ( empty( WC()->session ) ) {
            return;
        }

        WC()->session->set( self::SESSION_AUTO_RESUME_ORDER_ID, 0 );
        WC()->session->set( self::SESSION_AUTO_RESUME_CREATED_AT, 0 );
    }

    /**
     * Validate whether auto-resume intent exists and is still fresh.
     *
     * @param int $expected_order_id Optional expected order ID.
     * @return bool
     */
    protected function has_valid_auto_resume_intent( $expected_order_id = 0 ) {
        if ( empty( WC()->session ) ) {
            return false;
        }

        $intent_order_id = absint( WC()->session->get( self::SESSION_AUTO_RESUME_ORDER_ID ) );
        $created_at      = absint( WC()->session->get( self::SESSION_AUTO_RESUME_CREATED_AT ) );

        if ( ! $intent_order_id || ! $created_at ) {
            return false;
        }

        if ( $expected_order_id && $intent_order_id !== absint( $expected_order_id ) ) {
            return false;
        }

        if ( ( time() - $created_at ) > self::AUTO_RESUME_TTL ) {
            $this->clear_auto_resume_intent();
            return false;
        }

        return true;
    }

    /**
     * Determine whether stored hosted checkout session timestamp is still valid.
     *
     * @param string $created_at UTC datetime string.
     * @return bool
     */
    protected function is_checkout_session_fresh( $created_at ) {
        if ( '' === trim( (string) $created_at ) ) {
            return false;
        }

        $created_ts = strtotime( (string) $created_at . ' UTC' );
        if ( ! $created_ts ) {
            return false;
        }

        return ( time() - $created_ts ) <= self::CHECKOUT_SESSION_REUSE_TTL;
    }
}