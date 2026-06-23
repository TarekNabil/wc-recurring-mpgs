# Webhook Setup & Troubleshooting Guide

**For:** WooCommerce Store Operators & Merchants  
**Plugin:** WC Recurring MPGS Payment Gateway  
**Version:** Phase 5  
**Date:** 2026-06-22

---

## What Are Webhooks?

A **webhook** is an automatic notification sent by the payment provider (Areeba) to your WooCommerce store when a payment event occurs. Instead of your store repeatedly checking "is the payment complete?", Areeba proactively tells your store "the payment was captured" or "the payment failed."

**Why webhooks matter:**
- 🚀 **Faster order updates** — Orders marked paid immediately, not after 5-10 minutes
- 🔄 **Automatic renewals** — Subscription renewals process faster with webhook confirmation
- 🛡️ **Duplicate protection** — Webhooks are idempotent; if Areeba sends the same notification twice, your order won't be double-charged
- 📊 **Better reliability** — If the customer closes their browser after payment, webhooks still confirm the payment

---

## Webhook Endpoints

Your WooCommerce store has two webhook endpoints ready to receive notifications from Areeba:

```
Production Endpoint:
https://yourstore.com/wp-json/wc/v3/wcrmpgs_webhook

Backup Endpoint (same functionality):
https://yourstore.com/wp-json/wc/v3/wcrmpgs_notification
```

Replace `yourstore.com` with your actual WordPress domain.

**Endpoint Details:**
- **Protocol:** HTTPS (required for security)
- **Method:** POST
- **Content-Type:** `application/json`
- **Timeout:** Your store waits up to 60 seconds for a response
- **Authentication:** Currently none (signature verification planned for Phase 5.5)

---

## Registering Webhooks with Areeba

### Step 1: Access Areeba Dashboard

