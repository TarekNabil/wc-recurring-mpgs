# One-Time Payment Release Gate

This gate must stay green before starting recurring-payment implementation.

## Scope

One-time hosted checkout only (customer-initiated transaction), including callback verification and refund operations.

## Contract (Frozen)

### Order Meta Keys

1. `_wcrmpgs_success_indicator`
2. `_wcrmpgs_session_id`
3. `_wcrmpgs_session_version`
4. `_wcrmpgs_result_indicator`
5. `_wcrmpgs_result`
6. `_wcrmpgs_callback_payload`
7. `_wcrmpgs_transaction_id`
8. `_wcrmpgs_last_refund_payload`
9. `_wcrmpgs_last_refund_transaction_id`

### Expected Status Behavior

1. Successful callback verification: order marked paid via `payment_complete()`.
2. Indicator mismatch or failed verification: unpaid order moves to `failed`.
3. Refund success: `process_refund()` returns `true` and stores refund payload metadata.
4. Refund failure: `process_refund()` returns `WP_Error` with a mapped code/message.

### Error Mapping (Gateway)

1. `wcrmpgs_refund_invalid_order`
2. `wcrmpgs_refund_missing_credentials`
3. `wcrmpgs_refund_invalid_amount`
4. `wcrmpgs_refund_missing_transaction`
5. `wcrmpgs_refund_invalid_response`
6. `wcrmpgs_refund_rejected`

## Automated Gate Checks

Run all and require green status:

```bash
npm run test:unit
npm run test:integration
```

## Manual Sandbox Checks

1. Successful hosted checkout -> paid order + transaction ID
2. Callback indicator mismatch -> failed order + retry path
3. Invalid nonce callback -> hard reject
4. Invalid payment-method callback -> hard reject
5. Refund success (full and partial if supported)
6. Refund failure path (missing transaction or provider reject)

## Exit Decision

Recurring payment work starts only when:

1. Contract keys/statuses above are stable.
2. Automated suites pass on CI-equivalent environment.
3. Manual sandbox checks are signed off.

Record the decision and checklist completion in `docs/phase-3-signoff.md`.
