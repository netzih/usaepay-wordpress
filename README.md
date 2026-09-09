# USAePay Payments for WordPress

One plugin, one USAePay account, three integrations: **Gravity Forms**,
**GiveWP** and **WooCommerce**. Card details are entered in USAePay's hosted
Pay.js fields; this site only ever handles single-use payment keys and
saved-card references.

Built on [`chabadrichmond/usaepay-php`](../usaepay-php), the framework-free
client extracted from the CiviCRM `usaepayjs` extension, so gateway behaviour
(void unsettled / refund settled, no top-level `email`, reconciliation by
`orderid`) is shared and unit tested once.

## Setup

1. `composer install` in the plugin directory (or use a release build).
2. Activate **USAePay Payments**.
3. **Settings > USAePay**: choose Sandbox or Live, enter the API key (source
   key), its PIN and the Pay.js public key for that mode, save, then press
   **Check credentials**. The check lists one transaction and mints an unused
   payment key; nothing is charged.
4. On the live source key allow **Sale, Auth Only, Void and Credit (refund)**.
   Auth Only + Void are used to verify a card for a free trial; Credit for refunds.

## Gravity Forms

- Add the **USAePay Card** field (Pricing Fields) to a form with a product or
  total field. Then create a **USAePay** feed under Form Settings.
- **Products and Services** feeds charge the form total (or a chosen product)
  when the form is submitted. Declines are shown on the card field in payer
  wording; the gateway's own text goes to the add-on log.
- **Subscription** feeds charge the first installment immediately with
  `save_card` (a free trial verifies the card with a $1 authorization that is
  voided at once) and store a card reference on the entry. Renewals are
  charged by this site from GF's hourly cron (`gravityformsusaepay_cron`);
  nothing is scheduled in the USAePay console. Installment dates are anchored
  to the signup day (the 31st stays the 31st, clamped in short months). A
  declined installment is retried every 3 days, three attempts in all, then
  the subscription is cancelled with a note. "Recurring Times" expires the
  entry after that many payments (status **Expired**).
- **Cancel Subscription** (entry detail) stops further charges; the card
  reference stays on the entry for reference.
- **Refund via USAePay** (entry detail, one-time payments): unsettled sales
  are voided in full, settled ones refunded in full or in part. The entry
  becomes **Refunded** and a refund transaction is recorded.
- Notification events: Payment Completed/Failed/Refunded, Subscription
  Created/Payment Added/Payment Failed/Cancelled/Expired.
- Apple Pay: enable it in Settings > USAePay; the button appears on forms
  whose active USAePay feeds are all one-time, in browsers that support it,
  once the domain is registered with USAePay and Apple's association file is
  served from `/.well-known/`.

## GiveWP

- Works with the visual donation forms (v3). Enable **USAePay** under
  Donations > Settings > Payment Gateways > Gateways (v3 list); the **USAePay**
  section there only points at Settings > USAePay, where the credentials live.
- **GiveWP Test Mode selects the sandbox credentials**; live mode the live ones.
  Subscriptions remember the mode they were created in and are skipped by the
  renewal worker while the site is in the other mode.
- One-time donations charge the Pay.js key in `createPayment()`; the donation
  gets a note with the USAePay reference, auth code, AVS and CVV results.
  Declines throw a `PaymentGatewayException` with payer wording (shown on the
  form) and leave a note with the gateway text on the pending donation.
- Recurring donations need no add-on for the gateway itself: the form builder
  unlocks recurring because the gateway reports subscription support. The
  first installment is charged with `save_card`; the saved-card reference is
  stored as the subscription's gateway subscription id. An hourly WP-Cron event
  (`usaepay_givewp_renewals`) charges subscriptions whose renewal date has
  passed and records each with `Subscription::createRenewal()`, which also
  advances the renewal date. Declines: status **Failing**, retry in 3 days, three
  attempts, then **Cancelled**. Installment limits complete the subscription.
- Refunds: the donation page's Refund action voids unsettled sales and refunds
  settled ones (full amount).
- Cancelling a subscription in GiveWP stops further charges; nothing is
  scheduled at USAePay so there is nothing else to cancel.

## WooCommerce

- Enable **USAePay** under WooCommerce > Settings > Payments. Title, description
  and the saved-cards switch live there; credentials and sandbox/live under
  Settings > USAePay. Compatible with HPOS and the block checkout.
- Block checkout, classic checkout, Pay for Order and My Account > Add Payment
  Method all share one server path: the block checkout copies its payment data
  into `$_POST`, so `process_payment()` reads `usaepay_payment_key` and
  `wc-usaepay-payment-token` the same way everywhere.
- Saved cards are `WC_Payment_Token_CC` rows holding the USAePay saved-card
  reference (brand, last four and expiry for display). Logged-in customers can
  tick "save card"; a cart containing a subscription always saves it.
- Orders get a note with the USAePay reference, auth code, AVS and CVV results,
  plus meta `_usaepay_transaction_key`, `_usaepay_refnum`, `_usaepay_mode`,
  `_usaepay_card_reference`, `_usaepay_card_summary`. Declines add a note with
  the gateway text and show payer wording at checkout.
- Refunds from the order screen ("Refund via USAePay"): unsettled sales are
  voided in full, settled ones refunded in full or in part. Partial refunds of
  an unsettled sale are refused with an explanation.
- WooCommerce Subscriptions (add-on, untested here): renewals are charged from
  `woocommerce_scheduled_subscription_payment_usaepay` against the card
  reference copied onto the subscription; card changes by customer or admin go
  through the same checkout fields and verify the card with a $1 authorization
  that is voided at once. Free trials verify the card the same way.

### Conventions shared with the CiviCRM import

Every charge sends `custid` = payer email, `invoice` = `GF<form>-<submission>`
(one-time) or `GF-<entry>` (renewals), `GIVE-<donation>` / `GIVE-S<subscription>` for GiveWP, `WC-<order number>` for WooCommerce, `orderid` = an idempotency reference
(`gf-<entry>-<YYYY-MM-DD>-<attempt>` for renewals) and the billing address for
AVS. No top-level `email` is sent, so USAePay does not email its own receipt.

### Entry meta written by the add-on

`usaepay_mode`, `usaepay_order_id`, `usaepay_transaction_key`, `usaepay_refnum`,
`usaepay_card_brand`, `usaepay_card_last4`, `usaepay_payer`; for subscriptions
also `usaepay_card_reference`, `usaepay_interval_length/unit`,
`usaepay_recurring_times`, `usaepay_payments_made`, `usaepay_failed_attempts`,
`usaepay_schedule_start`, `usaepay_installment_index`, `usaepay_scheduled_date`,
`usaepay_next_charge`, `usaepay_last_transaction_key`. A subscription created in
sandbox mode is skipped by the renewal worker while the plugin is in live mode
(and vice versa).

## Development

```
composer install
vendor/bin/phpunit          # pure classes (schedule math)
composer run build          # vendor/ with the library copied, no dev deps
```

Browser tests live outside the repo (`~/.config/usaepayjs/browser/wp-*.mjs`,
Playwright) and run against the local site described in the project notes.
