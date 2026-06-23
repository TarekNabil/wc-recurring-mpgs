<?php

/**
 * Gateway API transport helpers.
 *
 * @package WCRMPGS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Thin wrapper around the current gateway REST API.
 */
class WCRMPGS_Api_Client {

    /**
     * Service host.
     *
     * @var string
     */
    private $service_host;

    /**
     * Merchant ID.
     *
     * @var string
     */
    private $merchant_id;

    /**
     * API password.
     *
     * @var string
     */
    private $api_password;

    /**
     * Constructor.
     *
     * @param string $service_host Service host.
     * @param string $merchant_id Merchant ID.
     * @param string $api_password API password.
     */
    public function __construct( $service_host, $merchant_id, $api_password ) {
        $this->service_host  = trailingslashit( $service_host );
        $this->merchant_id   = $merchant_id;
        $this->api_password  = $api_password;
    }

    /**
     * Build a merchant-scoped endpoint.
     *
     * @param string $api_version API version.
     * @param string $path Path after merchant scope.
     * @return string
     */
    public function build_endpoint( $api_version, $path ) {
        return $this->service_host . 'api/rest/version/' . rawurlencode( $api_version ) . '/merchant/' . rawurlencode( $this->merchant_id ) . '/' . ltrim( $path, '/' );
    }

    /**
     * Perform a JSON POST request.
     *
     * @param string $url Endpoint URL.
     * @param array  $payload Request payload.
     * @return array|WP_Error
     */
    public function post( $url, array $payload ) {
        return wp_remote_post(
            $url,
            array(
                'headers' => $this->build_json_headers(),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 45,
            )
        );
    }

    /**
     * Perform a JSON PUT request.
     *
     * @param string $url Endpoint URL.
     * @param array  $payload Request payload.
     * @return array|WP_Error
     */
    public function put( $url, array $payload ) {
        return wp_remote_request(
            $url,
            array(
                'method'  => 'PUT',
                'headers' => $this->build_json_headers(),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 45,
            )
        );
    }

    /**
     * Perform a JSON GET request.
     *
     * @param string $url Endpoint URL.
     * @return array|WP_Error
     */
    public function get( $url ) {
        return wp_remote_get(
            $url,
            array(
                'headers' => $this->build_json_headers(),
                'timeout' => 45,
            )
        );
    }

    /**
     * Build common JSON headers.
     *
     * @return array
     */
    private function build_json_headers() {
        return array(
            'Authorization' => 'Basic ' . base64_encode( 'merchant.' . $this->merchant_id . ':' . $this->api_password ),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );
    }
}