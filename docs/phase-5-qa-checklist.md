# Phase 5 QA Checklist

**Document Version:** 1.0  
**Date:** 2026-06-22  
**Status:** Ready for full QA  
**Lead:** Engineering team  

---

## Overview

Phase 5 introduces webhook/notification ingestion with duplicate-event idempotency and transient-error retry support. This QA checklist validates the complete webhook lifecycle: ingestion, reconciliation, retry logic, and integration with existing CIT/MIT flows.

---

## 1. Webhook Ingestion & Endpoints

### 1.1 Endpoint Registration
- [ ] Webhook endpoint exists at `/wp-json/wc/v3/wcrmpgs_webhook`
- [ ] Notification endpoint exists at `/wp-json/wc/v3/wcrmpgs_notification`
- [ ] Both endpoints return HTTP 200 on success
- [ ] Both endpoints accept JSON POST requests
- [ ] Both endpoints execute `WCRMPGS_Webhook_Controller::handle_request()`

### 1.2 Payload Read (JSON, Form, PHP Input)
- [ ] Webhook reads `$_POST` data if JSON not available
- [ ] Webhook reads `php://input` stream if $_POST is empty
- [ ] Webhook correctly decodes JSON payloads
- [ ] Webhook gracefully handles malformed JSON (returns empty array)
- [ ] Webhook gracefully handles empty payloads

### 1.3 HTTP Response Codes
- [ ] Success (200): Event accepted and ingested, or duplicate event silently accepted
- [ ] Transient Error (503): Unknown result code identified as transient; provider should retry
- [ ] Permanent Error (400): Bad request (e.g., missing order, payment method mismatch)

---

## 2. Payload Normalization

### 2.1 Field Extraction (Multiple Provider Formats)
- [ ] Order ID extracted from: `order_id`, `orderId`, `order.id`, `order.reference`, `merchantOrderId`
- [ ] Event ID extracted from: `eventId`, `notificationId`, `id`
- [ ] Event Type extracted from: `eventType`, `notificationType`, `type`, `transaction.type`
- [ ] Result extracted from: `result`, `status`, `transaction.result`, `transaction.status`
- [ ] Transaction ID extracted from: `transaction.id`, `transactionId`, `id`

### 2.2 Fallback Event ID Generation
- [ ] Event ID is generated if provider does not send one
- [ ] Fallback ID is deterministic: MD5(order_id|event_type|result|transaction_id)
- [ ] Fallback ID is prefixed with `wcrmpgs-webhook-`
- [ ] Fallback ID remains consistent for identical payloads

### 2.3 Nested Path Resolution
- [ ] Nested paths are traversed correctly (e.g., `transaction.result` extracts `payload['transaction']['result']`)
- [ ] Missing intermediate keys skip to next path gracefully
- [ ] First non-empty value is returned (ordering matters)
- [ ] Empty strings and null values are skipped

---

## 3. Duplicate Event Idempotency

### 3.1 Event ID Tracking
- [ ] Processed event IDs stored in order meta `_wcrmpgs_webhook_event_ids`
- [ ] Meta stored as JSON array
- [ ] Maximum 25 events stored per order (rolling buffer)
- [ ] Oldest events pruned when buffer exceeds 25
- [ ] Event ID array is always unique (no duplicates in list)

### 3.2 Duplicate Detection
- [ ] First identical event is processed normally
- [ ] Second identical event (same event_id) is silently accepted
- [ ] Duplicate event returns HTTP 200 with "Duplicate webhook event ignored"
- [ ] Duplicate event does NOT trigger order state change
- [ ] Duplicate event does NOT add multiple order notes
- [ ] Duplicate event does NOT double-charge the order

### 3.3 Event ID Variants
- [ ] Event with `eventId` is tracked by that ID
- [ ] Event without `eventId` gets fallback ID and is tracked
- [ ] Fallback ID is consistent across retries (same order_id, event_type, result, transaction_id → same ID)
- [ ] Two different fallback IDs do not collide (different payloads → different hashes)

---

## 4. Order Validation

