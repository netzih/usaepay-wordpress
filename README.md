# USAePay Payments for WordPress

One plugin, one USAePay account, three integrations: **Gravity Forms** (done),
GiveWP and WooCommerce (planned). Card details are entered in USAePay's hosted
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

### Conventions shared with the CiviCRM import

Every charge sends `custid` = payer email, `invoice` = `GF<form>-<submission>`
(one-time) or `GF-<entry>` (renewals), `orderid` = an idempotency reference
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
