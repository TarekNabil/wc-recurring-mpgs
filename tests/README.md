# WC Recurring MPGS - Tests

This directory contains integration and unit tests for the plugin.

## Test Files

### `integration-test-callback-flow.md`

**Manual integration test checklist** for the callback verification and order finalization flow.

Use this document to verify the following scenarios:
1. Successful payment callback (indicator matches, result SUCCESS)
2. Mismatched success indicator (verification failure)
3. Callback verification API failure
4. Invalid nonce
5. Invalid order key
6. Missing order ID
7. Payment method mismatch
8. Transaction ID extraction

**How to use:**
- Review the environment setup requirements
- Follow each test case step-by-step
- Check the expected outcomes
- Document findings in the sign-off section

### `class-test-callback-flow.php`

**PHPUnit test suite** for automated callback flow testing.

Includes:
- Successful callback with matching indicator
- Callback rejection with invalid nonce
- Callback rejection with invalid order key
- Callback rejection with invalid order ID
- Callback rejection with payment method mismatch
- Callback with mismatched indicator (order marked failed)
- Transaction ID extraction from various response formats

**How to run:**
```bash
# From WordPress root directory
wp test run --testcase=WCRMPGS_Test_Callback_Flow

# Or with PHPUnit directly
phpunit tests/class-test-callback-flow.php
```

**Prerequisites:**
- WordPress test environment (with `tests/bootstrap.php`)
- PHPUnit installed
- WooCommerce plugin active
- Plugin activated in tests

## Running Tests

### Manual Testing

1. Set up a local WordPress + WooCommerce environment
2. Configure the MPGS gateway with test credentials
3. Follow `integration-test-callback-flow.md` step-by-step
4. Document results and sign off

### Automated Testing

```bash
# Install test dependencies (if not already installed)
composer install --dev

# Run all plugin tests
wp test run

# Run specific test class
wp test run --testcase=WCRMPGS_Test_Callback_Flow

# Run with verbose output
wp test run --testcase=WCRMPGS_Test_Callback_Flow --verbose
```

## Test Coverage

### Phase 1 (Current)
- ✅ Callback verification
- ✅ Order finalization (success path)
- ✅ Order failure handling
- ✅ Metadata persistence
- ✅ Transaction ID extraction

### Phase 2 (Future)
- ⏳ Refund processing
- ⏳ Void requests
- ⏳ Order notes and logging

### Phase 3 (Future)
- ⏳ Token storage and retrieval
- ⏳ Recurring MIT charge requests
- ⏳ Manual admin charge action

### Phase 4 (Future)
- ⏳ Webhook ingestion
- ⏳ Retry policies
- ⏳ Duplicate charge protection

## Known Issues / Limitations

1. **Exit in callbacks:** The `process_response()` method uses `exit;` to redirect, which causes exceptions in test environment. Tests handle this with `try/catch` around `ob_start()`.

2. **Mock verification:** Automated tests need to mock the `verify_order_payment()` method since it makes actual HTTP requests. For integration testing, use the manual checklist with a test provider account.

3. **Nonce verification:** Automated tests generate nonces in `set_up()`, but real callbacks must include the nonce created during checkout session.

## Debugging

Enable debug logging in gateway settings to see detailed callback processing:
- WooCommerce > Settings > Payments > WC Recurring MPGS
- Enable "Debug Log"
- Check logs at: WooCommerce > Status > Logs

Log file pattern: `wc-recurring-mpgs-YYYY-MM-DD.log`

## Contributing

When adding new tests:
1. Add manual test case to `integration-test-callback-flow.md`
2. Add corresponding PHPUnit test to `class-test-callback-flow.php`
3. Document expected behavior in test docblocks
4. Ensure all related cleanup in `tear_down()` method