### 4.1 Order Existence
- [ ] Webhook accepted for valid order ID (HTTP 200)
- [ ] Webhook accepted with message if order not found (HTTP 200, no-op, no order modification)
- [ ] Order ID 0 or non-existent ID does not cause errors
- [ ] Webhook gracefully logs missing-order events

### 4.2 Payment Method Validation
- [ ] Webhook processed for orders with payment_method='merchant_payments'
- [ ] Webhook rejected if payment_method is different (HTTP 400)
- [ ] Webhook rejected message indicates "payment method mismatch"
- [ ] Webhook does NOT process other gateway orders (e.g., Stripe, PayPal)

### 4.3 Order Meta Preservation
- [ ] Last webhook payload stored in `_wcrmpgs_webhook_last_payload`
- [ ] Last event type stored in `_wcrmpgs_webhook_last_event_type`
- [ ] Last received timestamp stored in `_wcrmpgs_webhook_received_at` (UTC datetime)
- [ ] Error code stored in `_wcrmpgs_webhook_last_error` (if applicable)
- [ ] Retry guidance stored in `_wcrmpgs_webhook_retry_after_seconds` (if transient)

---

## 5. Order Reconciliation (Success Path)

### 5.1 Success Result Codes
- [ ] Result codes `SUCCESS`, `APPROVED`, `CAPTURED`, `PAID`, `COMPLETED` are recognized
- [ ] Case-insensitive matching (lowercase `success` → recognized)
- [ ] Each result code triggers payment_complete()

### 5.2 Success State Transition
- [ ] Order marked as paid (is_paid() == true)
- [ ] Transaction ID set from webhook if not already set
- [ ] Order status unchanged if already paid (does not re-trigger status hooks)
- [ ] Order note added: "MPGS webhook notification marked payment successful. Event: {type}, Transaction: {ID}"

### 5.3 Transaction ID Persistence
- [ ] Transaction ID from webhook stored in order meta `_wcrmpgs_transaction_id`
- [ ] Transaction ID set via `set_transaction_id()`
- [ ] Transaction ID not overwritten if already set
- [ ] Webhook without transaction ID still marks order paid (note shows N/A)

---

## 6. Order Reconciliation (Failure Path)

### 6.1 Failure Result Codes
- [ ] Result codes `FAILURE`, `DECLINED`, `REJECTED`, `ERROR`, `CANCELLED` are recognized
- [ ] Case-insensitive matching
- [ ] Each result code triggers status transition to `failed`

### 6.2 Failure State Transition
- [ ] Order status set to `failed` (if not already paid or failed)
- [ ] Failure note added: "MPGS webhook notification reported failure. Event: {type}, Result: {code}"
- [ ] Order is not modified if already marked paid (one-way: success is terminal)
- [ ] Order can transition from pending → failed on first failure notification

### 6.3 Failure Metadata
- [ ] Error code stored for audit trail
- [ ] Failure timestamp preserved in webhook_received_at
- [ ] Last payload stored for debugging

---

## 7. Order Reconciliation (Unknown/Transient Path)

### 7.1 Unknown Result Code Detection
- [ ] Unknown result codes (not in success or failure lists) are detected
- [ ] Unknown codes trigger transient error check
- [ ] Unknown codes return HTTP 400 by default (not retried by provider)

### 7.2 Transient Error Classification
- [ ] Transient codes: `PROCESSING`, `PENDING`, `TIMEOUT`, `TEMPORARILY_UNAVAILABLE`, `SERVICE_UNAVAILABLE`, `NETWORK_ERROR`, `TRY_AGAIN`, `RETRY`
- [ ] Transient code returns HTTP 503 (Service Unavailable)
- [ ] Non-transient unknown codes return HTTP 400 (Bad Request)
- [ ] Case-insensitive code matching

### 7.3 Transient Error Metadata
- [ ] Transient error stored in `_wcrmpgs_webhook_last_error`
- [ ] Retry after 60 seconds stored in `_wcrmpgs_webhook_retry_after_seconds`
- [ ] Order note added: "MPGS webhook notification received with unknown result code: {code} (Result: {type}, Retryable: Yes)"
- [ ] Non-transient unknown codes add note: "Retryable: No (permanent result)"

