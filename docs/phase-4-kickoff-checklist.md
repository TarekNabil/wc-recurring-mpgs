# Phase 4 Kickoff Checklist

## Objective

Implement recurring-payment core after Phase 3 gate is explicitly signed off.

## Preconditions

1. [X] Phase 3 signoff decision is GO in `docs/phase-3-signoff.md`
2. [X] Architecture decision section updated to GO in `docs/architecture.md`
3. [X] Test baseline green (`npm run test:all`)

## Workstreams

### 1) Token And Agreement Capture (Post-First CIT)

1. [X] Persist reusable token after first successful CIT
2. [X] Persist agreement metadata needed for MIT
3. [X] Add order/subscription meta mapping contract
4. [X] Add unit tests for token/metadata persistence

### 2) MIT PAY Request Builder (Version 100)

1. [X] Implement recurring MIT PAY payload builder
2. [X] Add strict validation for required MIT fields
3. [X] Add response normalization/error mapping
4. [X] Add unit tests for success/failure payload and mapping paths

### 3) Manual Admin MIT Charge Action

1. [X] Add admin action trigger for manual MIT charge
2. [X] Add capability/nonce validation for admin action
3. [X] Add order notes/logging for attempt outcomes
4. [X] Add integration coverage for admin charge flow

### 4) Renewal Orchestration Hooks

1. [X] Add WooCommerce Subscriptions renewal hook wiring
2. [X] Implement idempotency guard to prevent duplicate renewal charges
3. [X] Persist renewal attempt metadata and transaction IDs
4. [X] Add integration tests for renewal success/failure/retry behavior

## Definition Of Done (Phase 4)

1. [X] Recurring token + agreement metadata path is implemented and tested
2. [X] MIT request builder is implemented with passing tests
3. [X] Manual admin MIT charge action is implemented and validated
4. [X] Renewal hooks are implemented with duplicate-charge protection
5. [X] Unit and integration suites pass (`npm run test:all`)
6. [ ] Architecture and implementation docs updated for Phase 4 completion status
