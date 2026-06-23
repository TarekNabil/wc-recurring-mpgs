<?php

/**
 * Webhook and notification ingestion controller.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ingests provider webhook/notification payloads and applies lightweight order reconciliation.
 */
class WCRMPGS_Webhook_Controller {

    /**
     * Order meta key for processed webhook event identifiers.
     */
    const META_WEBHOOK_EVENT_IDS = '_wcrmpgs_webhook_event_ids';

    /**
     * Order meta key for the last webhook payload.
     */
    const META_WEBHOOK_LAST_PAYLOAD = '_wcrmpgs_webhook_last_payload';

    /**
     * Order meta key for the last webhook event type.
     */
    const META_WEBHOOK_LAST_EVENT_TYPE = '_wcrmpgs_webhook_last_event_type';

    /**
     * Order meta key for the last webhook timestamp.
     */
    const META_WEBHOOK_RECEIVED_AT = '_wcrmpgs_webhook_received_at';

    /**
     * Order meta key for the last webhook error (if any).
     */
    const META_WEBHOOK_LAST_ERROR = '_wcrmpgs_webhook_last_error';

    /**
     * Order meta key for retry guidance (seconds).
     */
    const META_WEBHOOK_RETRY_AFTER = '_wcrmpgs_webhook_retry_after_seconds';

    /**
     * Logger instance.
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->logger = wc_get_logger();

        add_action( 'woocommerce_api_wcrmpgs_webhook', array( $this, 'handle_request' ) );
        add_action( 'woocommerce_api_wcrmpgs_notification', array( $this, 'handle_request' ) );
    }

    /**
     * Handle a webhook/notification request from the provider.
     *
     * @return void
     */
    public function handle_request() {
        $payload = $this->read_request_payload();
        $result   = $this->ingest_payload( $payload );

        $http_code = 200;
        if ( ! empty( $result['transient_error'] ) ) {
            $http_code = 503;
        } elseif ( empty( $result['accepted'] ) ) {
            $http_code = 400;
        }

        if ( 200 === $http_code ) {
            wp_send_json_success( $result );
        }

        wp_send_json_error( $result, $http_code );
    }