### 7.4 No Order State Change on Unknown
- [ ] Unknown transient code does NOT mark order as paid or failed
- [ ] Unknown permanent code does NOT mark order as paid or failed
- [ ] Unknown codes are logged for merchant review, requiring manual action if needed

---

## 8. Integration with CIT Flow

### 8.1 CIT → Webhook Reconciliation Path
- [ ] Customer completes CIT checkout via Areeba Hosted Checkout
- [ ] Customer redirected back with callback result
- [ ] CIT callback processed (existing flow)
- [ ] Webhook notification arrives separately
- [ ] Webhook recognizes order and event ID
- [ ] Webhook duplicate check prevents double-processing
- [ ] Order remains paid (no duplicate payment_complete call)

### 8.2 Callback vs Webhook Order
- [ ] If webhook arrives first, then callback: order reconciled twice, callback overwrites webhook result (existing callback logic)
- [ ] If callback arrives first, then webhook duplicate: webhook silently ignored, order stays paid
- [ ] Both paths eventually mark order paid with correct transaction ID

---

## 9. Integration with Renewal (MIT) Flow

### 9.1 Renewal Initiated
- [ ] Subscription renewal scheduled
- [ ] `woocommerce_scheduled_subscription_payment_{gateway}` hook triggered
- [ ] Manual MIT call sent via API: `PUT /order/{id}/transaction/{trans_id}` with agreement block
- [ ] Renewal transaction ID assigned

### 9.2 Renewal Webhook Reconciliation
- [ ] Provider sends PAYMENT.CAPTURED webhook after MIT success
- [ ] Webhook contains renewal transaction ID
- [ ] Order reconciled: marked paid, note added, event tracked
- [ ] Subscription renewal marked complete (via existing renewal hook)

### 9.3 Renewal Failure Path
- [ ] Provider sends PAYMENT.FAILED webhook after MIT failure
- [ ] Webhook contains failure result code
- [ ] Order marked failed
- [ ] Subscription renewal retry triggered (via WC subscription logic)

---

## 10. Integration with Manual MIT Order Action

### 10.1 Manual MIT Initiation
- [ ] Merchant clicks "Run Manual MIT Charge" on existing order
- [ ] Manual MIT call sent: `PUT /order/{id}/transaction/{trans_id}` with agreement block
- [ ] Transaction ID assigned
- [ ] Order note added: "Manual MIT charge initiated..."

### 10.2 Manual MIT Webhook Reconciliation
- [ ] Provider sends PAYMENT.CAPTURED webhook
- [ ] Webhook reconciles order: marks paid
- [ ] Order note from webhook added
- [ ] Manual MIT action result confirmed via webhook (not just API response)

---

## 11. Logging & Observability

### 11.1 Webhook Events Logged
- [ ] `webhook_accepted` for valid webhooks
- [ ] `webhook_duplicate_event` for duplicate event IDs
- [ ] `webhook_ignored_missing_order` for no order_id
- [ ] `webhook_ignored_unknown_order` for order not found
- [ ] `webhook_payment_success` for success codes
- [ ] `webhook_payment_failure` for failure codes
- [ ] `webhook_unknown_result` for unknown codes (with transient flag)

### 11.2 Log Source
- [ ] All webhook logs have source `wc-recurring-mpgs-webhook`
- [ ] Logs accessible via WooCommerce admin logger
- [ ] Context includes order_id, event_id, event_type, result, transaction_id
- [ ] Timestamp recorded for each event

### 11.3 Error Debugging
- [ ] Payload stored in order meta for manual inspection
- [ ] Error codes stored for traceability
- [ ] Retry guidance stored for merchant review
- [ ] Order notes include sufficient detail for support troubleshooting

---

## 12. Edge Cases & Error Scenarios

### 12.1 Malformed Payloads
- [ ] Empty payload `{}` accepted (returns 200, no-op)
- [ ] Payload missing all expected fields accepted (returns 200, no-op)
- [ ] Payload with only order_id, no event_type: accepted, fallback event_id generated
- [ ] Payload with nested deeply: normalized correctly via path traversal
- [ ] Payload with extra unknown fields: ignored gracefully

