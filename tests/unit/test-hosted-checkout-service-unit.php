<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WCRMPGS_Test_Hosted_Checkout_Service_Unit extends TestCase {

    public function test_build_session_request_contains_required_core_fields(): void {
        $service = new WCRMPGS_Hosted_Checkout_Service_Unit_Adapter(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array()
        );

        $order   = new WCRMPGS_Test_Order_Stub();
        $payload = $service->build_session_request_for_stub( $order );

        $this->assertSame( 'INITIATE_CHECKOUT', $payload['apiOperation'] );
        $this->assertSame( '123', $payload['order']['id'] );
        $this->assertSame( '99.50', $payload['order']['amount'] );
        $this->assertSame( 'USD', $payload['order']['currency'] );
        $this->assertSame( 'PURCHASE', $payload['interaction']['operation'] );
        $this->assertSame( 'ORDER-123', $payload['transaction']['reference'] );
        $this->assertStringContainsString( 'key=order-key-123', $payload['interaction']['returnUrl'] );
        $this->assertStringContainsString( 'wcrmpgs_nonce=nonce-wcrmpgs_process_response', $payload['interaction']['returnUrl'] );
    }

    public function test_build_session_request_includes_customer_and_initiator_for_logged_in_user(): void {
        $service = new WCRMPGS_Hosted_Checkout_Service_Unit_Adapter(
            new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' ),
            array()
        );

        $order   = new WCRMPGS_Test_Order_Stub( 321, 10.0, 'EUR', 55, 'alice@example.test', 'Alice', 'Smith', 'k-321' );
        $payload = $service->build_session_request_for_stub( $order );

        $this->assertSame( '55', $payload['initiator']['userId'] );
        $this->assertSame( 'alice@example.test', $payload['customer']['email'] );
        $this->assertSame( 'Alice', $payload['customer']['firstName'] );
        $this->assertSame( 'Smith', $payload['customer']['lastName'] );
    }
}