1. Log in to your [Areeba Merchant Portal](https://merchant.areeba.com)
2. Navigate to **Settings** → **Webhooks** or **Notifications**

### Step 2: Add a Webhook URL

1. Click **Add Webhook** or **Register New Endpoint**
2. **URL:** Paste your webhook endpoint URL:
   ```
   https://yourstore.com/wp-json/wc/v3/wcrmpgs_webhook
   ```
3. **Event Types:** Select the events you want to receive:
   - ✅ **PAYMENT.CAPTURED** — Order payment succeeded
   - ✅ **PAYMENT.FAILED** — Order payment failed or declined
   - ✅ **PAYMENT.CANCELLED** — Customer cancelled the payment
   - ✅ **PAYMENT.PENDING** — Payment is processing (optional)
   - ✅ **RECURRING.AUTHORIZED** — Subscription agreement authorized (optional)

4. **Retry Policy:** 
   - Recommended: **Exponential backoff** or **Areeba default**
   - The plugin returns HTTP 503 for transient errors (e.g., PROCESSING), signaling Areeba to retry
   - The plugin returns HTTP 200 for permanent events, stopping further retries

5. **Save & Test**

### Step 3: Test the Webhook

Areeba should provide a **Test** or **Send Sample** button:

1. Click **Test**
2. Check your order notes in WooCommerce — You should see a note like:
   ```
   MPGS webhook notification marked payment successful. Event: PAYMENT.CAPTURED, Transaction: abc123xyz
   ```

---

## Webhook Event Types & Mapping

Your store recognizes the following event result codes from Areeba:

| Event Type | Result Code | Your Store Action | Order Status |
|------------|-------------|-------------------|--------------|
| **Payment Success** | SUCCESS, APPROVED, CAPTURED, PAID, COMPLETED | Mark paid | ✅ Processing → Completed |
| **Payment Failure** | FAILURE, DECLINED, REJECTED, ERROR, CANCELLED | Mark failed | ❌ Pending → Failed |
| **Processing** | PROCESSING, PENDING, TEMPORARILY_UNAVAILABLE, SERVICE_UNAVAILABLE | No action, signal retry | ⏳ Pending (unchanged) |

**Note:** Result codes are case-insensitive. The plugin accepts `success` and `SUCCESS` equally.

---

## Verifying Webhooks Are Working

### Method 1: Check Order Notes

1. Log in to WooCommerce admin
2. Go to **Orders**
3. Click on any recent order
4. Scroll to **Order Notes** section
5. Look for entries like:
   ```
   MPGS webhook notification marked payment successful. Event: PAYMENT.CAPTURED, Transaction: t123456789
   ```

If you see these notes, webhooks are being received and processed ✅

### Method 2: Check Order Metadata

1. In the order edit page, scroll to **Order Data** → **Meta**
2. Look for these fields:
   - `_wcrmpgs_webhook_last_event_type` — Last event received (e.g., "PAYMENT.CAPTURED")
   - `_wcrmpgs_webhook_received_at` — Timestamp of last webhook (e.g., "2026-06-22 14:30:45")
   - `_wcrmpgs_webhook_event_ids` — List of all webhook event IDs received

### Method 3: Check Logs

1. In WooCommerce admin, go to **Status** → **Logs**
2. Look for logs with source: **wc-recurring-mpgs-webhook**
3. Recent log entries should show:
   ```
   [INFO] webhook_payment_success
   {
     "context": {
       "order_id": 12345,
       "event_id": "evt_123...",
       "event_type": "PAYMENT.CAPTURED",
       "result": "SUCCESS"
     }
   }
   ```

---

## Troubleshooting Guide

### Issue 1: Order Not Marked as Paid After Webhook Delivery

**Symptoms:**
- Order remains in "Pending Payment" status
- No webhook notes appear in order notes
- Payment was captured in Areeba (confirmed in Areeba dashboard)

**Diagnosis:**

1. **Check if webhook is being sent:**
   - Log in to Areeba Dashboard → Webhooks → Logs/History
   - Find the order ID and look for delivery status (Success, Failed, Pending)
   - If status is **Failed**, proceed to "Webhook Delivery Failed" below

2. **Check if order exists in WooCommerce:**
   - Verify the order ID in Areeba matches the WooCommerce order ID
   - WooCommerce admin → Orders → Search by order ID
   - If order not found, Areeba may be sending wrong order ID

3. **Check order payment method:**
   - Order must be using **"Merchant Payments (MPGS)"** as payment method
   - Edit order → **Billing** section → look for "Payment method" field
   - If different, the webhook is rejected as "payment method mismatch"

**Resolution:**

- ✅ **If webhook delivery failed in Areeba:** Check your endpoint URL is correct (no typos, HTTPS, not behind IP allowlist)
- ✅ **If order ID is wrong:** Contact Areeba support; they may be misconfiguring order ID mapping
- ✅ **If payment method is wrong:** Ensure order was created via WC Recurring MPGS gateway (not manually created or different gateway)

---

### Issue 2: Webhook Delivery Failed (HTTP Error)

**Symptoms:**
- Areeba reports webhook delivery status as "Failed"
- Areeba logs show HTTP error (e.g., 404, 403, 500)
- Order remains unpaid

**Common HTTP Errors & Fixes:**

| HTTP Code | Meaning | Fix |
|-----------|---------|-----|
| **404 Not Found** | Endpoint URL doesn't exist | Verify URL is correct: `https://yourstore.com/wp-json/wc/v3/wcrmpgs_webhook` (no typos, correct domain) |
| **403 Forbidden** | Server blocking the request | Check if your server has IP allowlist enabled; Areeba's IP may be blocked |
| **500 Server Error** | WordPress error | Check WooCommerce system status; enable WordPress debug logging to `wp-content/debug.log` |
| **503 Service Unavailable** | Plugin detected transient error; Areeba should retry | No action needed; Areeba will retry in 60 seconds |
| **Connection Timeout** | Webhook URL took too long to respond | Check server performance; WooCommerce hooks may be running slow |

**Resolution Steps:**

1. **Test endpoint manually:**
   ```bash
   curl -X POST https://yourstore.com/wp-json/wc/v3/wcrmpgs_webhook \
     -H "Content-Type: application/json" \
     -d '{"order_id": 12345, "status": "CAPTURED"}'
   ```
   - Should return HTTP 200 with JSON response
   - If error, WordPress is rejecting the request

2. **Check WordPress debug log:**
   - Enable debug mode in `wp-config.php`:
     ```php
     define('WP_DEBUG', true);
     define('WP_DEBUG_LOG', true);
     ```
   - Errors appear in `wp-content/debug.log`
   - Look for PHP errors or permission issues

3. **Verify WooCommerce REST API is enabled:**
   - WooCommerce admin → **Settings** → **Advanced**
   - Check "Enable REST API" is enabled

4. **Check server firewall/IP allowlist:**
   - Ask your hosting provider if they're blocking outbound API calls
   - Whitelist Areeba's IP addresses (get list from Areeba support)

---

### Issue 3: Duplicate Orders or Double-Charging

**Symptoms:**
- Same order marked as paid multiple times
- Order notes show multiple "webhook marked payment successful" entries
- Customer charged twice

**This should NOT happen** because of built-in duplicate protection, but if it does:

**Diagnosis:**

1. Check if duplicate event IDs are in order meta:
   - Order → **Meta**
   - Look at `_wcrmpgs_webhook_event_ids` field
   - Should be a JSON array like `["evt_123", "evt_456"]` (all unique)
   - If same ID appears twice, duplicate protection failed

2. Check if customer was actually charged twice in Areeba:
   - Log into Areeba → Transaction History
   - Find the order and look at transactions
   - Count how many successful transactions exist
   - If only one exists, you're OK; WooCommerce notes may be misleading

**Resolution:**

- ✅ **If two transactions in Areeba:** Contact Areeba support immediately; they may have double-submitted
- ✅ **If only one transaction in Areeba but duplicate notes in WooCommerce:** Check WooCommerce debug log; may be manual order note added in error
- ✅ **If duplicate protection failed (same event ID twice):** Report to plugin support; this is a rare bug

**Prevention:**

- Always refund via WooCommerce admin (not Areeba dashboard separately)
- Do not manually mark orders as paid in WooCommerce
- Let webhooks handle reconciliation automatically

---

### Issue 4: Order Stuck in "Processing" After Webhook

**Symptoms:**
- Order marked as paid (webhook worked ✅)
- But order status stuck in "Processing" (not moving to "Completed")
- Customer doesn't receive completion email

**This is not a webhook issue**, but a WooCommerce fulfillment issue:

**Resolution:**

1. **Check if order is stuck waiting for shipment:**
   - WooCommerce may require manual shipment tracking before auto-completing
   - Order → **Fulfillment** or **Shipping** section
   - Mark items as shipped

2. **Check WooCommerce settings:**
   - Go to **WooCommerce** → **Settings** → **Orders**
   - Look for "Automatic order completion" or "Change order status after payment"
   - Ensure payment-triggered status change is enabled

3. **Manual fix (if urgent):**
   - Edit order → Status → Change to "Completed"
   - Customer will receive completion email

---

### Issue 5: Areeba Not Sending Webhooks At All

**Symptoms:**
- Payments are being captured in Areeba (confirmed)
- But no webhook notes appear in WooCommerce orders
- Check WooCommerce logs → no webhook events

**Diagnosis:**

1. **Verify webhook is registered in Areeba:**
   - Log in to Areeba Dashboard → Settings → Webhooks
   - Find your endpoint URL
   - Check if status is "Active" or "Enabled"
   - If "Disabled" or "Error", re-register

2. **Check Areeba webhook history/logs:**
   - Areeba may show last 10-100 webhook deliveries
   - Look for your orders
   - If no entries, Areeba is not triggering webhooks

3. **Check Areeba event filter settings:**
   - Some Areeba configurations require explicit event type selection
   - Ensure PAYMENT.CAPTURED and PAYMENT.FAILED are selected

**Resolution:**

- ✅ **If webhook disabled in Areeba:** Re-enable it (Settings → Webhooks → Enable)
- ✅ **If event types not selected:** Check which event types are enabled; add PAYMENT.CAPTURED
- ✅ **If still not working:** Contact Areeba support; they may need to manually configure webhook delivery

---

### Issue 6: Webhook Received But Order Already Marked Paid (from Callback)

**Symptoms:**
- Customer completes checkout via Hosted Checkout form
- Callback handler marks order as paid ✅
- Webhook arrives 5 minutes later
- Order marked paid twice (two notes)

**This is expected behavior (not an error):**

Your store uses two confirmation methods:
1. **Callback** — Customer redirected back from Hosted Checkout (fast, ~1-2 seconds)
2. **Webhook** — Provider sends notification (reliable, may be delayed 1-10 minutes)

**For safety**, both should confirm the payment. If callback marks paid, webhook will see it's already paid and silently ignore the duplicate event.

**Verification:**

- Order should have exactly one "payment complete" event in WooCommerce logs
- Order notes should show both callback and webhook entries (informational)
- Payment should NOT be double-charged (one transaction in Areeba)

---

## Manual Webhook Reconciliation

If a webhook was lost and your order remains unpaid, you can manually retry webhook processing:

**Method 1: Mark Paid Manually (Temporary)**

1. Order edit page → Status → Change to "Processing" or "Completed"
2. Order will be marked paid and customer notified
3. ⚠️ **Verify payment in Areeba first** before doing this

**Method 2: Check Logs for Lost Webhook**

1. WooCommerce → Status → Logs → Filter by `wc-recurring-mpgs-webhook`
2. Search for your order ID
3. If no entries for payment dates, webhook was likely lost
4. Contact Areeba support to resend notification

**Method 3: Future Enhancement (Phase 5.5)**

Planned for future release:
- Manual "Retry Webhook Reconciliation" button on order page
- Admins can force-reprocess previous webhook payloads
- Useful if webhook delivery failed and needs manual retry

---

## Webhook Payload Examples

If you need to debug webhook payloads or integrate with custom systems, here's what Areeba sends:

### PAYMENT.CAPTURED Example

```json
{
  "eventId": "evt_1234567890",
  "eventType": "PAYMENT.CAPTURED",
  "orderId": 12345,
  "transaction": {
    "id": "t987654321",
    "result": "SUCCESS",
    "amount": 99.99,
    "currency": "AED",
    "timestamp": "2026-06-22T14:30:00Z"
  },
  "merchant": {
    "id": "merchant123"
  }
}
```

Your store will:
1. Extract order ID: `12345`
2. Extract event ID: `evt_1234567890`
3. Extract result: `SUCCESS`
4. Extract transaction ID: `t987654321`
5. Mark order paid + add note

### PAYMENT.FAILED Example

```json
{
  "eventId": "evt_2345678901",
  "eventType": "PAYMENT.FAILED",
  "orderId": 12346,
  "transaction": {
    "id": "t987654322",
    "result": "DECLINED",
    "declineReason": "Card expired",
    "amount": 99.99,
    "currency": "AED"
  }
}
```

Your store will:
1. Extract order ID: `12346`
2. Extract result: `DECLINED` (treated as FAILURE)
3. Mark order failed + add note with decline reason

---

## Performance & SLA

**Webhook Processing SLA:**

| Metric | Target |
|--------|--------|
| **Processing time** | < 200ms (order marked paid/failed within 200ms) |
| **Failure rate** | < 0.1% (99.9% successful delivery) |
| **Retry window** | 5 minutes for transient errors |
| **Data retention** | Last 25 webhook events per order (rolling buffer) |

**If webhooks consistently slow:**
- Check WooCommerce server performance (CPU, memory)
- Check if order meta table is bloated (contact hosting provider)
- Enable WooCommerce caching plugin

---

## Frequently Asked Questions

### Q: What happens if I receive both a callback AND a webhook?
**A:** Both mark the order as paid. WooCommerce prevents duplicate payment-complete triggers. You'll see two notes but only one payment transaction.

### Q: Can a customer be double-charged if webhooks retry?
**A:** **No.** Duplicate webhooks are automatically ignored based on event ID. Areeba sends the same event ID on retries, and your store recognizes it as duplicate.

### Q: Do I need to do anything to enable webhooks?
**A:** No. The plugin is ready to receive webhooks. You just need to register your endpoint URL with Areeba (see "Registering Webhooks with Areeba" above).

### Q: What if my store is offline when a webhook arrives?
**A:** Areeba will retry for ~24 hours (check Areeba SLA). Once your store is back online, the webhook will succeed. No payment is lost.

### Q: Can I see webhook history in WooCommerce?
**A:** Yes. Order edit page → **Meta** section shows:
- `_wcrmpgs_webhook_last_payload` — Full webhook payload
- `_wcrmpgs_webhook_received_at` — Timestamp
- `_wcrmpgs_webhook_event_ids` — All event IDs received

### Q: Do webhooks work with subscription renewals?
**A:** **Yes.** When a subscription renews:
1. Plugin sends MIT charge request to Areeba
2. Areeba captures payment
3. Areeba sends PAYMENT.CAPTURED webhook
4. Plugin marks renewal order paid
5. Subscription renewed automatically

### Q: What if my store rejects a webhook (HTTP 500)?
**A:** Areeba will retry for ~24 hours. Check your WooCommerce debug log to see why it failed. Once fixed, the webhook will succeed on retry.

### Q: Is my webhook data encrypted in transit?
**A:** Yes. All webhooks use HTTPS (encrypted). Signature verification is planned for Phase 5.5.

---

## Support & Escalation

If you encounter issues beyond this guide:

1. **Check this troubleshooting guide** — Most issues covered above
2. **Review WooCommerce logs** — Status → Logs → Filter by `wc-recurring-mpgs`
3. **Verify Areeba webhook configuration** — Re-test webhook in Areeba dashboard
4. **Contact support with:**
   - Order ID
   - Expected payment date
   - Last webhook received timestamp (from order meta)
   - Error message from WooCommerce logs
   - Screenshot of Areeba webhook settings

---

## Change History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-06-22 | Initial Phase 5 webhook documentation |

