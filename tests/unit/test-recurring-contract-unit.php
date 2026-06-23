<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class WCRMPGS_Test_Recurring_Contract_Unit extends TestCase {

    public function test_extract_reads_token_and_agreement_fields_from_verification_payload(): void {
        $contract = new WCRMPGS_Recurring_Contract();

        $payload = array(
            'sourceOfFunds' => array(
                'provided' => array(
                    'card' => array(
                        'token' => 'tok_abc_123',
                    ),
                ),
            ),
            'agreement'     => array(
                'id'                         => 'agree_001',
                'type'                       => 'RECURRING',
                'source'                     => 'MERCHANT',
                'numberOfPayments'           => 6,
                'amountVariability'          => 'FIXED',
                'expiryDate'                 => '2027-11-30',
                'paymentFrequency'           => 'MONTHLY',
                'minimumDaysBetweenPayments' => 28,
            ),
        );

        $extracted = $contract->extract( $payload );

        $this->assertSame( 'tok_abc_123', $extracted['token'] );
        $this->assertSame( 'agree_001', $extracted['agreement_id'] );
        $this->assertSame( 'RECURRING', $extracted['agreement_type'] );
        $this->assertSame( 'MERCHANT', $extracted['agreement_source'] );
        $this->assertSame( '6', $extracted['agreement_number_of_payments'] );
        $this->assertSame( 'FIXED', $extracted['agreement_amount_variability'] );
        $this->assertSame( '2027-11-30', $extracted['agreement_expiry_date'] );
        $this->assertSame( 'MONTHLY', $extracted['agreement_payment_frequency'] );
        $this->assertSame( '28', $extracted['agreement_minimum_days_between_payments'] );
    }

    public function test_build_meta_map_returns_stable_meta_contract_when_data_exists(): void {
        $contract = new WCRMPGS_Recurring_Contract();

        $meta_map = $contract->build_meta_map(
            array(
                'token'            => 'tok_456',
                'agreement_id'     => 'agree_789',
                'agreement_type'   => 'MIT',
                'agreement_source' => 'STORED_ON_FILE',
                'agreement_number_of_payments'           => '9',
                'agreement_amount_variability'           => 'VARIABLE',
                'agreement_expiry_date'                  => '2028-01-31',
                'agreement_payment_frequency'            => 'MONTHLY',
                'agreement_minimum_days_between_payments' => '30',
            ),
            '2026-06-21 12:00:00'
        );

        $this->assertSame( 'tok_456', $meta_map[ WCRMPGS_Recurring_Contract::META_TOKEN ] );
        $this->assertSame( 'agree_789', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_ID ] );
        $this->assertSame( 'MIT', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_TYPE ] );
        $this->assertSame( 'STORED_ON_FILE', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_SOURCE ] );
        $this->assertSame( '9', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_NUMBER_OF_PAYMENTS ] );
        $this->assertSame( 'VARIABLE', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_AMOUNT_VARIABILITY ] );
        $this->assertSame( '2028-01-31', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_EXPIRY_DATE ] );
        $this->assertSame( 'MONTHLY', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_PAYMENT_FREQUENCY ] );
        $this->assertSame( '30', $meta_map[ WCRMPGS_Recurring_Contract::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS ] );
        $this->assertSame( '2026-06-21 12:00:00', $meta_map[ WCRMPGS_Recurring_Contract::META_CAPTURED_AT ] );
    }

    public function test_build_meta_map_returns_empty_when_no_contract_data_present(): void {
        $contract = new WCRMPGS_Recurring_Contract();

        $meta_map = $contract->build_meta_map(
            array(
                'token'            => '',
                'agreement_id'     => '',
                'agreement_type'   => '',
                'agreement_source' => '',
                'agreement_number_of_payments'           => '',
                'agreement_amount_variability'           => '',
                'agreement_expiry_date'                  => '',
                'agreement_payment_frequency'            => '',
                'agreement_minimum_days_between_payments' => '',
            ),
            '2026-06-21 12:00:00'
        );

        $this->assertSame( array(), $meta_map );
    }
}
