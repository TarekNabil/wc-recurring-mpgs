# WC Recurring MPGS Architecture

## Goal

Build a clean WooCommerce gateway that supports:

1. Hosted checkout customer-initiated transactions.
2. Refunds and post-payment verification.
3. Token capture and recurring agreement storage.
4. Merchant-initiated recurring renewals.
5. Manual retry and webhook-driven reconciliation.

## Why A New Plugin

The legacy plugin is workable for one-time hosted checkout, but it centralizes nearly all behavior inside one gateway class and does not provide the renewal hooks or service boundaries needed for CIT and MIT flows.

This replacement plugin is provider-neutral by design. The first connector is being validated against Areeba, but the structure should not assume a single processor.

## Planned Structure

```text
wc-recurring-mpgs/
  wc-recurring-mpgs.php
  uninstall.php
  assets/
    js/
  docs/
  includes/
    class-wcrmpgs-plugin.php
    class-wcrmpgs-gateway.php
    class-wcrmpgs-api-client.php
    class-wcrmpgs-hosted-checkout-service.php
    class-wcrmpgs-recurring-service.php
    class-wcrmpgs-webhook-controller.php
    class-wcrmpgs-subscriptions-adapter.php
    class-wcrmpgs-order-meta.php
  tests/
    bootstrap.php
    README.md
    integration-test-callback-flow.md
    class-test-callback-flow.php
```

## Delivery Phases

### Phase 1: COMPLETE

1. ✅ Plugin bootstrap
2. ✅ Gateway registration
3. ✅ Hosted checkout session builder
4. ✅ Safe settings model
5. ✅ Blocks adapter scaffolding

### Phase 2: COMPLETE

1. ✅ **COMPLETE** — Callback verification and order finalization
   - Server-to-server verification via verify_order_payment() method
   - Nonce, order key, and payment method validation
   - Success indicator matching to prevent tampering
   - Secure order finalization with proper status transitions

2. ✅ **COMPLETE** — Persist transaction metadata
   - Transaction ID extraction from provider response
   - Result indicator, result code, and full callback payload stored as order meta
   - Order status transitions (pending → processing on success, pending → failed on failure)
   - WooCommerce transaction ID assignment via order->set_transaction_id()

3. ✅ **COMPLETE** — Implement refund and void requests
  - `process_refund()` implemented with provider API integration
  - Refund response metadata persisted for auditability
  - Error mapping returns `WP_Error` with gateway-specific codes

4. ✅ **COMPLETE** — Add order notes and structured logging
  - Order notes recorded for callback and refund outcomes
  - Structured logs available through WooCommerce log channel

### Phase 3

1. Complete a testable one-time payment release gate.
2. Validate end-to-end hosted checkout success flow in sandbox.
3. Validate callback failures and customer retry behavior.
4. Validate merchant operational flow (logs, order notes, admin visibility).

### Phase 4

1. Store reusable token and agreement metadata after the first successful CIT.
2. Add recurring request builder for version 100 MIT PAY.
3. Add manual admin MIT charge action.
4. Add renewal orchestration hooks.

### Phase 5

1. Add webhook and notification ingestion.
2. Add retry policy and duplicate-charge protection.
3. Add tests for payload builders and response handlers.
4. Update merchant-facing documentation.

Current kickoff status:

1. Webhook controller scaffold added.
2. Public ingestion endpoints are registered.
3. Payload normalization and reconciliation hooks are being implemented.

## Implementation Details: Phase 2 (Callback & Order Finalization)

### What's Implemented

**File: includes/class-wcrmpgs-gateway.php**

#### process_response() Method (Line 244)
The main callback handler. Processing flow:

1. Extract and validate order_id, order key, nonce
2. Verify order exists and uses this payment method
3. Call verify_order_payment() for server-side check
4. Extract transaction ID from verification response
5. Compare success indicator from session with callback indicator
6. Update order metadata with full callback payload
7. Mark order as paid if indicators match and result is SUCCESS
8. Mark order as failed if verification fails or indicator mismatches
9. Add order notes for merchant visibility
10. Redirect customer to appropriate page (thank-you or payment retry)

#### is_valid_callback_nonce() Method (Line 384)
Validates WordPress nonce from callback query parameter. Prevents CSRF attacks.

#### verify_order_payment() Method (Line 398)
Server-to-server verification. Calls provider API endpoint:
- `/api/rest/version/{version}/merchant/{merchant_id}/order/{order_id}`
- Returns full order state from provider for comparison

#### extract_transaction_id() Method (Line 420)
Robust transaction ID extraction supporting multiple provider response formats:
- `$response['transaction']['id']`
- `$response['transaction'][0]['id']`
- `$response['id']`

#### get_api_client() Method (Line 447)
Factory method to create and configure WCRMPGS_Api_Client instance.

