<?php

/**
 * Recurring token/agreement contract mapper.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maps verification payload data into stable recurring meta keys.
 */
class WCRMPGS_Recurring_Contract {

    /**
     * Order/subscription meta key for reusable token.
     */
    const META_TOKEN = '_wcrmpgs_recurring_token';

    /**
     * Order/subscription meta key for agreement id.
     */
    const META_AGREEMENT_ID = '_wcrmpgs_agreement_id';

    /**
     * Order/subscription meta key for agreement type.
     */
    const META_AGREEMENT_TYPE = '_wcrmpgs_agreement_type';

    /**
     * Order/subscription meta key for agreement source.
     */
    const META_AGREEMENT_SOURCE = '_wcrmpgs_agreement_source';

    /**
     * Order/subscription meta key for agreement number of payments.
     */
    const META_AGREEMENT_NUMBER_OF_PAYMENTS = '_wcrmpgs_agreement_number_of_payments';

    /**
     * Order/subscription meta key for agreement amount variability.
     */
    const META_AGREEMENT_AMOUNT_VARIABILITY = '_wcrmpgs_agreement_amount_variability';

    /**
     * Order/subscription meta key for agreement expiry date.
     */
    const META_AGREEMENT_EXPIRY_DATE = '_wcrmpgs_agreement_expiry_date';

    /**
     * Order/subscription meta key for agreement payment frequency.
     */
    const META_AGREEMENT_PAYMENT_FREQUENCY = '_wcrmpgs_agreement_payment_frequency';

    /**
     * Order/subscription meta key for agreement minimum days between payments.
     */
    const META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS = '_wcrmpgs_agreement_minimum_days_between_payments';

    /**
     * Order/subscription meta key for captured at timestamp.
     */
    const META_CAPTURED_AT = '_wcrmpgs_recurring_contract_captured_at';

    /**
     * Extract normalized recurring data from provider verification payload.
     *
     * @param array $verification Verification payload.
    * @return array{token:string,agreement_id:string,agreement_type:string,agreement_source:string,agreement_number_of_payments:string,agreement_amount_variability:string,agreement_expiry_date:string,agreement_payment_frequency:string,agreement_minimum_days_between_payments:string}
     */
    public function extract( array $verification ) {
        $token = $this->pick_first_string(
            $verification,
            array(
                array( 'sourceOfFunds', 'token' ),
                array( 'sourceOfFunds', 'provided', 'card', 'token' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'id' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'token' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFileId' ),
                array( 'sourceOfFunds', 'provided', 'card', 'id' ),
                array( 'token' ),
            )
        );

        $agreement_id = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'id' ),
                array( 'agreement', 'reference' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'id' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'agreementId' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'agreement', 'id' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'id' ),
            )
        );

        $agreement_type = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'type' ),
                array( 'agreement', 'classification' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'type' ),
            )
        );

        $agreement_source = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'source' ),
                array( 'sourceOfFunds', 'provided', 'card', 'storedOnFile', 'source' ),
            )
        );

        $agreement_number_of_payments = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'numberOfPayments' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'numberOfPayments' ),
            )
        );

        $agreement_amount_variability = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'amountVariability' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'amountVariability' ),
            )
        );

        $agreement_expiry_date = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'expiryDate' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'expiryDate' ),
            )
        );

        $agreement_payment_frequency = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'paymentFrequency' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'paymentFrequency' ),
            )
        );

        $agreement_minimum_days_between_payments = $this->pick_first_string(
            $verification,
            array(
                array( 'agreement', 'minimumDaysBetweenPayments' ),
                array( 'sourceOfFunds', 'provided', 'card', 'agreement', 'minimumDaysBetweenPayments' ),
            )
        );

        return array(
            'token'            => $token,
            'agreement_id'     => $agreement_id,
            'agreement_type'   => $agreement_type,
            'agreement_source' => $agreement_source,
            'agreement_number_of_payments'           => $agreement_number_of_payments,
            'agreement_amount_variability'           => $agreement_amount_variability,
            'agreement_expiry_date'                  => $agreement_expiry_date,
            'agreement_payment_frequency'            => $agreement_payment_frequency,
            'agreement_minimum_days_between_payments' => $agreement_minimum_days_between_payments,
        );
    }

    /**
     * Build normalized meta key map for order/subscription persistence.
     *
     * @param array  $contract_data Extracted contract data.
     * @param string $captured_at UTC datetime in Y-m-d H:i:s.
     * @return array<string,string>
     */
    public function build_meta_map( array $contract_data, $captured_at ) {
        $meta_map = array();

        if ( ! empty( $contract_data['token'] ) ) {
            $meta_map[ self::META_TOKEN ] = (string) $contract_data['token'];
        }

        if ( ! empty( $contract_data['agreement_id'] ) ) {
            $meta_map[ self::META_AGREEMENT_ID ] = (string) $contract_data['agreement_id'];
        }

        if ( ! empty( $contract_data['agreement_type'] ) ) {
            $meta_map[ self::META_AGREEMENT_TYPE ] = (string) $contract_data['agreement_type'];
        }

        if ( ! empty( $contract_data['agreement_source'] ) ) {
            $meta_map[ self::META_AGREEMENT_SOURCE ] = (string) $contract_data['agreement_source'];
        }

        if ( ! empty( $contract_data['agreement_number_of_payments'] ) ) {
            $meta_map[ self::META_AGREEMENT_NUMBER_OF_PAYMENTS ] = (string) $contract_data['agreement_number_of_payments'];
        }

        if ( ! empty( $contract_data['agreement_amount_variability'] ) ) {
            $meta_map[ self::META_AGREEMENT_AMOUNT_VARIABILITY ] = (string) $contract_data['agreement_amount_variability'];
        }

        if ( ! empty( $contract_data['agreement_expiry_date'] ) ) {
            $meta_map[ self::META_AGREEMENT_EXPIRY_DATE ] = (string) $contract_data['agreement_expiry_date'];
        }

        if ( ! empty( $contract_data['agreement_payment_frequency'] ) ) {
            $meta_map[ self::META_AGREEMENT_PAYMENT_FREQUENCY ] = (string) $contract_data['agreement_payment_frequency'];
        }

        if ( ! empty( $contract_data['agreement_minimum_days_between_payments'] ) ) {
            $meta_map[ self::META_AGREEMENT_MIN_DAYS_BETWEEN_PAYMENTS ] = (string) $contract_data['agreement_minimum_days_between_payments'];
        }

        if ( ! empty( $meta_map ) ) {
            $meta_map[ self::META_CAPTURED_AT ] = (string) $captured_at;
        }

        return $meta_map;
    }

    /**
     * Pick first non-empty string from nested paths.
     *
     * @param array $data Source payload.
     * @param array $paths Nested key paths.
     * @return string
     */
    private function pick_first_string( array $data, array $paths ) {
        foreach ( $paths as $path ) {
            $value = $this->read_nested_value( $data, $path );
            if ( null === $value ) {
                continue;
            }

            $normalized = trim( (string) $value );
            if ( '' !== $normalized ) {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * Read nested array value.
     *
     * @param array $data Source payload.
     * @param array $path Nested key path.
     * @return mixed|null
     */
    private function read_nested_value( array $data, array $path ) {
        $cursor = $data;

        foreach ( $path as $segment ) {
            if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
                return null;
            }

            $cursor = $cursor[ $segment ];
        }

        return $cursor;
    }
}