    /**
     * Ingest a provider webhook/notification payload.
     *
     * This method is intentionally testable without HTTP.
     *
     * @param array $payload Normalized request payload.
     * @return array{accepted:bool,order_id:int,event_id:string,event_type:string,result:string,message:string}
     */
    public function ingest_payload( array $payload ) {
        $normalized = $this->normalize_payload( $payload );

        if ( empty( $normalized['event_id'] ) ) {
            $normalized['event_id'] = $this->build_fallback_event_id( $normalized );
        }

        if ( empty( $normalized['order_id'] ) ) {
            $this->log_event( 'webhook_ignored_missing_order', $normalized );
            return array_merge(
                $normalized,
                array(
                    'accepted' => true,
                    'message'  => __( 'Webhook accepted without order match.', 'wc-recurring-mpgs' ),
                )
            );
        }

        $order = wc_get_order( $normalized['order_id'] );
        if ( ! $order instanceof WC_Order ) {
            $this->log_event( 'webhook_ignored_unknown_order', $normalized );
            return array_merge(
                $normalized,
                array(
                    'accepted' => true,
                    'message'  => __( 'Webhook accepted without matching order.', 'wc-recurring-mpgs' ),
                )
            );
        }

        $payment_method = method_exists( $order, 'get_payment_method' ) ? (string) $order->get_payment_method() : '';
        if ( $payment_method && 'merchant_payments' !== $payment_method ) {
            $this->log_event(
                'webhook_ignored_payment_method_mismatch',
                array_merge(
                    $normalized,
                    array( 'payment_method' => $payment_method )
                )
            );

            return array_merge(
                $normalized,
                array(
                    'accepted' => false,
                    'message'  => __( 'Webhook order payment method mismatch.', 'wc-recurring-mpgs' ),
                )
            );
        }

        if ( $this->has_processed_event( $order, $normalized['event_id'] ) ) {
            $this->log_event( 'webhook_duplicate_event', $normalized );
            return array_merge(
                $normalized,
                array(
                    'accepted' => true,
                    'message'  => __( 'Duplicate webhook event ignored.', 'wc-recurring-mpgs' ),
                )
            );
        }

        $order->update_meta_data( self::META_WEBHOOK_LAST_PAYLOAD, wp_json_encode( $payload ) );
        $order->update_meta_data( self::META_WEBHOOK_LAST_EVENT_TYPE, $normalized['event_type'] );
        $order->update_meta_data( self::META_WEBHOOK_RECEIVED_AT, gmdate( 'Y-m-d H:i:s' ) );
        $order->delete_meta_data( self::META_WEBHOOK_LAST_ERROR );
        $order->delete_meta_data( self::META_WEBHOOK_RETRY_AFTER );
        $this->remember_event( $order, $normalized['event_id'] );

        $result_code = strtoupper( $normalized['result'] );
        $transaction_id = $normalized['transaction_id'];

        if ( in_array( $result_code, array( 'SUCCESS', 'APPROVED', 'CAPTURED', 'PAID', 'COMPLETED' ), true ) ) {
            if ( $transaction_id && ! $order->get_transaction_id() ) {
                $order->set_transaction_id( $transaction_id );
                $order->update_meta_data( '_wcrmpgs_transaction_id', $transaction_id );
            }

            if ( ! $order->is_paid() ) {
                $order->payment_complete( $transaction_id );
            }

            $order->add_order_note(
                sprintf(
                    __( 'MPGS webhook notification marked payment successful. Event: %1$s, Transaction: %2$s', 'wc-recurring-mpgs' ),
                    $normalized['event_type'] ? $normalized['event_type'] : __( 'N/A', 'wc-recurring-mpgs' ),
                    $transaction_id ? $transaction_id : __( 'N/A', 'wc-recurring-mpgs' )
                )
            );
            $order->save();

            $this->log_event( 'webhook_payment_success', $normalized );

            return array_merge(
                $normalized,
                array(
                    'accepted' => true,
                    'message'  => __( 'Webhook reconciled payment successfully.', 'wc-recurring-mpgs' ),
                )
            );
        }

        if ( in_array( $result_code, array( 'FAILURE', 'DECLINED', 'REJECTED', 'ERROR', 'CANCELLED' ), true ) ) {
            if ( ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
                $order->update_status( 'failed', __( 'Payment notification reported failure.', 'wc-recurring-mpgs' ) );
            }

            $order->add_order_note(
                sprintf(
                    __( 'MPGS webhook notification reported failure. Event: %1$s, Result: %2$s', 'wc-recurring-mpgs' ),
                    $normalized['event_type'] ? $normalized['event_type'] : __( 'N/A', 'wc-recurring-mpgs' ),
                    $result_code
                )
            );
            $order->save();

            $this->log_event( 'webhook_payment_failure', $normalized );

            return array_merge(
                $normalized,
                array(
                    'accepted' => true,
                    'message'  => __( 'Webhook recorded failure state.', 'wc-recurring-mpgs' ),
                )
            );
        }

        if ( ! in_array( $result_code, array( 'SUCCESS', 'APPROVED', 'CAPTURED', 'PAID', 'COMPLETED', 'FAILURE', 'DECLINED', 'REJECTED', 'ERROR', 'CANCELLED' ), true ) ) {
            $is_transient = $this->is_transient_error( $result_code );
            $order->update_meta_data( self::META_WEBHOOK_LAST_ERROR, $result_code );
            if ( $is_transient ) {
                $order->update_meta_data( self::META_WEBHOOK_RETRY_AFTER, 60 );
            }
            $order->add_order_note(
                sprintf(
                    __( 'MPGS webhook notification received with unknown result code: %1$s (Result: %2$s, Retryable: %3$s)', 'wc-recurring-mpgs' ),
                    $result_code,
                    $normalized['event_type'] ? $normalized['event_type'] : __( 'N/A', 'wc-recurring-mpgs' ),
                    $is_transient ? __( 'Yes (provider should retry)', 'wc-recurring-mpgs' ) : __( 'No (permanent result)', 'wc-recurring-mpgs' )
                )
            );
            $order->save();

            $this->log_event( 'webhook_unknown_result', array_merge( $normalized, array( 'transient' => $is_transient ) ) );

            return array_merge(
                $normalized,
                array(
                    'accepted' => false,
                    'transient_error' => $is_transient,
                    'message' => $is_transient ? __( 'Webhook received with transient error. Retry requested.', 'wc-recurring-mpgs' ) : __( 'Webhook received with unknown permanent result.', 'wc-recurring-mpgs' ),
                )
            );
        }

        $order->add_order_note(
            sprintf(
                __( 'MPGS webhook notification received. Event: %1$s, Result: %2$s', 'wc-recurring-mpgs' ),
                $normalized['event_type'] ? $normalized['event_type'] : __( 'N/A', 'wc-recurring-mpgs' ),
                $result_code ? $result_code : __( 'N/A', 'wc-recurring-mpgs' )
            )
        );
        $order->save();

        $this->log_event( 'webhook_received', $normalized );

        return array_merge(
            $normalized,
            array(
                'accepted' => true,
                'message'  => __( 'Webhook ingested without state change.', 'wc-recurring-mpgs' ),
            )
        );
    }

