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
        $this->assertStringContainsString( 'sessionId=session-123', $result['redirect'] );

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
}
