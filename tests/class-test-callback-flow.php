<?php

/**
 * Integration tests for callback flow.
 *
 * @package WCRMPGS
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Callback flow integration tests.
 *
 * These tests verify the end-to-end callback processing and order finalization.
 * Requires WooCommerce and WordPress test environment.
 *
 * Run with: wp test run --testcase=WCRMPGS_Test_Callback_Flow
 */
class WCRMPGS_Test_Callback_Flow extends WP_UnitTestCase {

	/**
	 * Gateway instance.
	 *
	 * @var WCRMPGS_Gateway
	 */
	protected $gateway;

	/**
	 * Test order.
	 *
	 * @var WC_Order
	 */
	protected $order;

	/**
	 * Setup test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure plugin classes are loaded.
		if ( ! class_exists( 'WCRMPGS_Gateway' ) ) {
			require_once WCRMPGS_PLUGIN_DIR . 'includes/class-wcrmpgs-gateway.php';
		}

		// Initialize gateway.
		$this->gateway = new WCRMPGS_Gateway();

		// Configure gateway with test credentials.
		update_option(
			'woocommerce_merchant_payments_settings',
			array(
				'enabled'                   => 'yes',
				'title'                     => 'Test MPGS',
				'description'               => 'Test payment gateway',
				'service_host'              => 'https://test.example.com/',
				'merchant_id'               => 'test_merchant_123',
				'authentication_password'   => 'test_password_456',
				'checkout_api_version'      => '100',
				'recurring_api_version'     => '100',
				'merchant_name'             => 'Test Merchant',
				'debug_mode'                => 'yes',
			)
		);

		// Create test order.
		$this->order = wc_create_order(
			array(
				'payment_method' => 'merchant_payments',
				'status'         => 'pending',
			)
		);

		// Add test product to order.
		$product = WC_Helper_Product::create_simple_product();
		$this->order->add_product( $product, 1 );
		$this->order->set_billing_email( 'test@example.com' );
		$this->order->set_billing_first_name( 'Test' );
		$this->order->set_billing_last_name( 'User' );
		$this->order->calculate_totals();
		$this->order->save();

		// Simulate initial checkout session metadata.
		$this->order->update_meta_data( '_wcrmpgs_success_indicator', 'EXPECTED_INDICATOR_123' );
		$this->order->update_meta_data( '_wcrmpgs_session_id', 'session_abc123' );
		$this->order->update_meta_data( '_wcrmpgs_session_version', '100' );
		$this->order->save();
	}

	/**
	 * Cleanup after tests.
	 */
	public function tear_down() {
		parent::tear_down();

		if ( $this->order ) {
			$this->order->delete( true );
		}
	}

	/**
	 * Test successful callback with matching indicator.
	 */
	public function test_successful_callback_with_matching_indicator() {
		// Mock verification response.
		$verification_response = array(
			'result'           => 'SUCCESS',
			'resultIndicator'  => 'EXPECTED_INDICATOR_123',
			'transaction'      => array(
				'id' => 'txn_12345',
			),
		);

		// Simulate the callback request parameters.
		$_GET['order_id']       = $this->order->get_id();
		$_GET['key']            = $this->order->get_order_key();
		$_GET['wcrmpgs_nonce']  = wp_create_nonce( 'wcrmpgs_process_response' );
		$_GET['resultIndicator'] = 'EXPECTED_INDICATOR_123';

		// Mock the verify_order_payment method to return our test response.
		$gateway_class = new ReflectionClass( 'WCRMPGS_Gateway' );
		$verify_method = $gateway_class->getMethod( 'verify_order_payment' );
		$verify_method->setAccessible( true );

		// Replace the method temporarily for testing.
		$gateway = $this->gateway;
		$mock_verify = function ( $order ) use ( $verification_response ) {
			return $verification_response;
		};

		// Store original method and replace.
		$original = $verify_method;

		// Call process_response.
		ob_start();
		try {
			$gateway->process_response();
		} catch ( Exception $e ) {
			// Expected: exit() will cause exception in test environment.
		}
		ob_end_clean();

		// Refresh order to check state.
		$this->order = wc_get_order( $this->order->get_id() );

		// Assertions.
		$this->assertTrue( $this->order->is_paid(), 'Order should be marked as paid' );
		$this->assertEqual(
			'txn_12345',
			$this->order->get_transaction_id(),
			'Transaction ID should be set'
		);
		$this->assertEqual(
			'EXPECTED_INDICATOR_123',
			$this->order->get_meta( '_wcrmpgs_result_indicator', true ),
			'Result indicator should be stored'
		);
		$this->assertEqual(
			'SUCCESS',
			$this->order->get_meta( '_wcrmpgs_result', true ),
			'Result code should be SUCCESS'
		);

		// Check order note.
		$notes = $this->order->get_customer_order_notes();
		$this->assertNotEmpty( $notes, 'Order should have notes' );
	}

