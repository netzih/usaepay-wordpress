# USAePay Payments for WordPress

One plugin, one USAePay account, three integrations: **Gravity Forms**,
**GiveWP** and **WooCommerce**. Card details are entered in USAePay's hosted
Pay.js fields; this site only ever handles single-use payment keys and
saved-card references.

Built on [`chabadrichmond/usaepay-php`](lib/usaepay-php), the framework-free
client extracted from the CiviCRM `usaepayjs` extension, so gateway behaviour
(void unsettled / refund settled, no top-level `email`, reconciliation by
`orderid`) is shared and unit tested once.

## Installation

Download `usaepay-payments-<version>.zip` from the
[latest release](https://github.com/netzih/usaepay-wordpress/releases/latest)
(the repository is private, so sign in to GitHub first). In WordPress go to
**Plugins > Add New Plugin > Upload Plugin**, choose the zip, press **Install
Now**, then **Activate**. The zip carries everything the plugin runs on,
including the bundled USAePay client library; no Composer or shell access is
needed on the site. To upgrade, upload the newer zip the same way and confirm
**Replace current with uploaded** when WordPress asks.

Requirements: WordPress 6.4+, PHP 8.1+ with curl and json, and at least one of
Gravity Forms 2.9+, GiveWP 4 or WooCommerce 8 (WooCommerce Subscriptions for
recurring WooCommerce payments).

From a clone instead of a release: run `composer install` in the plugin
directory, or `bin/build-zip.sh` to make the same zip a release ships.

## Setup

1. Install and activate **USAePay Payments** (see above).
3. **Settings > USAePay**: choose Sandbox or Live, enter the API key (source
   key), its PIN and the Pay.js public key for that mode, save, then press
   **Check credentials**. The check lists one transaction and mints an unused
   payment key; nothing is charged. Server-side work (charges, refunds,
   renewals) needs only the key and PIN; checkouts also need the public key
   and are offered only when all three are present.
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
- Renewals cannot double-charge: the `orderid` of each attempt is written to
  the entry before the charge is sent and cleared last, after the outcome
  is recorded. A run that finds one looks it up at USAePay first (bounded by the
  time it was sent, so a miss is conclusive) and records the earlier charge
  instead of sending another. When USAePay cannot be asked, nothing is charged
  and the entry is checked again next hour. Workers take a database lock, so
  two cron runs never charge the same entry.
- **Cancel Subscription** (entry detail) stops further charges; the card
  reference stays on the entry for reference.
- **Refund via USAePay** (entry detail, one-time payments): unsettled sales
  are voided in full, settled ones refunded in full or in part. The entry
  becomes **Refunded** and a refund transaction is recorded. A refund whose
  answer was lost is found at USAePay before it could be sent again.
- On a multi-page form the **USAePay Card** field must sit on the last page:
  the single-use key is minted when the form is submitted, and the editor
  warns when the field is anywhere else.
- Notification events: Payment Completed/Failed/Refunded, Subscription
  Created/Payment Added/Payment Failed/Cancelled/Expired.
- Apple Pay: enable it in Settings > USAePay; the button appears on forms
  whose active USAePay feeds are all one-time, in browsers that support it,
  once the domain is registered with USAePay and Apple's association file is
  served from `/.well-known/`. The amount shown on the sheet is the form total
  as Gravity Forms 3 computes it (`gform.products.getPaymentAmount()`, current
  on every keystroke); on GF 2.9 the Total field is read, or the product
  fields are added up when there is none. Pay.js gives its Apple Pay button
  one fixed element id, so one form per page can offer Apple Pay.

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
  that is voided at once. Free trials verify the card the same way. Renewal
  orders count attempts before each charge and reconcile earlier attempts by
  `orderid` first; an order is left pending, not re-charged, while USAePay
  cannot confirm what happened.
- Apple Pay is offered on plain carts only: keys are single-use and return no
  saved card, so the button is hidden for subscription carts, Pay for Order on
  subscription orders, payment-method changes and Add Payment Method.

### Conventions shared with the CiviCRM import

Every charge sends `custid` = payer email, `invoice` = `GF<form>-<submission>`
(one-time) or `GF-<entry>` (renewals), `GIVE-<donation>` / `GIVE-S<subscription>` for GiveWP, `WC-<order number>` for WooCommerce, `orderid` = an idempotency reference
(`gf-<entry>-<YYYY-MM-DD>-<attempt>` for renewals) and the billing address for
AVS. No top-level `email` is sent, so USAePay does not email its own receipt.

Every `orderid` starts with a six-character prefix derived from the site URL
(filter `usaepay_payments_orderid_prefix`), so two sites on one USAePay account
never reconcile each other's charges. USAePay ignores an `orderid` sent with a
refund and gives the refund the sale's `orderid` instead, so refunds are
reconciled by the sale's `orderid`, the transaction type and the amount.

### Charge at most once

Every charge and refund is guarded the same way: a marker (`orderid`, time and
amount) is stored on the record before the request goes out, and a later
attempt for the same record first looks that marker up at USAePay, bounded by
the time it was sent so a miss is conclusive. Found: recorded without charging
again. Missing: charged. USAePay unreachable or the listing window exhausted:
nothing is sent and the admin or payer is told to wait or get in touch. Stores:
WooCommerce order meta (`_usaepay_charge_sent`, `_usaepay_refund_sent`,
`_usaepay_renewal_pending`), GiveWP donation meta (`_usaepay_charge_sent`,
`_usaepay_refund_sent`), Gravity Forms entry meta (`usaepay_reconcile_*`,
`usaepay_refund_sent`) and, for a submission that has no entry yet, a row in
`wp_options` (`usaepay_marker_*`, written with plain SQL so no object cache can
drop it; purged after seven days by the hourly Gravity Forms cron).

Reading the marker, storing it and sending the request happen under a lock on
the `orderid` (a row inserted into `wp_options`, so the insert itself is the
test), and the stored marker is read back before anything is sent. Two requests
for the same order at the same moment therefore cannot both charge: the second
is told the payment is already being processed. Cron workers hold the same kind
of lock per subscription and per run.

A refund inherits the sale's `orderid`, so two refunds of the same amount look
alike. Before a refund is sent, the refunds the sale already has are listed and
their keys stored with the marker; a later lookup counts only a refund that was
not there before. A listed row that carries the `orderid` but no type or amount
is treated as inconclusive rather than matched, and a voided transaction never
counts.

Renewal success is recorded so that running it twice for the same transaction
changes nothing. Gravity Forms writes one record per approved installment
(`usaepay_installment_applied`: transaction key, new count, next date) before
touching the metas the schedule reads; a run that dies half-way derives them
again from that record. GiveWP records the renewal donation first and, when a
run died before the renewal date moved, moves it on the next run instead of
charging the period again (a donation created after the current renewal date is
this period's; one created before it belongs to an earlier period, so the
marker is a leftover and the period is charged).

Settings > USAePay lists every request in that state ("Unresolved requests":
WooCommerce orders and renewal orders, Gravity Forms entries and submissions
without an entry, GiveWP donations and subscriptions) with a "Check at USAePay"
action that reports whether the request went through, was declined or never
arrived, and what to do next; "Run renewal workers now" runs the Gravity Forms
and GiveWP renewal workers immediately. The list is read-only: each module
resolves its own marker on its next attempt.

A signup whose answer was lost is recovered from the listing without the
saved-card key (USAePay's listing and transaction detail carry no
`savedcard`, verified against the sandbox), so a subscription signup recovered
that way is voided and the payer is asked for the card again.

### Entry meta written by the add-on

`usaepay_mode`, `usaepay_order_id`, `usaepay_transaction_key`, `usaepay_refnum`,
`usaepay_card_brand`, `usaepay_card_last4`, `usaepay_payer`; for subscriptions
also `usaepay_card_reference`, `usaepay_interval_length/unit`,
`usaepay_recurring_times`, `usaepay_payments_made`, `usaepay_failed_attempts`,
`usaepay_schedule_start`, `usaepay_installment_index`, `usaepay_scheduled_date`,
`usaepay_next_charge`, `usaepay_last_transaction_key`,
`usaepay_installment_applied`, and while a charge is in flight
`usaepay_reconcile_order_id` / `usaepay_reconcile_sent_at`. A subscription created in
sandbox mode is skipped by the renewal worker while the plugin is in live mode
(and vice versa).

## Development

```
composer install
vendor/bin/phpunit          # pure classes (schedule math, Reconcile)
composer run build          # vendor/ with the library copied, no dev deps
bin/build-zip.sh            # build/usaepay-payments-<version>.zip for a release
```

Releasing: bump `Version:` in `usaepay-payments.php` and `Plugin::VERSION`
together (the second is the `?ver=` of the enqueued assets outside WP_DEBUG),
commit, tag `v<version>`, run `bin/build-zip.sh` and attach the zip to the
GitHub release for that tag.

Browser tests live outside the repo (`~/.config/usaepayjs/browser/wp-*.mjs`,
Playwright) and run against the local site described in the project notes.

## To do

- Consolidate the charge/exception ladder. The sequence "get a client, run
  the request, catch inconclusive / ambiguous / gateway error / invalid
  argument / busy / not sent, each with its own message and log line" is
  repeated in the Gravity Forms add-on, the GiveWP gateway, the WooCommerce
  gateway and both renewal workers. Move it into one shared method that takes
  the gateway call plus log and note callbacks and returns a typed outcome;
  each module maps the outcome to its own return shape. Pure movement, own
  commit, no behaviour change. Deferred on 2026-09-10.
- Zero- and three-decimal currencies. Amounts are always sent with two
  decimals, which is right for USD and wrong for JPY, KRW or KWD. Left as a
  documented USD-only limit by decision on 2026-09-10.
- Apple Pay in the WooCommerce checkout has not been exercised in a browser.
