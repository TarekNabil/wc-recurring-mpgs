<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WCRMPGS_Test_Recurring_Service_Unit extends TestCase {

    public function test_build_mit_pay_request_builds_expected_payload_for_valid_input(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $payload = $service->build_mit_pay_request(
            array(
                'order_id'                => 77,
                'amount'                  => '25.50',
                'currency'                => 'usd',
                'token'                   => 'tok_77',
                'transaction_id'          => 'mit-txn-001',
                'merchant_transaction_id' => 'mit-merchant-001',
                'agreement_id'            => 'agree-77',
                'agreement_type'          => 'RECURRING',
                'agreement_source'        => 'MERCHANT_INITIATED',
                'agreement_number_of_payments' => '5',
                'agreement_amount_variability' => 'FIXED',
                'agreement_expiry_date'        => '2027-12-31',
                'agreement_payment_frequency'  => 'MONTHLY',
                'agreement_minimum_days_between_payments' => '28',
            )
        );

        $this->assertIsArray( $payload );
        $this->assertSame( 'PAY', $payload['apiOperation'] );
        $this->assertSame( '77', $payload['order']['id'] );
        $this->assertSame( '25.50', $payload['order']['amount'] );
        $this->assertSame( 'USD', $payload['order']['currency'] );
        $this->assertSame( 'mit-merchant-001', $payload['transaction']['reference'] );
        $this->assertSame( 'MERCHANT', $payload['transaction']['source'] );
        $this->assertSame( 'CARD', $payload['sourceOfFunds']['type'] );
        $this->assertSame( 'tok_77', $payload['sourceOfFunds']['token'] );
        $this->assertSame( 'agree-77', $payload['agreement']['id'] );
        $this->assertSame( 'RECURRING', $payload['agreement']['type'] );
        $this->assertSame( 5, $payload['agreement']['numberOfPayments'] );
        $this->assertSame( 'FIXED', $payload['agreement']['amountVariability'] );
        $this->assertSame( '2027-12-31', $payload['agreement']['expiryDate'] );
        $this->assertSame( 'MONTHLY', $payload['agreement']['paymentFrequency'] );
        $this->assertSame( 28, $payload['agreement']['minimumDaysBetweenPayments'] );
    }

    public function test_build_mit_pay_request_returns_error_when_required_fields_are_missing(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $payload = $service->build_mit_pay_request(
            array(
                'order_id'       => 77,
                'amount'         => '25.50',
                'currency'       => 'USD',
                'transaction_id' => 'mit-txn-001',
            )
        );

        $this->assertInstanceOf( WP_Error::class, $payload );
        $this->assertSame( 'wcrmpgs_mit_validation_missing_fields', $payload->code );
    }

    public function test_build_mit_pay_request_returns_error_for_invalid_currency(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $payload = $service->build_mit_pay_request(
            array(
                'order_id'       => 77,
                'amount'         => '25.50',
                'currency'       => 'USDX',
                'token'          => 'tok_77',
                'transaction_id' => 'mit-txn-001',
            )
        );

        $this->assertInstanceOf( WP_Error::class, $payload );
        $this->assertSame( 'wcrmpgs_mit_validation_invalid_currency', $payload->code );
    }

    public function test_normalize_mit_response_maps_success_payload(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $normalized = $service->normalize_mit_response(
            array(
                'result'      => 'SUCCESS',
                'response'    => array(
                    'gatewayCode' => 'APPROVED',
                ),
                'transaction' => array(
                    'id' => 'txn-success-201',
                ),
            )
        );

        $this->assertTrue( $normalized['success'] );
        $this->assertSame( 'SUCCESS', $normalized['result_code'] );
        $this->assertSame( 'APPROVED', $normalized['gateway_code'] );
        $this->assertSame( 'txn-success-201', $normalized['transaction_id'] );
        $this->assertSame( '', $normalized['error_code'] );
    }

    public function test_normalize_mit_response_maps_failure_gateway_codes_to_internal_error_codes(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $normalized = $service->normalize_mit_response(
            array(
                'result'   => 'FAILURE',
                'response' => array(
                    'gatewayCode' => 'DECLINED',
                ),
                'error'    => array(
                    'explanation' => 'Card declined by issuer',
                ),
            )
        );

        $this->assertFalse( $normalized['success'] );
        $this->assertSame( 'FAILURE', $normalized['result_code'] );
        $this->assertSame( 'DECLINED', $normalized['gateway_code'] );
        $this->assertSame( 'card_declined', $normalized['error_code'] );
        $this->assertSame( 'Card declined by issuer', $normalized['message'] );
    }

    public function test_build_mit_endpoint_uses_recurring_api_version_and_transaction_scope(): void {
        $service = new WCRMPGS_Recurring_Service(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array( 'recurring_api_version' => '100' )
        );

        $endpoint = $service->build_mit_endpoint( '42', 'mit-txn-42' );

        $this->assertSame(
            'https://gateway.test/api/rest/version/100/merchant/merchant_1/order/42/transaction/mit-txn-42',
            $endpoint
        );
    }
}
