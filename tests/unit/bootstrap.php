<?php
declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            public string $code;
            public string $message;

            public function __construct( string $code = '', string $message = '' ) {
                $this->code    = $code;
                $this->message = $message;
            }

            public function get_error_message(): string {
                return $this->message;
            }
        }
    }

    if ( ! function_exists( 'trailingslashit' ) ) {
        function trailingslashit( string $value ): string {
            return rtrim( $value, '/' ) . '/';
        }
    }

    if ( ! function_exists( 'rawurlencode' ) ) {
        function rawurlencode( string $value ): string {
            return \rawurlencode( $value );
        }
    }

    if ( ! function_exists( '__' ) ) {
        function __( string $text, string $domain = '' ): string {
            return $text;
        }
    }

    if ( ! function_exists( 'get_bloginfo' ) ) {
        function get_bloginfo( string $show = '' ): string {
            return 'Test Blog';
        }
    }

    if ( ! function_exists( 'home_url' ) ) {
        function home_url( string $path = '' ): string {
            return 'https://example.test' . $path;
        }
    }

    if ( ! function_exists( 'add_query_arg' ) ) {
        function add_query_arg( array $args, string $url ): string {
            $query = http_build_query( $args );
            return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
        }
    }

    if ( ! function_exists( 'wp_create_nonce' ) ) {
        function wp_create_nonce( string $action ): string {
            return 'nonce-' . $action;
        }
    }

    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $value ): string {
            return json_encode( $value );
        }
    }

    $GLOBALS['wcrmpgs_unit_spy'] = array(
        'post_calls' => array(),
        'get_calls'  => array(),
    );

    function wcrmpgs_unit_reset_spy(): void {
        $GLOBALS['wcrmpgs_unit_spy']['post_calls'] = array();
        $GLOBALS['wcrmpgs_unit_spy']['get_calls']  = array();
    }

    function wp_remote_post( string $url, array $args ) {
        $GLOBALS['wcrmpgs_unit_spy']['post_calls'][] = array( $url, $args );
        return array( 'url' => $url, 'args' => $args );
    }

    function wp_remote_get( string $url, array $args ) {
        $GLOBALS['wcrmpgs_unit_spy']['get_calls'][] = array( $url, $args );
        return array( 'url' => $url, 'args' => $args );
    }
}

namespace {
    require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-api-client.php';
    require_once dirname( __DIR__, 2 ) . '/includes/class-wcrmpgs-hosted-checkout-service.php';

    class WCRMPGS_Test_Order_Stub {
        private int $id;
        private float $total;
        private string $currency;
        private int $user_id;
        private string $email;
        private string $first_name;
        private string $last_name;
        private string $order_key;

        public function __construct(
            int $id = 123,
            float $total = 99.5,
            string $currency = 'USD',
            int $user_id = 88,
            string $email = 'john@example.test',
            string $first_name = 'John',
            string $last_name = 'Doe',
            string $order_key = 'order-key-123'
        ) {
            $this->id        = $id;
            $this->total     = $total;
            $this->currency  = $currency;
            $this->user_id   = $user_id;
            $this->email     = $email;
            $this->first_name = $first_name;
            $this->last_name = $last_name;
            $this->order_key = $order_key;
        }

        public function get_id(): int {
            return $this->id;
        }

        public function get_total(): float {
            return $this->total;
        }

        public function get_currency(): string {
            return $this->currency;
        }

        public function get_user_id(): int {
            return $this->user_id;
        }

        public function get_billing_email(): string {
            return $this->email;
        }

        public function get_billing_first_name(): string {
            return $this->first_name;
        }

        public function get_billing_last_name(): string {
            return $this->last_name;
        }

        public function get_order_key(): string {
            return $this->order_key;
        }
    }

    class WCRMPGS_Hosted_Checkout_Service_Unit_Adapter extends WCRMPGS_Hosted_Checkout_Service {
        public function build_session_request_for_stub( WCRMPGS_Test_Order_Stub $order ): array {
            $api_version = 100;
            $order_id    = $order->get_id();

            $payload = array(
                'apiOperation' => $api_version >= 63 ? 'INITIATE_CHECKOUT' : 'CREATE_CHECKOUT_SESSION',
                'order'        => array(
                    'id'                => (string) $order_id,
                    'amount'            => number_format( (float) $order->get_total(), 2, '.', '' ),
                    'currency'          => $order->get_currency(),
                    'description'       => sprintf( 'Pay for order #%d', $order_id ),
                    'reference'         => (string) $order_id,
                    'customerOrderDate' => gmdate( 'Y-m-d' ),
                ),
                'interaction'  => array(
                    'operation' => 'PURCHASE',
                    'returnUrl' => add_query_arg(
                        array(
                            'wc-api'        => 'wcrmpgs_gateway',
                            'order_id'      => $order_id,
                            'key'           => $order->get_order_key(),
                            'wcrmpgs_nonce' => wp_create_nonce( 'wcrmpgs_process_response' ),
                        ),
                        home_url( '/' )
                    ),
                    'merchant'  => array(
                        'name'    => 'Test Merchant',
                        'address' => array(
                            'line1' => 'Address 1',
                            'line2' => 'Address 2',
                        ),
                    ),
                ),
                'transaction'  => array(
                    'reference' => 'ORDER-' . $order_id,
                    'source'    => 'INTERNET',
                ),
            );

            if ( $order->get_user_id() ) {
                $payload['initiator'] = array( 'userId' => (string) $order->get_user_id() );
            }

            if ( $order->get_billing_email() && $order->get_billing_first_name() && $order->get_billing_last_name() ) {
                $payload['customer'] = array(
                    'email'     => $order->get_billing_email(),
                    'firstName' => $order->get_billing_first_name(),
                    'lastName'  => $order->get_billing_last_name(),
                );
            }

            return $payload;
        }
    }
}