### 12.2 Order Edge Cases
- [ ] Order with no transaction ID: webhook sets it correctly
- [ ] Order with existing transaction ID: webhook does not overwrite (preserves original)
- [ ] Order already paid: webhook duplicate does not trigger payment hooks again
- [ ] Order already failed: webhook success can transition back to paid
- [ ] Order with no payment method: webhook rejected (payment method mismatch)

### 12.3 Concurrency & Race Conditions
- [ ] Two identical webhooks arrive simultaneously: one processed, one accepted as duplicate
- [ ] Webhook arrives during callback processing: order state consistent (one path wins)
- [ ] Webhook arrives during manual MIT action: order state consistent
- [ ] Event buffer at max capacity (25): oldest event pruned correctly

### 12.4 Data Integrity
- [ ] Order meta not corrupted on webhook ingestion
- [ ] Event ID buffer remains valid JSON
- [ ] Transaction ID not lost on duplicate webhook
- [ ] Payment status not downgraded by later webhook (success is terminal)

---

## 13. Provider-Specific Scenarios (Areeba Example)

### 13.1 Areeba Webhook Format
- [ ] Areeba sends PAYMENT.CAPTURED result code (mapped to SUCCESS)
- [ ] Areeba sends transaction.id and transaction.reference
- [ ] Areeba sends orderId, eventId, eventType fields
- [ ] Areeba webhook normalizes correctly via path resolution

### 13.2 Areeba Failure Format
- [ ] Areeba sends PAYMENT.FAILED result code (mapped to FAILURE)
- [ ] Areeba sends error or decline reason in payload
- [ ] Order marked failed with merchant-visible note
- [ ] Failure logged for debugging

### 13.3 Areeba Retry Scenario
- [ ] Areeba sends unknown result code (e.g., PROCESSING)
- [ ] Plugin returns HTTP 503
- [ ] Areeba retries after delay
- [ ] Duplicate event check prevents duplicate reconciliation

---

## 14. Merchant Workflow Validation

### 14.1 Happy Path (CIT)
- [ ] Customer places order, completes CIT checkout
- [ ] Order marked pending payment
- [ ] Customer completes Hosted Checkout form
- [ ] Callback received: order marked paid
- [ ] Webhook received: duplicate ignored, order remains paid
- [ ] Merchant confirms payment in WooCommerce admin

### 14.2 Renewal Happy Path
- [ ] Subscription renews on schedule
- [ ] MIT charge sent automatically
- [ ] Webhook confirms payment
- [ ] Subscription renewed, next charge date set
- [ ] Customer sees renewal in subscription history

### 14.3 Recovery Scenario (Webhook Delayed)
- [ ] MIT charge initiated manually or via renewal
- [ ] Webhook delayed (e.g., 30 min later)
- [ ] Webhook arrives: order already marked paid (from callback)
- [ ] Webhook duplicate check: event ID persisted from callback
- [ ] Webhook silently ignored, order unchanged

### 14.4 Failure & Retry Scenario
- [ ] Webhook arrives with TEMPORARILY_UNAVAILABLE
- [ ] Order note added: "Retryable: Yes"
- [ ] HTTP 503 returned to provider
- [ ] Provider retries after 60 seconds
- [ ] Retry webhook arrives: duplicate check ignores duplicate
- [ ] OR successful webhook arrives: order marked paid

---

## 15. Documentation & Support

### 15.1 Webhook Setup Documentation
- [ ] `docs/WEBHOOK_SETUP.md` exists and is complete
- [ ] Endpoint URLs clearly documented
- [ ] Payload format examples provided
- [ ] Duplicate handling explained
- [ ] Retry logic explained
- [ ] Troubleshooting guide included

### 15.2 Merchant Documentation
- [ ] How to register webhooks with Areeba
- [ ] Event types supported (PAYMENT.CAPTURED, PAYMENT.FAILED)
- [ ] Log location and format
- [ ] How to verify webhook receipt (order notes, order meta)
- [ ] Manual retry instructions (if webhook lost)