    /**
     * Normalize arbitrary provider payload fields.
     *
     * @param array $payload Raw payload.
     * @return array{order_id:int,event_id:string,event_type:string,result:string,transaction_id:string}
     */
    public function normalize_payload( array $payload ) {
        $order_id = absint(
            $this->read_first_value(
                $payload,
                array(
                    array( 'order_id' ),
                    array( 'orderId' ),
                    array( 'order', 'id' ),
                    array( 'order', 'reference' ),
                    array( 'merchantOrderId' ),
                )
            )
        );

        $event_id = (string) $this->read_first_value(
            $payload,
            array(
                array( 'eventId' ),
                array( 'notificationId' ),
                array( 'id' ),
            )
        );

        $event_type = (string) $this->read_first_value(
            $payload,
            array(
                array( 'eventType' ),
                array( 'notificationType' ),
                array( 'type' ),
                array( 'transaction', 'type' ),
            )
        );

        $result = (string) $this->read_first_value(
            $payload,
            array(
                array( 'result' ),
                array( 'status' ),
                array( 'transaction', 'result' ),
                array( 'transaction', 'status' ),
            )
        );

        $transaction_id = (string) $this->read_first_value(
            $payload,
            array(
                array( 'transaction', 'id' ),
                array( 'transactionId' ),
                array( 'id' ),
            )
        );

        return array(
            'order_id'       => $order_id,
            'event_id'       => $event_id,
            'event_type'     => $event_type,
            'result'         => $result,
            'transaction_id' => $transaction_id,
        );
    }

    /**
     * Read the request body as an array.
     *
     * @return array
     */
    protected function read_request_payload() {
        $raw_body = '';

        if ( isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) && is_string( $GLOBALS['HTTP_RAW_POST_DATA'] ) ) {
            $raw_body = $GLOBALS['HTTP_RAW_POST_DATA'];
        } elseif ( isset( $_POST ) && ! empty( $_POST ) ) {
            $raw_body = wp_json_encode( wp_unslash( $_POST ) );
        } elseif ( function_exists( 'file_get_contents' ) ) {
            $raw_body = (string) file_get_contents( 'php://input' );
        }

        $decoded = json_decode( $raw_body, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        if ( isset( $_POST ) && is_array( $_POST ) ) {
            return wp_unslash( $_POST );
        }

        return array();
    }