### Order Metadata Stored

After callback processing, order has these metadata keys:

| Key | Value | Purpose |
|-----|-------|---------|
| `_wcrmpgs_session_id` | string | Hosted checkout session ID (set at payment initiation) |
| `_wcrmpgs_success_indicator` | string | Expected indicator from checkout response |
| `_wcrmpgs_result_indicator` | string | Actual indicator from callback verification |
| `_wcrmpgs_result` | string | Final result code from provider (SUCCESS, FAILURE, etc.) |
| `_wcrmpgs_transaction_id` | string | Provider's transaction reference ID |
| `_wcrmpgs_callback_payload` | JSON | Full verification response (audit trail) |

### Callback Validation Layers

1. **Order Existence** — wp_die if order not found
2. **Payment Method** — wp_die if order doesn't use MPGS gateway
3. **Nonce Verification** — wp_die if invalid/missing nonce
4. **Order Key** — wp_die if order key doesn't match
5. **Credentials** — User-facing error if gateway config incomplete
6. **Provider Verification** — User-facing error if API call fails
7. **Indicator Match** — Soft fail: mark order failed, add note, redirect to retry

### Order Status Transitions

**On Successful Verification:**
- pending → processing (or completed, depending on merchant config)
- Order marked paid via payment_complete()
- Customer redirected to thank-you page

**On Failed Verification:**
- pending → failed (if not already paid/failed)
- Customer shown error message
- Customer redirected to payment retry page
- Order note added explaining failure reason

### Secure Return URL Structure

File: includes/class-wcrmpgs-hosted-checkout-service.php (Line 69)

Return URL includes:
```
?wc-api=wcrmpgs_gateway&order_id={id}&key={order_key}&wcrmpgs_nonce={nonce}&resultIndicator={indicator}
```

Parameters:
- `wc-api` — Routes to WooCommerce API endpoint
- `order_id` — Identifies which order to finalize
- `key` — Order key for ownership verification
- `wcrmpgs_nonce` — CSRF protection (created via wp_create_nonce)
- `resultIndicator` — Provider's success/failure indicator (matched against stored value)

## Test Coverage

### Manual Testing

File: tests/integration-test-callback-flow.md

10 integration test cases covering:
1. Successful callback with matching indicator
2. Callback with mismatched success indicator
3. Callback verification API failure
4. Invalid nonce rejection
5. Invalid order key rejection
6. Missing order ID rejection
7. Payment method mismatch rejection
8. Refund from admin (scaffold)
9. Callback payload persistence
10. Transaction ID extraction variants

### Automated Testing

File: tests/class-test-callback-flow.php

PHPUnit test suite with 6 test cases:
- test_successful_callback_with_matching_indicator
- test_callback_rejection_invalid_nonce
- test_callback_rejection_invalid_key
- test_callback_rejection_invalid_order_id
- test_callback_rejection_payment_method_mismatch
- test_callback_with_mismatched_indicator_fails_order
- test_transaction_id_extraction

Run with:
```bash
wp test run --testcase=WCRMPGS_Test_Callback_Flow
```

## Current Status vs Production Readiness

### What's Ready for Testing

✅ Hosted checkout session creation and redirection  
✅ Callback verification and order finalization  
✅ Metadata persistence for audit trail  
✅ Secure nonce and order key validation  
✅ Transaction ID extraction and assignment  
✅ Order status transitions (success/failure)  
✅ Structured logging and order notes  

### What's Still Needed Before Production

❌ Hosted checkout SDK loading and error handling  
❌ Recurring payment token storage (Phase 3)  
❌ MIT charge requests (Phase 3)  
❌ Webhook ingestion (Phase 5)  
❌ Comprehensive merchant documentation  

### Next Priority

**Phase 3: One-Time Payment Gate Signoff**
- Complete and document manual sandbox smoke checks
- Record explicit GO/NO-GO decision for recurring implementation
- Keep automated unit/integration suites green before feature expansion

**Estimated scope:** Documentation and sandbox validation signoff

## Known Limitations

1. Callback assumes synchronous flow (customer returns immediately after payment)
2. No async webhook support yet (Phase 4)
3. Nonce expires after 24 hours (WordPress default)
4. No retry logic if verify_order_payment() times out
5. No duplicate-charge protection (Phase 4)

## Debugging

Enable debug mode in WooCommerce admin:
1. WooCommerce > Settings > Payments > WC Recurring MPGS
2. Check "Enable debug logging"
3. View logs at: WooCommerce > Status > Logs
4. Log file: wc-recurring-mpgs-YYYY-MM-DD.log

Log entries include:
- `[info]` Successful verification and order finalization
- `[warning]` Verification failures or indicator mismatches
- `[error]` Validation failures (nonce, key, order lookup, etc.)
