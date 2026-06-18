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

### Phase 1

1. Plugin bootstrap.
2. Gateway registration.
3. Hosted checkout session builder.
4. Safe settings model.
5. Blocks adapter scaffolding.

### Phase 2

1. Implement callback verification.
2. Persist transaction metadata.
3. Implement refund and void requests.
4. Add order notes and structured logging.

### Phase 3

1. Store reusable token and agreement metadata after the first successful CIT.
2. Add recurring request builder for version 100 MIT PAY.
3. Add manual admin MIT charge action.
4. Add renewal orchestration hooks.

### Phase 4

1. Add webhook and notification ingestion.
2. Add retry policy and duplicate-charge protection.
3. Add tests for payload builders and response handlers.
4. Update merchant-facing documentation.

## Current Scaffold Status

This initial scaffold registers a new gateway and provides:

1. A separate plugin namespace and folder.
2. A hosted checkout session request builder.
3. A transport wrapper around provider REST calls.
4. Placeholder callback and refund entry points.
5. A blocks integration shell.

It is not production-ready yet. Callback verification, refund execution, token capture, recurring MIT, webhooks, and renewal scheduling remain to be implemented.