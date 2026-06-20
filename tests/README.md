# WC Recurring MPGS - Tests

This plugin follows the same split strategy used in simple-plugin:

1. `tests/unit`: pure logic tests with isolated stubs
2. `tests/integration`: WordPress + WooCommerce behavior tests

This prevents test duplication.

## Structure

1. `tests/unit/bootstrap.php`
2. `tests/unit/test-api-client-unit.php`
3. `tests/unit/test-hosted-checkout-service-unit.php`
4. `tests/bootstrap.php`
5. `tests/integration/test-gateway-integration.php`

## Coverage Split (No Overlap)

1. Unit tests cover:
	- `WCRMPGS_Api_Client::build_endpoint()` URL construction
	- `WCRMPGS_Api_Client::post()` request envelope and headers
	- `WCRMPGS_Api_Client::get()` request envelope and headers
	- hosted checkout payload shape and callback URL fields (`key`, nonce)

2. Integration tests cover:
	- `WCRMPGS_Gateway::process_payment()` with mocked HTTP transport via `pre_http_request`
	- order meta persistence after session creation
	- callback hard-rejection paths (`invalid nonce`, `payment method mismatch`) via `wp_die_handler`
	- callback outcome finalization (success path + indicator mismatch path)
	- refund success and missing-transaction error path

## Run Tests

1. Install dependencies:

```bash
composer install
npm install
```

2. Start wp-env:

```bash
npm run env:start
```

3. Run unit tests:

```bash
npm run test:unit
```

4. Run integration tests:

```bash
npm run test:integration
```

5. Run all:

```bash
npm run test:all
```

## Config Files

1. `phpunit.unit.xml` -> unit suite (`tests/unit`, bootstrap `tests/unit/bootstrap.php`)
2. `phpunit.xml` -> integration suite (`tests/integration`, bootstrap `tests/bootstrap.php`)
3. `.wp-env.json` -> wp-env + WooCommerce plugin wiring

## Notes

1. Integration tests intentionally avoid success-path `process_response()` assertions because the method redirects and exits; these are best covered by end-to-end callback runs.
2. Unit tests intentionally avoid WordPress DB behavior; integration tests own DB and order-state behavior.

## Phase 3 Gate

One-time payment release gate checklist is documented in `docs/one-time-payment-gate.md`.
