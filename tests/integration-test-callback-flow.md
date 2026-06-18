# WC Recurring MPGS - Integration Test Checklist: Callback Flow

## Test Environment Setup

- WordPress 6.0+
- WooCommerce 8.0+
- WC Recurring MPGS plugin active
- Gateway enabled and configured with valid credentials
- Gateway debug logging enabled (for troubleshooting)

## Pre-Test Requirements

1. Configure gateway settings in WooCommerce admin:
   - Service Host: (provider URL)
   - Merchant ID: (test merchant id)
   - API Password: (test password)
   - Checkout API Version: 100
   - Recurring API Version: 100
   - Merchant Name: Test Merchant

2. Create test product (e.g., $10.00)

3. Verify WooCommerce logs are accessible:
   - Via WooCommerce Status > Logs
   - File location: `wp-content/uploads/wc-logs/`

---

## Test Case 1: Successful Payment Callback

**Objective:** Verify order is marked paid when indicator matches and result is SUCCESS.

### Steps

1. Go to checkout page
2. Add test product to cart
3. Select "WC Recurring MPGS" payment method
4. Complete checkout → redirected to hosted checkout page
5. Simulate provider callback with:
   - Query params:
     - `wc-api=wcrmpgs_gateway`
     - `order_id={order_id}`
     - `key={order_key}`
     - `wcrmpgs_nonce={valid_nonce}`
     - `resultIndicator={matching_success_indicator}`

### Expected Outcome

- [ ] Order status changes to **Processing** or **Completed**
- [ ] Order marked as paid (`is_paid()` returns true)
- [ ] Transaction ID persisted in order meta
- [ ] Order note added: "MPGS payment verified successfully."
- [ ] Customer redirected to order thank-you page
- [ ] Gateway log shows: "Order {id} payment verified successfully." (info level)
- [ ] Order meta updated:
  - `_wcrmpgs_result_indicator` = matching indicator
  - `_wcrmpgs_result` = "SUCCESS"
  - `_wcrmpgs_transaction_id` = provider transaction id

### How to Trigger Callback (Sandbox)

Create a test request against your site's callback endpoint:

```bash
curl -i "https://yoursite.local/wp-json/wp/v2/" \
  -G \
  -d "wc-api=wcrmpgs_gateway" \
  -d "order_id=123" \
  -d "key=abc123xyz" \
  -d "wcrmpgs_nonce=NONCE_VALUE" \
  -d "resultIndicator=EXPECTED_INDICATOR"
```

Or simulate via WooCommerce order admin → Order > Actions (if webhook simulation tool is available).

---

## Test Case 2: Callback with Mismatched Success Indicator

**Objective:** Verify order fails when success indicator does not match.

### Steps

1. Repeat Test Case 1 up to step 4
2. Simulate provider callback with:
   - `resultIndicator={DIFFERENT_INDICATOR}` (not matching the one from session creation)
   - `result=SUCCESS` (provider says success, but indicator mismatch)

### Expected Outcome

- [ ] Order status changes to **Failed**
- [ ] Order remains **unpaid**
- [ ] Order note added: "Payment verification failed due to mismatched indicator."
- [ ] Customer shown error: "Payment verification failed due to mismatched indicator."
- [ ] Customer redirected back to payment page
- [ ] Gateway log shows: "Order {id} payment verification failed. Result: SUCCESS." (warning level)
- [ ] Order meta stored:
  - `_wcrmpgs_result` = "SUCCESS" (provider result)
  - `_wcrmpgs_result_indicator` = different indicator

---

## Test Case 3: Callback Verification API Failure

**Objective:** Verify order fails gracefully when server-to-server verification fails.

### Steps

1. Repeat Test Case 1 up to step 4
2. Temporarily disconnect network or mock provider API to return error
3. Simulate callback with valid nonce, key, and order

### Expected Outcome

- [ ] Order redirected back to payment page (not marked paid)
- [ ] Customer shown error: "We could not verify your payment. Please try again or contact support."
- [ ] Order note added: "MPGS callback verification failed. See gateway logs for details."
- [ ] Gateway log shows verification error (error level)
- [ ] Order meta includes `_wcrmpgs_callback_payload` (if partial data received)

---

## Test Case 4: Invalid Nonce

**Objective:** Verify callback rejects invalid or missing nonce.

