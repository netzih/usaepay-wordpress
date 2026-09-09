# usaepay-php

Framework-free PHP client for the USAePay REST API v2, extracted from the
CiviCRM `usaepayjs` extension and shared by the WordPress plugin
(Gravity Forms, GiveWP, WooCommerce).

- `Usaepay\GatewayClient` — cc:sale with a Pay.js `payment_key` or a saved-card
  reference, card verification (authonly + save_card + void), void, refund,
  transaction lookup and paging, credential and public-key checks. Pass a
  `software` string so the USAePay console shows which integration charged.
- `Usaepay\DonorMessage` — payer-facing wording for declines and errors, with
  the raw gateway text kept for logs. Inject a translator with `setTranslator()`.
- `Usaepay\CardDetails` — brand and last four from a response.
- `Usaepay\Country` — ISO alpha-2 ⇄ alpha-3 for `billing_address.country`.

Gateway behaviour these classes encode was verified against the sandbox; see
the CiviCRM extension's README for the details (never send a top-level
`email`, void unsettled / refund settled, list endpoint ignores filters).

```
composer install && vendor/bin/phpunit
```
