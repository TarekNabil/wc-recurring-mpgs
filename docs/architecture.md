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
```

## Delivery Phases

## Current Stage

Current stage is Phase 4 kickoff for recurring core implementation.

Implemented at this stage:

1. Hosted checkout session creation and redirect flow.
2. Callback verification and order finalization.
3. Failed/canceled callback retry routing.
4. Refund/void operations using provider-compatible transport methods.
5. Blocks checkout registration and classic checkout compatibility.
6. Green automated suites (unit + integration).
7. Phase 3 manual sandbox smoke checklist is signed off.

Not started in this stage:

1. Recurring token capture and agreement storage.
2. MIT recurring renewals and scheduling hooks.
3. Webhook reconciliation and retry policy automation.

### Phase 1

1. Plugin bootstrap.
2. Gateway registration.
3. Hosted checkout session builder.
4. Safe settings model.
5. Blocks adapter scaffolding.

### Phase 2

1. ✅ Implement callback verification.
2. ✅ Persist transaction metadata.
3. ✅ Implement refund and void requests.
4. ✅ Add order notes and structured logging.

### Phase 3

1. ✅ Complete a testable one-time payment release gate.
2. ✅ Validate end-to-end hosted checkout success flow in sandbox.
3. ✅ Validate callback failures and customer retry behavior.
4. ✅ Validate merchant operational flow (logs, order notes, admin visibility).
5. ✅ Freeze one-time payment contract (meta keys, statuses, and error mapping).
6. ✅ Require green automated suites (unit + integration) before feature expansion.

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
3. Payload normalization and order-event reconciliation are now being implemented.

## Phase 3 Exit Criteria (Testable One-Time Payments)

The project does not move to recurring payments until all items below pass:

1. Customer can place and complete a one-time payment successfully through hosted checkout.
2. Callback verification marks paid orders correctly and failed callbacks do not mark orders paid.
3. Transaction metadata is persisted and traceable in WooCommerce order data.
4. Integration tests for one-time payment flow pass in the test container.
5. Unit tests for payload and API client behavior pass.
6. Manual sandbox smoke tests are documented and signed off.

See detailed gate contract and checklist in `docs/one-time-payment-gate.md`.

## Current Phase Decision (As Of 2026-06-22)

**Decision:** GO for Phase 5 start.

Current evidence:

1. ✅ Automated suites were green before the phase transition.
2. ✅ Manual sandbox smoke tests are documented and signed off.

Phase 5 implementation is authorized to proceed using the kickoff checklist.

Use `docs/phase-5-kickoff-checklist.md` to execute the Phase 5 implementation scope.

## Current Scaffold Status

Current implementation includes:

1. A separate plugin namespace and folder.
2. A hosted checkout session request builder.
3. A transport wrapper around provider REST calls.
4. Callback verification and order finalization flow.
5. A blocks integration shell.
6. Unit and integration test scaffolding.

It is not production-ready yet. Webhook reconciliation, retry policy automation, merchant documentation, and the remaining Phase 5 QA gates still need to be completed.