### Steps

1. Repeat Test Case 1 up to step 4
2. Simulate callback with:
   - `wcrmpgs_nonce=INVALID_NONCE` or omit nonce entirely

### Expected Outcome

- [ ] HTTP response: wp_die message "Invalid callback request."
- [ ] Order **not updated**
- [ ] Gateway log shows: "Callback rejected: invalid nonce for order {id}." (error level)
- [ ] No order note added

---

## Test Case 5: Invalid Order Key

**Objective:** Verify callback rejects mismatched order key.

### Steps

1. Repeat Test Case 1 up to step 4
2. Simulate callback with:
   - `key=WRONG_KEY` (different from actual order key)

### Expected Outcome

- [ ] HTTP response: wp_die message "Invalid callback key."
- [ ] Order **not updated**
- [ ] Gateway log shows: "Callback rejected: invalid order key for order {id}." (error level)
- [ ] No order note added

---

## Test Case 6: Missing Order ID

**Objective:** Verify callback rejects missing or invalid order ID.

### Steps

1. Simulate callback with:
   - Omit `order_id` parameter, or
   - `order_id=999999` (non-existent order)

### Expected Outcome

- [ ] HTTP response: wp_die message "Invalid order callback."
- [ ] Gateway log shows: "Callback rejected: missing or invalid order id." (error level)

---

## Test Case 7: Payment Method Mismatch

**Objective:** Verify callback rejects order not using this gateway.

### Steps

1. Create order using a different payment method (e.g., PayPal)
2. Simulate callback with that order's ID and valid key/nonce

### Expected Outcome

- [ ] HTTP response: wp_die message "Invalid payment method callback."
- [ ] Order **not updated**
- [ ] Gateway log shows: "Callback rejected: payment method mismatch for order {id}." (error level)

---

## Test Case 8: Refund from Admin

**Objective:** Verify refund button in admin (if implemented).

### Steps

1. Ensure order is in paid/processing state from Test Case 1
2. Go to WooCommerce order admin page
3. Look for "Refund" button or link

### Expected Outcome

- [ ] Refund section available (or scaffold message if not yet implemented)
- [ ] If implemented: refund creates a credit note and calls provider API
- [ ] If scaffold: message states "Refund support not yet implemented"

---

## Test Case 9: Callback with Payload Persistence

**Objective:** Verify full callback payload is stored for audit.

### Steps

1. Run Test Case 1
2. Check order meta

### Expected Outcome

- [ ] Order meta key `_wcrmpgs_callback_payload` contains full JSON from provider verification API
- [ ] JSON decodable and contains:
  - `result` field
  - `transaction.id` (or similar path)
  - `resultIndicator` (if present)

---

## Test Case 10: Transaction ID Extraction

**Objective:** Verify transaction ID is correctly extracted from various response formats.

### Steps

1. Run Test Case 1
2. Inspect provider response format (check gateway logs)
3. Verify transaction ID extraction handles:
   - `$response['transaction']['id']`
   - `$response['transaction'][0]['id']`
   - `$response['id']`

### Expected Outcome

- [ ] Transaction ID correctly set on order: `order->get_transaction_id()`
- [ ] Matches provider's transaction reference
- [ ] Also stored in meta: `_wcrmpgs_transaction_id`

---

## Logging Verification

After each test, inspect gateway logs at:

**WooCommerce > Status > Logs > wc-recurring-mpgs-{date}.log**

Expected log entries should include:

- `[info]` Order payment verified successfully
- `[warning]` Order payment verification failed
- `[error]` Callback rejected / Callback failed
- `[error]` Hosted checkout session creation failed

---

## Cleanup

After testing:

1. Disable debug mode in gateway settings (to reduce log spam)
2. Delete test orders from admin
3. Review final logs for any unexpected errors
4. Reset sandbox/test merchant account if applicable

---

## Known Limitations & Future Work

- Refund support is not yet implemented (Phase 2 scope)
- Recurring/MIT charging not yet implemented (Phase 3 scope)
- Webhook ingestion not yet implemented (Phase 4 scope)
- Tests assume synchronous callback flow; async webhooks are Phase 4

---

## Sign-Off

- **Tester Name:** ________________
- **Date:** ________________
- **All Tests Passed:** Yes / No
- **Notes:**
  ```
  
  
  ```