### 15.3 Developer Documentation
- [ ] Architecture docs updated for Phase 5
- [ ] Implementation docs updated for Phase 5
- [ ] Code comments added to webhook controller
- [ ] Inline documentation for transient error classification
- [ ] Decision rationale documented (HTTP 503 vs 400)

---

## 16. Performance & Load Testing

### 16.1 Webhook Processing Speed
- [ ] Single webhook processed in < 200ms
- [ ] Order update persisted within 1 second
- [ ] No timeout on order operations (default WP timeout adequate)

### 16.2 Concurrent Webhooks
- [ ] 10 webhooks for same order: handled without race condition
- [ ] 100 webhooks total: processed without data loss
- [ ] Event buffer pruned correctly (stays ≤ 25 events)

### 16.3 Database Impact
- [ ] Order meta table not bloated by webhook data
- [ ] Event ID buffer remains < 1KB per order
- [ ] Last payload stored but not queried frequently (audit only)

---

## 17. Test Coverage

### 17.1 Unit Tests
- [ ] `test_normalize_payload_extracts_order_id_from_multiple_formats`
- [ ] `test_normalize_payload_extracts_event_id_from_multiple_formats`
- [ ] `test_normalize_payload_extracts_result_from_nested_paths`
- [ ] `test_build_fallback_event_id_is_deterministic`
- [ ] `test_build_fallback_event_id_is_consistent_across_retries`
- [ ] `test_is_transient_error_returns_true_for_processing`
- [ ] `test_is_transient_error_returns_false_for_unknown`
- [ ] `test_is_transient_error_is_case_insensitive`

### 17.2 Integration Tests
- [ ] `test_webhook_ingests_success_and_marks_order_paid`
- [ ] `test_webhook_ignores_duplicate_event`
- [ ] `test_webhook_processes_failure_and_marks_order_failed`
- [ ] `test_webhook_unknown_transient_code_returns_503`
- [ ] `test_webhook_unknown_permanent_code_returns_400`
- [ ] `test_webhook_reconciles_order_with_missing_order_id`
- [ ] `test_webhook_processes_renewal_notification`
- [ ] `test_webhook_respects_payment_method_validation`

### 17.3 Acceptance Tests
- [ ] Full CIT → Callback → Webhook flow (e2e)
- [ ] Renewal → MIT → Webhook flow (e2e)
- [ ] Webhook duplicate handling with realistic delays
- [ ] Provider retry scenario (transient → success)

---

## Sign-Off Criteria

**Phase 5 QA is COMPLETE when:**

1. ✅ All webhook ingestion tests pass (endpoints, payload reading, normalization)
2. ✅ All duplicate-event idempotency tests pass
3. ✅ All order reconciliation tests pass (success, failure, unknown paths)
4. ✅ All integration tests pass (CIT, renewal, manual MIT)
5. ✅ All edge case tests pass
6. ✅ Merchant can successfully receive and process webhooks in staging
7. ✅ Manual testing confirms no duplicate charges on retries
8. ✅ Documentation complete and reviewed
9. ✅ Static code analysis passing (no lint errors)
10. ✅ Performance tests confirm < 200ms per webhook
11. ✅ Team sign-off from engineering and product

---

## Known Limitations & Future Work

- [ ] **HTTP signature verification**: Webhook payload signing not yet implemented (planned for Phase 5.5)
- [ ] **Webhook event filtering**: Merchant can't customize which events to receive (planned for Phase 6)
- [ ] **Retry exponential backoff**: Fixed 60-second retry not customizable (planned for Phase 5.5)
- [ ] **Webhook delivery metrics**: No admin dashboard showing delivery success rate (planned for Phase 6)

---

## Questions & Escalations

| Question | Owner | Status |
|----------|-------|--------|
| Can merchants manually re-trigger webhook reconciliation if delivery was lost? | TBD | Open |
| Should we implement webhook signature verification before go-live? | TBD | Open |
| What's the fallback if webhook delivery is permanently lost? | TBD | Open |

