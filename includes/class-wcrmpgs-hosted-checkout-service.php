<?php

/**
 * Hosted checkout request builder.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds initial CIT checkout payloads.
 */
class WCRMPGS_Hosted_Checkout_Service {

    /**
     * API client.
     *
    * @var WCRMPGS_Api_Client
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
    * @param WCRMPGS_Api_Client $api_client API client.
     * @param array           $settings Gateway settings.
     */
    public function __construct( WCRMPGS_Api_Client $api_client, array $settings ) {
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

        // Determine if this is a recurring checkout.
        $is_recurring = $this->is_recurring_order( $order );
        $operation    = apply_filters( 'wcrmpgs_checkout_operation', 'PURCHASE', $order, $this->settings, $is_recurring );

        $payload = array(
            'apiOperation' => $api_version >= 63 ? 'INITIATE_CHECKOUT' : 'CREATE_CHECKOUT_SESSION',
            'order'        => array(
                'id'                => (string) $order_id,
                'amount'            => number_format( (float) $order->get_total(), 2, '.', '' ),
                'currency'          => $order->get_currency(),
                'description'       => sprintf( __( 'Pay for order #%d', 'wc-recurring-mpgs' ), $order_id ),
                'reference'         => (string) $order_id,
                'customerOrderDate' => gmdate( 'Y-m-d' ),
            ),
            'interaction'  => array(
                'operation' => $operation,
                'returnUrl' => add_query_arg(
                    array(
                        'wc-api'     => 'wcrmpgs_gateway',
                        'order_id'   => $order_id,
                        'key'        => $order->get_order_key(),
                        'wcrmpgs_nonce' => wp_create_nonce( 'wcrmpgs_process_response' ),
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

        // Agreement injection is enabled by default for recurring orders to capture
        // reusable tokens. Processors require all Areeba-spec agreement fields.
        $enable_checkout_agreement = (bool) apply_filters( 'wcrmpgs_enable_checkout_agreement', $is_recurring, $order, $this->settings, $is_recurring );
        if ( $enable_checkout_agreement ) {
            $agreement = $this->build_agreement_config( $order );
            if ( ! empty( $agreement ) ) {
                $payload['agreement'] = $agreement;
            }
        }

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

        return apply_filters( 'wcrmpgs_checkout_session_request', $payload, $order, $this->settings );
    }

    /**
     * Check if an order is for a recurring/subscription payment.
     *
     * @param WC_Order $order Order object.
     * @return bool
     */
    private function is_recurring_order( WC_Order $order ) {
        // Check if recurring is disabled in gateway settings
        $recurring_enabled = isset( $this->settings['recurring_enabled'] ) ? $this->settings['recurring_enabled'] : 'yes';
        if ( 'yes' !== $recurring_enabled ) {
            return false;
        }

        // Check if the order has associated subscriptions
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return false;
        }

        $subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
        return ! empty( $subscriptions ) && is_array( $subscriptions );
    }

    /**
     * Build the agreement configuration from subscription data.
     *
     * Areeba API v100 requires these agreement fields for CIT checkout:
     * - agreement.id
     * - agreement.type=RECURRING
     * - agreement.numberOfPayments
     * - agreement.amountVariability
     * - agreement.expiryDate
     * - agreement.paymentFrequency
     * - agreement.minimumDaysBetweenPayments
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    private function build_agreement_config( WC_Order $order ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return array();
        }

        $subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
        if ( empty( $subscriptions ) || ! is_array( $subscriptions ) ) {
            return array();
        }

        // Use the first subscription's configuration
        $subscription = reset( $subscriptions );

        if ( ! is_object( $subscription ) ) {
            return array();
        }

        $agreement = array(
            'id'   => 'agr-' . (string) $order->get_id() . '-' . gmdate( 'YmdHis' ),
            'type' => 'RECURRING',
        );

        // amountVariability - fixed for most subscriptions
        $agreement['amountVariability'] = 'FIXED';

        // numberOfPayments - from WC_Subscription::get_payment_count()
        if ( method_exists( $subscription, 'get_payment_count' ) ) {
            $payment_count = (int) $subscription->get_payment_count();
            if ( $payment_count > 0 ) {
                $agreement['numberOfPayments'] = $payment_count;
            }
        }

        // paymentFrequency - derive from billing period + interval
        $payment_frequency = $this->get_payment_frequency( $subscription );
        if ( ! empty( $payment_frequency ) ) {
            $agreement['paymentFrequency'] = $payment_frequency;
        }

        // minimumDaysBetweenPayments - derive from billing interval
        $min_days = $this->get_minimum_days_between_payments( $subscription );
        if ( $min_days > 0 ) {
            $agreement['minimumDaysBetweenPayments'] = $min_days;
        }

        // expiryDate - calculate from subscription or add 1 year as default
        $expiry_date = $this->get_agreement_expiry_date( $subscription );
        if ( ! empty( $expiry_date ) ) {
            $agreement['expiryDate'] = $expiry_date;
        }

        return apply_filters( 'wcrmpgs_checkout_agreement_config', $agreement, $subscription, $order );
    }

    /**
     * Get payment frequency string for agreement (e.g., "MONTHLY", "QUARTERLY").
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return string
     */
    private function get_payment_frequency( $subscription ) {
        if ( ! method_exists( $subscription, 'get_billing_period' ) ) {
            return '';
        }

        $period = $subscription->get_billing_period();
        $frequency_map = array(
            'day'   => 'DAILY',
            'week'  => 'WEEKLY',
            'month' => 'MONTHLY',
            'year'  => 'YEARLY',
        );

        return isset( $frequency_map[ $period ] ) ? $frequency_map[ $period ] : 'MONTHLY';
    }

    /**
     * Get minimum days between payments for agreement.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return int
     */
    private function get_minimum_days_between_payments( $subscription ) {
        if ( ! method_exists( $subscription, 'get_billing_period' ) || ! method_exists( $subscription, 'get_billing_interval' ) ) {
            return 0;
        }

        $period   = $subscription->get_billing_period();
        $interval = (int) $subscription->get_billing_interval();

        // Map billing period to days
        $days_per_period = array(
            'day'   => 1,
            'week'  => 7,
            'month' => 30,
            'year'  => 365,
        );

        $base_days = isset( $days_per_period[ $period ] ) ? $days_per_period[ $period ] : 30;
        return max( 1, $base_days * $interval );
    }

    /**
     * Get agreement expiry date (subscription end date or 1 year from now).
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return string Date in YYYY-MM-DD format
     */
    private function get_agreement_expiry_date( $subscription ) {
        if ( method_exists( $subscription, 'get_date_to_display' ) ) {
            $end_date = $subscription->get_date_to_display( 'end' );
            if ( ! empty( $end_date ) ) {
                // get_date_to_display returns formatted string, convert to timestamp
                $timestamp = strtotime( $end_date );
                if ( false !== $timestamp ) {
                    return gmdate( 'Y-m-d', $timestamp );
                }
            }
        }

        // Default to 1 year from now if no explicit end date
        return gmdate( 'Y-m-d', strtotime( '+1 year' ) );
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