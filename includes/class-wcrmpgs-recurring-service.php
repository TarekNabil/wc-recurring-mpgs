<?php

/**
 * Recurring MIT request builder and response normalizer.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles recurring MIT PAY payloads (API version 100).
 */
class WCRMPGS_Recurring_Service {

    /**
     * API client.
     *
     * @var WCRMPGS_Api_Client
     */
    private $api_client;

    /**
     * Service settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param WCRMPGS_Api_Client $api_client API client.
     * @param array              $settings Service settings.
     */
    public function __construct( WCRMPGS_Api_Client $api_client, array $settings ) {
        $this->api_client = $api_client;
        $this->settings   = $settings;
    }

    /**
     * Build MIT PAY request payload from normalized input.
     *
     * Required input keys:
     * - order_id
     * - amount
     * - currency
     * - token
     * - transaction_id
     *
     * @param array $input MIT input data.
     * @return array|WP_Error
     */
    public function build_mit_pay_request( array $input ) {
        $validation = $this->validate_mit_input( $input );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $order_id                = (string) $input['order_id'];
        $merchant_transaction_id = ! empty( $input['merchant_transaction_id'] ) ? (string) $input['merchant_transaction_id'] : 'mit-' . $order_id . '-' . gmdate( 'YmdHis' );
        $agreement_type          = ! empty( $input['agreement_type'] ) ? (string) $input['agreement_type'] : 'RECURRING';

        $payload = array(
            'apiOperation' => 'PAY',
            'order'        => array(
                'id'        => $order_id,
                'amount'    => number_format( (float) $input['amount'], 2, '.', '' ),
                'currency'  => strtoupper( (string) $input['currency'] ),
                'reference' => ! empty( $input['order_reference'] ) ? (string) $input['order_reference'] : $order_id,
            ),
            'transaction'  => array(
                'reference' => $merchant_transaction_id,
                'source'    => 'MERCHANT',
            ),
            'sourceOfFunds' => array(
                'type'  => 'CARD',
                'token' => (string) $input['token'],
            ),
            'initiator'    => array(
                'type' => 'MERCHANT',
            ),
        );

        if ( ! empty( $input['agreement_id'] ) ) {
            $payload['agreement'] = array(
                'id'     => (string) $input['agreement_id'],
                'type'   => $agreement_type,
            );

            if ( '' !== trim( (string) ( $input['agreement_number_of_payments'] ?? '' ) ) ) {
                $payload['agreement']['numberOfPayments'] = (int) $input['agreement_number_of_payments'];
            }

            if ( '' !== trim( (string) ( $input['agreement_amount_variability'] ?? '' ) ) ) {
                $payload['agreement']['amountVariability'] = (string) $input['agreement_amount_variability'];
            }

            if ( '' !== trim( (string) ( $input['agreement_expiry_date'] ?? '' ) ) ) {
                $payload['agreement']['expiryDate'] = (string) $input['agreement_expiry_date'];
            }

            if ( '' !== trim( (string) ( $input['agreement_payment_frequency'] ?? '' ) ) ) {
                $payload['agreement']['paymentFrequency'] = (string) $input['agreement_payment_frequency'];
            }

            if ( '' !== trim( (string) ( $input['agreement_minimum_days_between_payments'] ?? '' ) ) ) {
                $payload['agreement']['minimumDaysBetweenPayments'] = (int) $input['agreement_minimum_days_between_payments'];
            }
        }

        return apply_filters( 'wcrmpgs_mit_pay_request', $payload, $input, $this->settings );
    }

    /**
     * Build recurring MIT endpoint.
     *
     * @param string $order_id Order ID.
     * @param string $transaction_id Transaction ID.
     * @return string
     */
    public function build_mit_endpoint( $order_id, $transaction_id ) {
        $version = ! empty( $this->settings['recurring_api_version'] ) ? (string) $this->settings['recurring_api_version'] : '100';

        return $this->api_client->build_endpoint(
            $version,
            'order/' . rawurlencode( (string) $order_id ) . '/transaction/' . rawurlencode( (string) $transaction_id )
        );
    }

    /**
     * Normalize provider response for MIT attempts.
     *
     * @param array $response Provider response payload.
     * @return array{success:bool,result_code:string,gateway_code:string,transaction_id:string,error_code:string,message:string}
     */
    public function normalize_mit_response( array $response ) {
        $result_code    = strtoupper( (string) ( $response['result'] ?? '' ) );
        $gateway_code   = strtoupper( (string) ( $response['response']['gatewayCode'] ?? '' ) );
        $is_success     = 'SUCCESS' === $result_code;
        $transaction_id = '';

        if ( ! empty( $response['transaction']['id'] ) ) {
            $transaction_id = (string) $response['transaction']['id'];
        } elseif ( ! empty( $response['id'] ) ) {
            $transaction_id = (string) $response['id'];
        }

        $message = '';
        if ( ! empty( $response['error']['explanation'] ) ) {
            $message = (string) $response['error']['explanation'];
        } elseif ( ! empty( $response['response']['acquirerMessage'] ) ) {
            $message = (string) $response['response']['acquirerMessage'];
        } elseif ( ! $is_success ) {
            $message = __( 'Recurring MIT payment was rejected.', 'wc-recurring-mpgs' );
        }

        return array(
            'success'        => $is_success,
            'result_code'    => $result_code,
            'gateway_code'   => $gateway_code,
            'transaction_id' => $transaction_id,
            'error_code'     => $is_success ? '' : $this->map_gateway_error_code( $gateway_code ),
            'message'        => $message,
        );
    }

    /**
     * Validate MIT request input.
     *
     * @param array $input MIT input data.
     * @return true|WP_Error
     */
    private function validate_mit_input( array $input ) {
        $required = array( 'order_id', 'amount', 'currency', 'token', 'transaction_id' );
        $missing  = array();

        foreach ( $required as $field ) {
            if ( ! isset( $input[ $field ] ) || '' === trim( (string) $input[ $field ] ) ) {
                $missing[] = $field;
            }
        }

        if ( ! empty( $missing ) ) {
            return new WP_Error(
                'wcrmpgs_mit_validation_missing_fields',
                sprintf( __( 'Missing required MIT fields: %s', 'wc-recurring-mpgs' ), implode( ', ', $missing ) )
            );
        }

        if ( ! is_numeric( $input['amount'] ) || (float) $input['amount'] <= 0 ) {
            return new WP_Error( 'wcrmpgs_mit_validation_invalid_amount', __( 'MIT amount must be greater than zero.', 'wc-recurring-mpgs' ) );
        }

        $currency = strtoupper( (string) $input['currency'] );
        if ( 3 !== strlen( $currency ) || ! ctype_alpha( $currency ) ) {
            return new WP_Error( 'wcrmpgs_mit_validation_invalid_currency', __( 'MIT currency must be a 3-letter ISO code.', 'wc-recurring-mpgs' ) );
        }

        return true;
    }

    /**
     * Map provider gateway code to stable internal error code.
     *
     * @param string $gateway_code Gateway response code.
     * @return string
     */
    private function map_gateway_error_code( $gateway_code ) {
        $code = strtoupper( (string) $gateway_code );

        $map = array(
            'DECLINED'           => 'card_declined',
            'EXPIRED_CARD'       => 'expired_card',
            'INSUFFICIENT_FUNDS' => 'insufficient_funds',
            'TIMED_OUT'          => 'gateway_timeout',
            'SYSTEM_ERROR'       => 'provider_system_error',
        );

        return $map[ $code ] ?? 'provider_rejected';
    }
}