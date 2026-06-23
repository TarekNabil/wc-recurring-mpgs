# Phase 4 Pre-Phase 5 Manual QA Checklist

## Purpose

Use this checklist to manually validate all implemented features before starting Phase 5.

## Test Environment

- [X] WordPress + WooCommerce running
- [X] WooCommerce Subscriptions active
- [X] Gateway enabled in WooCommerce payments settings
- [X] Recurring payments enabled in gateway settings
- [X] Service host, merchant ID, and API password configured
- [X] Test card and sandbox account available
- [X] Debug mode enabled (optional, recommended)

Environment details:
- URL: ___http://localhost:8888/_______________________
- Plugin version/branch: ______version 1 phase 4____________________
- Tester: ________Tarek Nabil __________________
- Date: ____________21 Jun 26______________

---

## A) Hosted Checkout (CIT) Flow

### A1. Session Creation + Redirect
- [X] Place a new order with gateway selected
- [X] Confirm redirect to hosted checkout
- [X] Confirm return URL includes order identity and nonce

Expected:
- [X] No session creation error at checkout
- [X] Order note indicates checkout session created
- [X] Session/order metadata exists in order admin view

### A2. Successful Payment Callback
- [X] Complete payment successfully in sandbox
- [X] Return to store and open order admin

Expected:
- [X] Order is paid (processing/completed)
- [X] Transaction ID is stored
- [X] Callback payload/result metadata is stored
- [X] Success order note is present

### A3. Callback Indicator Mismatch
- [X] Simulate mismatch/failure callback scenario

Expected:
- [X] Order is not marked paid
- [X] Order status becomes failed
- [X] Failure note indicates verification mismatch/failure
- [X] Customer can retry payment

### A4. Callback Security Rejections
- [X] Trigger callback with invalid/missing nonce
- [X] Trigger callback with wrong payment method
- [X] Trigger callback with invalid key

Expected:
- [X] Request is rejected 
- [X] No paid status is granted
- [X] No false success metadata is written

---

## B) Refund / Void Flow

### B1. Successful Refund
- [X] Refund full amount from WooCommerce admin
- [X] Refund partial amount from WooCommerce admin

Expected:
- [X] Refund operation succeeds
- [-] Refund transaction metadata is stored
- [-] Order note includes amount/currency/reason

### B2. Refund Failure Paths
- [-] Attempt refund where original transaction is missing
- [-] Simulate provider reject on refund

Expected:
- [-] Error is shown and/or WP error path triggered
- [-] Failure note appears on order
- [-] No invalid refund metadata indicates success

---

## C) Recurring Contract Capture (Post-CIT)

### C1. Token + Agreement Capture on Successful CIT
- [X] Complete a successful initial CIT order
- [X] Inspect order meta

Expected:
- [X] Recurring token meta exists
- [X] Agreement ID/type/source meta exists when provider returns them
- [X] Contract captured timestamp meta exists
- [X] Order note confirms recurring contract captured

### C2. Subscription Meta Mirroring (If subscription linked)
- [X] Ensure successful parent order is linked to subscription
- [X]] Inspect subscription meta

Expected:
- [X] Token/agreement contract metadata is mirrored to subscription

---

## D) Manual Admin MIT Charge Action

### D1. Action Visibility
- [ ] Open order using this gateway with token present

Expected:
- [ ] Admin order action "MPGS: Run Manual MIT Charge" is available

### D2. Success Path
- [ ] Run manual MIT action from order actions

Expected:
- [ ] MIT request is sent successfully
- [ ] Order note indicates manual MIT success with transaction id
- [ ] MIT request/response/attempt metadata is persisted
- [ ] Last MIT transaction metadata is updated

### D3. Security Validation
- [ ] Attempt with invalid nonce
- [ ] Attempt as user without required capability

Expected:
- [ ] Action is blocked
- [ ] Block/failure note is recorded
- [ ] No successful charge metadata is written

### D4. Missing Token / Config Guardrails
- [ ] Remove token and run action
- [ ] Disable recurring setting and run action
- [ ] Break credentials and run action

Expected:
- [ ] Clear failure outcome
- [ ] Failure note/log is present

---

## E) Renewal Orchestration (Subscriptions)

### E1. Scheduled Renewal Success
- [ ] Trigger scheduled renewal payment for subscription

Expected:
- [ ] Renewal hook executes MIT flow
- [ ] Renewal order is marked paid on success
- [ ] Renewal success note includes transaction id
- [ ] Renewal attempt metadata stored (attempted at, request, response, result)

### E2. Renewal Failure
- [ ] Simulate provider decline/error during renewal charge

Expected:
- [ ] Renewal order not marked paid
- [ ] Renewal order marked failed with failure reason
- [ ] Renewal failure note and logs are present
- [ ] Renewal attempt metadata records failed result

### E3. Retry After Failure
- [ ] Re-trigger renewal after a failed attempt

Expected:
- [ ] Retry is allowed
- [ ] Renewal can succeed on later attempt
- [ ] Renewal attempt metadata updates accordingly

### E4. Duplicate Charge Protection
- [ ] After successful renewal, trigger same renewal path again

Expected:
- [ ] Duplicate attempt is blocked
- [ ] No second successful charge is created
- [ ] Idempotency guard result is visible in metadata/logs

---

## F) Operational Observability

- [ ] Order notes are clear for all outcomes (success/failure/block)
- [ ] Debug logs contain meaningful entries for MIT/renewal events
- [ ] Metadata keys are consistent and readable for support/debugging

---

## G) Regression Sweep

- [ ] Standard checkout still works for one-time orders
- [ ] Failed checkout retry still works
- [ ] Refund flow still works after recurring features
- [ ] Existing integration behavior unchanged for non-recurring orders

---

## Final Signoff

Result:
- [X] GO to Phase 5
- [ ] NO-GO to Phase 5

Blocking issues:
1. Can't fully check refund function before getting access to the sandbox account
2. Switched to classic checkout to avoid some compatibility issue with donation platform
3. ______________________________________________

Approved by: Tarek Nabil__________________________
Date: __________________22jun________
