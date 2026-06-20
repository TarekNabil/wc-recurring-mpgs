# WC Recurring MPGS

WooCommerce payment gateway integration for MPGS hosted checkout.

## Current Features

- Hosted checkout session creation via MPGS API.
- Redirect payment flow to MPGS hosted payment page.
- Secure callback handling using:
  - callback nonce validation,
  - order key validation,
  - payment method validation,
  - server-side order verification (Retrieve Order API).
- Payment finalization with order updates:
  - successful payment completion,
  - failed verification handling,
  - transaction/result metadata persistence.
- Refund support from WooCommerce admin:
  - full refund as VOID request,
  - partial refund as REFUND request,
  - provider response validation and order notes.
- WooCommerce Blocks checkout integration.
- Classic WooCommerce checkout/pay-for-order integration.
- Optional debug logging to WooCommerce logs.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Configuration

Configure the gateway in:

- WooCommerce -> Settings -> Payments -> WC Recurring MPGS

Required settings:

- Service Host (base gateway URL, for example `https://epayment.areeba.com/`)
- Merchant ID
- API Password
- Checkout API Version

Optional settings:

- Merchant Name
- Merchant Address Line 1
- Merchant Address Line 2
- Debug Log

## Implemented Payment Flow

1. Customer places order with `WC Recurring MPGS`.
2. Plugin creates an MPGS checkout session.
3. Customer is redirected to hosted checkout.
4. MPGS returns to plugin callback URL.
5. Plugin verifies the order state from MPGS and updates order status.

## Testing

Current automated test setup includes:

- Unit tests for API client behavior.
- Unit tests for hosted checkout payload construction.
- Integration tests for gateway payment/callback/refund behavior.

Run tests:

- `npm run test:unit`
- `npm run test:integration`
- `npm run test:all`