    /**
     * Check whether an event identifier was already processed.
     *
     * @param WC_Order $order Order object.
     * @param string   $event_id Event identifier.
     * @return bool
     */
    protected function has_processed_event( WC_Order $order, $event_id ) {
        if ( '' === trim( (string) $event_id ) ) {
            return false;
        }

        $processed = $this->get_processed_event_ids( $order );

        return in_array( (string) $event_id, $processed, true );
    }

    /**
     * Remember that an event has been processed.
     *
     * @param WC_Order $order Order object.
     * @param string   $event_id Event identifier.
     * @return void
     */
    protected function remember_event( WC_Order $order, $event_id ) {
        if ( '' === trim( (string) $event_id ) ) {
            return;
        }

        $processed = $this->get_processed_event_ids( $order );
        if ( in_array( (string) $event_id, $processed, true ) ) {
            return;
        }

        $processed[] = (string) $event_id;
        $processed    = array_slice( array_values( array_unique( $processed ) ), -25 );

        $order->update_meta_data( self::META_WEBHOOK_EVENT_IDS, wp_json_encode( $processed ) );
    }

    /**
     * Get processed event IDs from order meta.
     *
     * @param WC_Order $order Order object.
     * @return array<int,string>
     */
    protected function get_processed_event_ids( WC_Order $order ) {
        $raw = (string) $order->get_meta( self::META_WEBHOOK_EVENT_IDS, true );

        if ( '' === $raw ) {
            return array();
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        return array_values(
            array_filter(
                array_map(
                    static function ( $value ) {
                        return trim( (string) $value );
                    },
                    $decoded
                )
            )
        );
    }

    /**
     * Read first nested value matching the provided key paths.
     *
     * @param array $payload Raw payload.
     * @param array $paths Candidate nested paths.
     * @return mixed
     */
    protected function read_first_value( array $payload, array $paths ) {
        foreach ( $paths as $path ) {
            $cursor = $payload;

            foreach ( $path as $segment ) {
                if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                    continue 2;
                }

                $cursor = $cursor[ $segment ];
            }

            if ( null !== $cursor && '' !== $cursor ) {
                return $cursor;
            }
        }

        return '';
    }

    /**
     * Build a fallback event ID when the provider does not send one.
     *
     * @param array $normalized Normalized payload.
     * @return string
     */
    protected function build_fallback_event_id( array $normalized ) {
        $parts = array(
            (string) ( $normalized['order_id'] ?? 0 ),
            (string) ( $normalized['event_type'] ?? '' ),
            (string) ( $normalized['result'] ?? '' ),
            (string) ( $normalized['transaction_id'] ?? '' ),
        );

        return 'wcrmpgs-webhook-' . md5( implode( '|', $parts ) );
    }

    /**
     * Determine whether a result code indicates a transient error that should be retried.
     *
     * Transient errors are those where the provider should retry delivery later.
     *
     * @param string $result_code Result code from provider.
     * @return bool
     */
    protected function is_transient_error( $result_code ) {
        $transient_codes = array(
            'PROCESSING',
            'PENDING',
            'TIMEOUT',
            'TEMPORARILY_UNAVAILABLE',
            'SERVICE_UNAVAILABLE',
            'NETWORK_ERROR',
            'TRY_AGAIN',
            'RETRY',
        );

        return in_array( strtoupper( (string) $result_code ), $transient_codes, true );
    }

    /**
     * Log webhook events to the WooCommerce logger.
     *
     * @param string $event Event key.
     * @param array  $context Context data.
     * @return void
     */
    protected function log_event( $event, array $context = array() ) {
        $this->logger->info(
            $event,
            array(
                'source'  => 'wc-recurring-mpgs-webhook',
                'context' => $context,
            )
        );
    }

    /**
     * Extract a sanitized integer order ID from the payload.
     *
     * @param array $payload Raw payload.
     * @return int
     */
    public function extract_order_id( array $payload ) {
        return absint(
            $this->read_first_value(
                $payload,
                array(
                    array( 'order_id' ),
                    array( 'orderId' ),
                    array( 'order', 'id' ),
                    array( 'order', 'reference' ),
                )
            )
        );
    }
}