<?php

/**
 * Hosted checkout request builder.
 *
 * @package MPFW
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds initial CIT checkout payloads.
 */
class MPFW_Hosted_Checkout_Service {

    /**
     * API client.
     *
    * @var MPFW_Api_Client
     */
    private $api_client;

    /**
     * Gateway settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     *
    * @param MPFW_Api_Client $api_client API client.
     * @param array           $settings Gateway settings.
     */
    public function __construct( MPFW_Api_Client $api_client, array $settings ) {
        $this->api_client = $api_client;
        $this->settings   = $settings;
    }

    /**
     * Build the hosted checkout session payload.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    public function build_session_request( WC_Order $order ) {
        $api_version = (int) $this->settings['checkout_api_version'];
        $order_id    = $order->get_id();

        $payload = array(
            'apiOperation' => $api_version >= 63 ? 'INITIATE_CHECKOUT' : 'CREATE_CHECKOUT_SESSION',
            'order'        => array(
                'id'                => (string) $order_id,
                'amount'            => number_format( (float) $order->get_total(), 2, '.', '' ),
                'currency'          => $order->get_currency(),
                'description'       => sprintf( __( 'Pay for order #%d', 'merchant-payments-for-woocommerce' ), $order_id ),
                'reference'         => (string) $order_id,
                'customerOrderDate' => gmdate( 'Y-m-d' ),
            ),
            'interaction'  => array(
                'operation' => 'PURCHASE',
                'returnUrl' => add_query_arg(
                    array(
                        'wc-api'     => 'mpfw_gateway',
                        'order_id'   => $order_id,
                        'mpfw_nonce' => wp_create_nonce( 'mpfw_process_response' ),
                    ),
                    home_url( '/' )
                ),
                'merchant'  => array(
                    'name'    => $this->settings['merchant_name'] ? $this->settings['merchant_name'] : get_bloginfo( 'name' ),
                    'address' => array(
                        'line1' => $this->settings['merchant_address1'],
                        'line2' => $this->settings['merchant_address2'],
                    ),
                ),
            ),
            'transaction'  => array(
                'reference' => 'ORDER-' . $order_id,
                'source'    => 'INTERNET',
            ),
        );

        if ( $order->get_user_id() ) {
            if ( $api_version >= 62 ) {
                $payload['initiator'] = array(
                    'userId' => (string) $order->get_user_id(),
                );
            } else {
                $payload['userId'] = (string) $order->get_user_id();
            }
        }

        if ( $order->get_billing_email() && $order->get_billing_first_name() && $order->get_billing_last_name() ) {
            $payload['customer'] = array(
                'email'     => $order->get_billing_email(),
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
            );
        }

        return apply_filters( 'mpfw_checkout_session_request', $payload, $order, $this->settings );
    }

    /**
     * Create a checkout session.
     *
     * @param WC_Order $order Order object.
     * @return array|WP_Error
     */
    public function create_checkout_session( WC_Order $order ) {
        $request_url = $this->api_client->build_endpoint( $this->settings['checkout_api_version'], 'session' );
        $payload     = $this->build_session_request( $order );

        return $this->api_client->post( $request_url, $payload );
    }
}