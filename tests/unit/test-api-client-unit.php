<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WCRMPGS_Test_Api_Client_Unit extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        wcrmpgs_unit_reset_spy();
    }

    public function test_build_endpoint_constructs_expected_url(): void {
        $client = new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' );

        $url = $client->build_endpoint( '100', 'session' );

        $this->assertSame(
            'https://gateway.test/api/rest/version/100/merchant/merchant_1/session',
            $url
        );
    }

    public function test_post_sends_json_headers_and_payload(): void {
        $client  = new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' );
        $payload = array( 'foo' => 'bar' );

        $client->post( 'https://gateway.test/endpoint', $payload );

        $this->assertCount( 1, $GLOBALS['wcrmpgs_unit_spy']['post_calls'] );
        $call = $GLOBALS['wcrmpgs_unit_spy']['post_calls'][0];

        $this->assertSame( 'https://gateway.test/endpoint', $call[0] );
        $this->assertSame( 'application/json', $call[1]['headers']['Content-Type'] );
        $this->assertSame( 'application/json', $call[1]['headers']['Accept'] );
        $this->assertSame( wp_json_encode( $payload ), $call[1]['body'] );
        $this->assertSame( 45, $call[1]['timeout'] );
    }

    public function test_get_sends_json_headers(): void {
        $client = new WCRMPGS_Api_Client( 'https://gateway.test', 'merchant_1', 'secret' );

        $client->get( 'https://gateway.test/endpoint' );

        $this->assertCount( 1, $GLOBALS['wcrmpgs_unit_spy']['get_calls'] );
        $call = $GLOBALS['wcrmpgs_unit_spy']['get_calls'][0];

        $this->assertSame( 'https://gateway.test/endpoint', $call[0] );
        $this->assertSame( 'application/json', $call[1]['headers']['Content-Type'] );
        $this->assertSame( 'application/json', $call[1]['headers']['Accept'] );
        $this->assertSame( 45, $call[1]['timeout'] );
    }
}
