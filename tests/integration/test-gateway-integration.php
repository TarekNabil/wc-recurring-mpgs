<?php

/**
 * Integration tests for gateway behavior against WP + WooCommerce test stack.
 *
 * @package WCRMPGS
 */
class WCRMPGS_Test_Gateway_Integration extends WP_UnitTestCase {

    /**
     * @var WCRMPGS_Gateway
     */
    private $gateway;

    /**
     * @var WC_Order
     */
    private $order;

    /**
     * @var int
     */
    private $product_id;

    public function setUp(): void {
        parent::setUp();

        if ( ! defined( 'WCRMPGS_VERSION' ) ) {
            define( 'WCRMPGS_VERSION', '0.1.0' );
        }

        if ( ! defined( 'WCRMPGS_PLUGIN_FILE' ) ) {
            define( 'WCRMPGS_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/wc-recurring-mpgs.php' );
        }

        if ( ! defined( 'WCRMPGS_PLUGIN_DIR' ) ) {
            define( 'WCRMPGS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
        }

        if ( ! defined( 'WCRMPGS_PLUGIN_URL' ) ) {
            define( 'WCRMPGS_PLUGIN_URL', 'http://example.com/wp-content/plugins/wc-recurring-mpgs/' );
        }

        if ( ! class_exists( 'WCRMPGS_Api_Client' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-api-client.php';
        }

        if ( ! class_exists( 'WCRMPGS_Hosted_Checkout_Service' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-hosted-checkout-service.php';
        }

        if ( ! class_exists( 'WCRMPGS_Recurring_Contract' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-recurring-contract.php';
        }

        if ( ! class_exists( 'WCRMPGS_Recurring_Service' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-recurring-service.php';
        }

        if ( ! class_exists( 'WCRMPGS_Webhook_Controller' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-webhook-controller.php';
        }

        if ( ! class_exists( 'WCRMPGS_Gateway' ) ) {
            require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-gateway.php';
        }

        update_option(
            'woocommerce_merchant_payments_settings',
            array(
                'enabled'                 => 'yes',
                'title'                   => 'MPGS',
                'description'             => 'Pay with MPGS',
                'service_host'            => 'https://gateway.test/',
                'merchant_id'             => 'merchant_123',
                'authentication_password' => 'secret_123',
                'checkout_api_version'    => '100',
                'recurring_api_version'   => '100',
                'merchant_name'           => 'Test Merchant',
                'merchant_address1'       => 'Address line 1',
                'merchant_address2'       => 'Address line 2',
                'debug_mode'              => 'no',
                'recurring_enabled'       => 'yes',
            )
        );

        $this->gateway = new WCRMPGS_Gateway();

        $product = new WC_Product_Simple();
        $product->set_name( 'MPGS Test Product' );
        $product->set_regular_price( '50' );
        $this->product_id = $product->save();

        $this->order = wc_create_order(
            array(
                'status' => 'pending',
            )
        );
        $this->order->add_product( wc_get_product( $this->product_id ), 1 );
        $this->order->set_payment_method( 'merchant_payments' );
        $this->order->set_billing_first_name( 'Jane' );
        $this->order->set_billing_last_name( 'Doe' );
        $this->order->set_billing_email( 'jane@example.test' );
        $this->order->calculate_totals();
        $this->order->save();
    }

    public function tearDown(): void {
        remove_all_filters( 'pre_http_request' );
        remove_all_filters( 'wp_die_handler' );
        $_GET = array();

        if ( $this->order instanceof WC_Order ) {
            $this->order->delete( true );
        }

        if ( $this->product_id ) {
            wp_delete_post( $this->product_id, true );
        }

        parent::tearDown();
    }

    public function test_process_payment_sets_session_meta_and_returns_redirect(): void {
        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/session' ) ) {
                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'           => 'SUCCESS',
                                'successIndicator' => 'indicator-123',
                                'session'          => array(
                                    'id'      => 'session-123',
                                    'version' => '1',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $result = $this->gateway->process_payment( $this->order->get_id() );

        $this->assertSame( 'success', $result['result'] );
        $this->assertStringContainsString( 'order-pay=' . $this->order->get_id(), $result['redirect'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'indicator-123', $order->get_meta( '_wcrmpgs_success_indicator', true ) );
        $this->assertSame( 'session-123', $order->get_meta( '_wcrmpgs_session_id', true ) );
        $this->assertSame( '1', $order->get_meta( '_wcrmpgs_session_version', true ) );
    }

    public function test_process_payment_returns_failure_on_gateway_error(): void {
        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/session' ) ) {
                    return new WP_Error( 'http_error', 'Gateway unavailable' );
                }

                return $preempt;
            },
            10,
            3
        );

        $result = $this->gateway->process_payment( $this->order->get_id() );

        $this->assertSame( 'failure', $result['result'] );
    }

    public function test_process_response_rejects_invalid_nonce(): void {
        $this->order->update_meta_data( '_wcrmpgs_success_indicator', 'indicator-abc' );
        $this->order->save();

        $_GET['order_id']      = $this->order->get_id();
        $_GET['key']           = $this->order->get_order_key();
        $_GET['wcrmpgs_nonce'] = 'invalid';

        add_filter(
            'wp_die_handler',
            function () {
                return function ( $message ) {
                    throw new Exception( wp_strip_all_tags( (string) $message ) );
                };
            }
        );

        $this->expectException( Exception::class );
        $this->expectExceptionMessage( 'Invalid callback request.' );

        $this->gateway->process_response();
    }

    public function test_process_response_rejects_payment_method_mismatch(): void {
        $this->order->set_payment_method( 'cod' );
        $this->order->save();

        $_GET['order_id']      = $this->order->get_id();
        $_GET['key']           = $this->order->get_order_key();
        $_GET['wcrmpgs_nonce'] = wp_create_nonce( 'wcrmpgs_process_response' );

        add_filter(
            'wp_die_handler',
            function () {
                return function ( $message ) {
                    throw new Exception( wp_strip_all_tags( (string) $message ) );
                };
            }
        );

        $this->expectException( Exception::class );
        $this->expectExceptionMessage( 'Invalid payment method callback.' );

        $this->gateway->process_response();
    }

    public function test_process_refund_returns_true_on_successful_provider_response(): void {
        $this->order->set_transaction_id( 'txn-original-123' );
        $this->order->save();

        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/transaction/' ) ) {
                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'      => 'SUCCESS',
                                'transaction' => array(
                                    'id' => 'txn-refund-999',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $result = $this->gateway->process_refund( $this->order->get_id(), 10.00, 'Customer requested refund' );

        $this->assertTrue( $result );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'txn-refund-999', $order->get_meta( '_wcrmpgs_last_refund_transaction_id', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_last_refund_payload', true ) );
    }

    public function test_process_refund_returns_error_when_transaction_id_is_missing(): void {
        $this->order->set_transaction_id( '' );
        $this->order->delete_meta_data( '_wcrmpgs_transaction_id' );
        $this->order->save();

        $result = $this->gateway->process_refund( $this->order->get_id(), 10.00, 'Missing transaction test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wcrmpgs_refund_missing_transaction', $result->get_error_code() );
    }

    public function test_finalize_callback_result_marks_order_paid_on_success(): void {
        $this->order->update_meta_data( '_wcrmpgs_success_indicator', 'expected-indicator-1' );
        $this->order->save();

        $verification = array(
            'result'          => 'SUCCESS',
            'resultIndicator' => 'expected-indicator-1',
            'transaction'     => array(
                'id' => 'txn-success-100',
            ),
        );

        $result = $this->invoke_protected_method(
            $this->gateway,
            'finalize_callback_result',
            array( $this->order, $verification, 'expected-indicator-1' )
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'SUCCESS', $result['result_code'] );
        $this->assertSame( 'expected-indicator-1', $result['result_indicator'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertTrue( $order->is_paid() );
        $this->assertSame( 'txn-success-100', $order->get_transaction_id() );
        $this->assertSame( 'expected-indicator-1', $order->get_meta( '_wcrmpgs_result_indicator', true ) );
        $this->assertSame( 'SUCCESS', $order->get_meta( '_wcrmpgs_result', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_callback_payload', true ) );
    }

    public function test_finalize_callback_result_persists_recurring_contract_meta_on_success(): void {
        $this->order->update_meta_data( '_wcrmpgs_success_indicator', 'expected-indicator-3' );
        $this->order->save();

        $verification = array(
            'result'          => 'SUCCESS',
            'resultIndicator' => 'expected-indicator-3',
            'transaction'     => array(
                'id' => 'txn-success-102',
            ),
            'sourceOfFunds'   => array(
                'provided' => array(
                    'card' => array(
                        'token' => 'tok_test_102',
                    ),
                ),
            ),
            'agreement'       => array(
                'id'                         => 'agree-test-102',
                'type'                       => 'MIT',
                'source'                     => 'MERCHANT',
                'numberOfPayments'           => 12,
                'amountVariability'          => 'FIXED',
                'expiryDate'                 => '2028-12-31',
                'paymentFrequency'           => 'MONTHLY',
                'minimumDaysBetweenPayments' => 28,
            ),
        );

        $result = $this->invoke_protected_method(
            $this->gateway,
            'finalize_callback_result',
            array( $this->order, $verification, 'expected-indicator-3' )
        );

        $this->assertTrue( $result['success'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'tok_test_102', $order->get_meta( WCRMPGS_Recurring_Contract::META_TOKEN, true ) );
        $this->assertSame( 'agree-test-102', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_ID, true ) );
        $this->assertSame( 'MIT', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_TYPE, true ) );
        $this->assertSame( 'MERCHANT', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_SOURCE, true ) );
        $this->assertSame( '12', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS, true ) );
        $this->assertSame( 'FIXED', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY, true ) );
        $this->assertSame( '2028-12-31', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE, true ) );
        $this->assertSame( 'MONTHLY', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY, true ) );
        $this->assertSame( '28', $order->get_meta( WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS, true ) );
        $this->assertNotEmpty( $order->get_meta( WCRMPGS_Recurring_Contract::META_CAPTURED_AT, true ) );
    }

    public function test_finalize_callback_result_marks_order_failed_on_indicator_mismatch(): void {
        $this->order->update_meta_data( '_wcrmpgs_success_indicator', 'expected-indicator-2' );
        $this->order->save();

        $verification = array(
            'result'          => 'SUCCESS',
            'resultIndicator' => 'different-indicator',
            'transaction'     => array(
                'id' => 'txn-fail-101',
            ),
        );

        $result = $this->invoke_protected_method(
            $this->gateway,
            'finalize_callback_result',
            array( $this->order, $verification, 'different-indicator' )
        );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'SUCCESS', $result['result_code'] );
        $this->assertSame( 'different-indicator', $result['result_indicator'] );
        $this->assertSame( 'Payment verification failed due to mismatched indicator.', $result['message'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertFalse( $order->is_paid() );
        $this->assertTrue( $order->has_status( 'failed' ) );
        $this->assertSame( 'different-indicator', $order->get_meta( '_wcrmpgs_result_indicator', true ) );
        $this->assertSame( 'SUCCESS', $order->get_meta( '_wcrmpgs_result', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_callback_payload', true ) );
    }

    public function test_process_manual_mit_charge_success_persists_attempt_meta(): void {
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_TOKEN, 'tok-manual-1' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_ID, 'agree-manual-1' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_TYPE, 'RECURRING' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_SOURCE, 'MERCHANT_INITIATED' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS, '5' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY, 'FIXED' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE, '2027-11-30' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY, 'MONTHLY' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS, '28' );
        $this->order->set_transaction_id( 'cit-txn-001' );
        $this->order->save();

        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/transaction/mit-manual-' ) ) {
                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'      => 'SUCCESS',
                                'response'    => array(
                                    'gatewayCode' => 'APPROVED',
                                ),
                                'transaction' => array(
                                    'id' => 'mit-txn-success-301',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $result = $this->invoke_protected_method(
            $this->gateway,
            'process_manual_mit_charge',
            array( $this->order )
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'mit-txn-success-301', $order->get_meta( '_wcrmpgs_last_mit_transaction_id', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_last_mit_attempted_at', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_last_mit_request', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_last_mit_response', true ) );

        $last_request = json_decode( $order->get_meta( '_wcrmpgs_last_mit_request', true ), true );
        $this->assertIsArray( $last_request );
        $this->assertSame( 'MERCHANT', $last_request['transaction']['source'] );
        $this->assertSame( 5, $last_request['agreement']['numberOfPayments'] );
        $this->assertSame( 'FIXED', $last_request['agreement']['amountVariability'] );
        $this->assertSame( '2027-11-30', $last_request['agreement']['expiryDate'] );
        $this->assertSame( 'MONTHLY', $last_request['agreement']['paymentFrequency'] );
        $this->assertSame( 28, $last_request['agreement']['minimumDaysBetweenPayments'] );
    }

    public function test_process_manual_mit_charge_returns_error_when_token_missing(): void {
        $this->order->delete_meta_data( WCRMPGS_Recurring_Contract::META_TOKEN );
        $this->order->save();

        $result = $this->invoke_protected_method(
            $this->gateway,
            'process_manual_mit_charge',
            array( $this->order )
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wcrmpgs_manual_mit_missing_token', $result->get_error_code() );
    }

    public function test_validate_manual_mit_admin_request_rejects_invalid_nonce(): void {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $_REQUEST['_wpnonce'] = 'invalid-nonce';

        $result = $this->invoke_protected_method(
            $this->gateway,
            'validate_manual_mit_admin_request',
            array( $this->order )
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wcrmpgs_manual_mit_invalid_nonce', $result->get_error_code() );

        wp_set_current_user( 0 );
    }

    public function test_process_renewal_mit_charge_success_persists_renewal_meta(): void {
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_TOKEN, 'tok-renewal-1' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_ID, 'agree-renewal-1' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS, '10' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY, 'FIXED' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE, '2028-10-31' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY, 'MONTHLY' );
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS, '28' );
        $this->order->set_transaction_id( 'cit-renewal-001' );
        $this->order->save();

        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/transaction/mit-renewal-' ) ) {
                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'      => 'SUCCESS',
                                'response'    => array(
                                    'gatewayCode' => 'APPROVED',
                                ),
                                'transaction' => array(
                                    'id' => 'mit-renewal-success-401',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $result = $this->invoke_protected_method(
            $this->gateway,
            'process_renewal_mit_charge',
            array( $this->order, 50.00 )
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'success', $order->get_meta( '_wcrmpgs_renewal_attempt_result', true ) );
        $this->assertSame( 'mit-renewal-success-401', $order->get_meta( '_wcrmpgs_renewal_attempt_transaction_id', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_renewal_attempted_at', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_renewal_attempt_request', true ) );
        $this->assertNotEmpty( $order->get_meta( '_wcrmpgs_renewal_attempt_response', true ) );

        $attempt_request = json_decode( $order->get_meta( '_wcrmpgs_renewal_attempt_request', true ), true );
        $this->assertIsArray( $attempt_request );
        $this->assertSame( 'MERCHANT', $attempt_request['transaction']['source'] );
        $this->assertSame( 10, $attempt_request['agreement']['numberOfPayments'] );
        $this->assertSame( 'FIXED', $attempt_request['agreement']['amountVariability'] );
        $this->assertSame( '2028-10-31', $attempt_request['agreement']['expiryDate'] );
        $this->assertSame( 'MONTHLY', $attempt_request['agreement']['paymentFrequency'] );
        $this->assertSame( 28, $attempt_request['agreement']['minimumDaysBetweenPayments'] );
    }

    public function test_process_renewal_mit_charge_allows_retry_after_failure(): void {
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_TOKEN, 'tok-renewal-retry' );
        $this->order->set_transaction_id( 'cit-renewal-retry-001' );
        $this->order->save();

        $attempt = 0;
        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) use ( &$attempt ) {
                if ( false !== strpos( $url, '/transaction/mit-renewal-' ) ) {
                    $attempt++;

                    if ( 1 === $attempt ) {
                        return array(
                            'headers'  => array(),
                            'body'     => wp_json_encode(
                                array(
                                    'result'   => 'FAILURE',
                                    'response' => array(
                                        'gatewayCode' => 'DECLINED',
                                    ),
                                    'error'    => array(
                                        'explanation' => 'Declined on first attempt',
                                    ),
                                )
                            ),
                            'response' => array( 'code' => 200, 'message' => 'OK' ),
                        );
                    }

                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'      => 'SUCCESS',
                                'response'    => array(
                                    'gatewayCode' => 'APPROVED',
                                ),
                                'transaction' => array(
                                    'id' => 'mit-renewal-retry-402',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $first_result = $this->invoke_protected_method(
            $this->gateway,
            'process_renewal_mit_charge',
            array( $this->order, 50.00 )
        );
        $this->assertInstanceOf( WP_Error::class, $first_result );

        $second_result = $this->invoke_protected_method(
            $this->gateway,
            'process_renewal_mit_charge',
            array( $this->order, 50.00 )
        );

        $this->assertIsArray( $second_result );
        $this->assertTrue( $second_result['success'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertSame( 'success', $order->get_meta( '_wcrmpgs_renewal_attempt_result', true ) );
    }

    public function test_process_renewal_mit_charge_blocks_duplicate_after_success(): void {
        $this->order->update_meta_data( WCRMPGS_Recurring_Contract::META_TOKEN, 'tok-renewal-dup' );
        $this->order->set_transaction_id( 'cit-renewal-dup-001' );
        $this->order->save();

        add_filter(
            'pre_http_request',
            function ( $preempt, $parsed_args, $url ) {
                if ( false !== strpos( $url, '/transaction/mit-renewal-' ) ) {
                    return array(
                        'headers'  => array(),
                        'body'     => wp_json_encode(
                            array(
                                'result'      => 'SUCCESS',
                                'response'    => array(
                                    'gatewayCode' => 'APPROVED',
                                ),
                                'transaction' => array(
                                    'id' => 'mit-renewal-dup-403',
                                ),
                            )
                        ),
                        'response' => array( 'code' => 200, 'message' => 'OK' ),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $first_result = $this->invoke_protected_method(
            $this->gateway,
            'process_renewal_mit_charge',
            array( $this->order, 50.00 )
        );
        $this->assertIsArray( $first_result );
        $this->assertTrue( $first_result['success'] );

        $second_result = $this->invoke_protected_method(
            $this->gateway,
            'process_renewal_mit_charge',
            array( $this->order, 50.00 )
        );

        $this->assertInstanceOf( WP_Error::class, $second_result );
        $this->assertSame( 'wcrmpgs_renewal_duplicate_attempt', $second_result->get_error_code() );
    }

    public function test_webhook_controller_ingests_success_and_ignores_duplicate_event(): void {
        $controller = new WCRMPGS_Webhook_Controller();

        $payload = array(
            'eventId'   => 'evt-webhook-001',
            'eventType' => 'PAYMENT.CAPTURED',
            'result'    => 'SUCCESS',
            'order'     => array(
                'id' => $this->order->get_id(),
            ),
            'transaction' => array(
                'id' => 'txn-webhook-001',
            ),
        );

        $first_result = $controller->ingest_payload( $payload );

        $this->assertTrue( $first_result['accepted'] );
        $this->assertSame( 'evt-webhook-001', $first_result['event_id'] );

        $order = wc_get_order( $this->order->get_id() );
        $this->assertTrue( $order->is_paid() );
        $this->assertSame( 'txn-webhook-001', $order->get_transaction_id() );

        $event_ids = json_decode( $order->get_meta( WCRMPGS_Webhook_Controller::META_WEBHOOK_EVENT_IDS, true ), true );
        $this->assertIsArray( $event_ids );
        $this->assertContains( 'evt-webhook-001', $event_ids );

        $duplicate_result = $controller->ingest_payload( $payload );

        $this->assertTrue( $duplicate_result['accepted'] );
        $this->assertSame( 'Duplicate webhook event ignored.', $duplicate_result['message'] );
    }

    /**
     * Call a protected method on target object.
     *
     * @param object $object Target object.
     * @param string $method_name Method name.
     * @param array  $params Method params.
     * @return mixed
     */
    private function invoke_protected_method( $object, $method_name, array $params = array() ) {
        $reflection = new ReflectionClass( get_class( $object ) );
        $method     = $reflection->getMethod( $method_name );
        $method->setAccessible( true );

        return $method->invokeArgs( $object, $params );
    }
}