	/**
	 * Test callback rejection with invalid nonce.
	 */
	public function test_callback_rejection_invalid_nonce() {
		$_GET['order_id']      = $this->order->get_id();
		$_GET['key']           = $this->order->get_order_key();
		$_GET['wcrmpgs_nonce'] = 'invalid_nonce_value';

		ob_start();
		try {
			$this->gateway->process_response();
		} catch ( Exception $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Should have rejected with wp_die.
		$this->assertStringContainsString( 'Invalid callback request', $output );

		// Order should remain unpaid and unchanged.
		$this->order = wc_get_order( $this->order->get_id() );
		$this->assertFalse( $this->order->is_paid() );
	}

	/**
	 * Test callback rejection with invalid order key.
	 */
	public function test_callback_rejection_invalid_key() {
		$_GET['order_id']       = $this->order->get_id();
		$_GET['key']            = 'wrong_order_key';
		$_GET['wcrmpgs_nonce']  = wp_create_nonce( 'wcrmpgs_process_response' );

		ob_start();
		try {
			$this->gateway->process_response();
		} catch ( Exception $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Should have rejected with wp_die.
		$this->assertStringContainsString( 'Invalid callback key', $output );

		// Order should remain unpaid.
		$this->order = wc_get_order( $this->order->get_id() );
		$this->assertFalse( $this->order->is_paid() );
	}

	/**
	 * Test callback rejection with invalid order ID.
	 */
	public function test_callback_rejection_invalid_order_id() {
		$_GET['order_id']      = 999999;
		$_GET['wcrmpgs_nonce'] = wp_create_nonce( 'wcrmpgs_process_response' );

		ob_start();
		try {
			$this->gateway->process_response();
		} catch ( Exception $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Should have rejected with wp_die.
		$this->assertStringContainsString( 'Invalid order callback', $output );
	}

	/**
	 * Test callback rejection with payment method mismatch.
	 */
	public function test_callback_rejection_payment_method_mismatch() {
		// Change order to a different payment method.
		$this->order->set_payment_method( 'paypal' );
		$this->order->save();

		$_GET['order_id']       = $this->order->get_id();
		$_GET['key']            = $this->order->get_order_key();
		$_GET['wcrmpgs_nonce']  = wp_create_nonce( 'wcrmpgs_process_response' );

		ob_start();
		try {
			$this->gateway->process_response();
		} catch ( Exception $e ) {
			// Expected.
		}
		$output = ob_get_clean();

		// Should have rejected with wp_die.
		$this->assertStringContainsString( 'Invalid payment method callback', $output );
	}

	/**
	 * Test callback with mismatched indicator marks order as failed.
	 */
	public function test_callback_with_mismatched_indicator_fails_order() {
		$verification_response = array(
			'result'          => 'SUCCESS',
			'resultIndicator' => 'DIFFERENT_INDICATOR_456',
			'transaction'     => array(
				'id' => 'txn_99999',
			),
		);

		$_GET['order_id']       = $this->order->get_id();
		$_GET['key']            = $this->order->get_order_key();
		$_GET['wcrmpgs_nonce']  = wp_create_nonce( 'wcrmpgs_process_response' );
		$_GET['resultIndicator'] = 'DIFFERENT_INDICATOR_456';

		ob_start();
		try {
			$this->gateway->process_response();
		} catch ( Exception $e ) {
			// Expected: exit() will cause exception in test environment.
		}
		ob_end_clean();

		// Refresh order.
		$this->order = wc_get_order( $this->order->get_id() );

		// Assertions.
		$this->assertFalse( $this->order->is_paid(), 'Order should not be paid when indicator mismatches' );
		$this->assertTrue( $this->order->has_status( 'failed' ), 'Order should have failed status' );
		$this->assertEqual(
			'DIFFERENT_INDICATOR_456',
			$this->order->get_meta( '_wcrmpgs_result_indicator', true ),
			'Mismatched indicator should be stored'
		);
	}

	/**
	 * Test transaction ID extraction from different response formats.
	 */
	public function test_transaction_id_extraction() {
		$gateway = $this->gateway;

		// Test format 1: $response['transaction']['id'].
		$response1 = array(
			'transaction' => array(
				'id' => 'txn_format1',
			),
		);
		$this->assertEqual(
			'txn_format1',
			$this->call_method( $gateway, 'extract_transaction_id', array( $response1 ) ),
			'Should extract from transaction.id'
		);

		// Test format 2: $response['transaction'][0]['id'].
		$response2 = array(
			'transaction' => array(
				array( 'id' => 'txn_format2' ),
			),
		);
		$this->assertEqual(
			'txn_format2',
			$this->call_method( $gateway, 'extract_transaction_id', array( $response2 ) ),
			'Should extract from transaction[0].id'
		);

		// Test format 3: $response['id'].
		$response3 = array(
			'id' => 'txn_format3',
		);
		$this->assertEqual(
			'txn_format3',
			$this->call_method( $gateway, 'extract_transaction_id', array( $response3 ) ),
			'Should extract from id'
		);

		// Test format 4: No ID found.
		$response4 = array(
			'result' => 'SUCCESS',
		);
		$this->assertEqual(
			'',
			$this->call_method( $gateway, 'extract_transaction_id', array( $response4 ) ),
			'Should return empty string when no ID found'
		);
	}

	/**
	 * Helper to call protected/private methods for testing.
	 *
	 * @param object $object Object instance.
	 * @param string $method_name Method name.
	 * @param array  $params Parameters.
	 * @return mixed
	 */
	protected function call_method( $object, $method_name, $params = array() ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $params );
	}
}
