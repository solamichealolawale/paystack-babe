# Booking Gateway for Paystack

Adds [Paystack](https://paystack.com) as a payment method to the [BA Book Everything](https://wordpress.org/plugins/ba-book-everything/) WordPress booking plugin.

BA Book Everything ships with "Pay later" and "Pay by Coupon" only. Online payments come from paid, vendor-built add-ons — and there is no Paystack one, which leaves hotels and rentals across Nigeria, Ghana, Kenya and South Africa unable to take card payments for bookings.

This plugin fills that gap. It is free and GPLv3.

> **Status: v0.4.0 — both payment paths verified in test mode.**
>
> *Redirect path:* a full booking completed through the real UI against BA Book Everything 1.8.16 — room → dates → extras → checkout → Paystack hosted checkout → callback → order marked paid, with the correct amount and currency, and the extras carried onto the order.
>
> *Webhook path:* verified by initialising a transaction whose `callback_url` pointed elsewhere, paying it for real on Paystack, and leaving the webhook as the only route that could complete the order. Forged and unsigned deliveries were rejected with 401; the signed delivery completed the order; two replays did not double-credit it.
>
> Not yet exercised: **live mode** (test keys only), currencies other than **NGN**, and BA versions other than **1.8.16**. Run in Paystack test mode first and read [Known limitations](#known-limitations) before going live.

## Why this exists

Paystack maintains an excellent, actively developed [WooCommerce plugin](https://github.com/PaystackOSS/woo-paystack). It does not help BA Book Everything users, because BA Book Everything has its own cart, checkout, orders table and payment layer, and never touches WooCommerce.

The only existing routes to Paystack for BABE are a paid third-party add-on with no published changelog, or [Knit Pay Pro](https://wordpress.org/plugins/knit-pay-pro/), which routes payments through a third-party RapidAPI service that charges per transaction. Neither felt right for hotel revenue, hence this.

## Features

- Card, bank transfer, USSD and mobile money via Paystack's hosted checkout
- Test and live mode with separate key pairs
- **Server-side verification** of every transaction before a booking is marked paid
- **Signed webhooks** (HMAC-SHA512) so payments still complete when a guest closes the tab
- Idempotent fulfilment — the callback and the webhook cannot double-complete a booking
- Amount and currency re-checked against the stored booking total
- Supports deposits / partial payments where BABE is configured for them
- Currencies: NGN, GHS, ZAR, KES, USD, XOF

## Requirements

| | |
|---|---|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| BA Book Everything | 1.8.x (see note below) |
| Paystack account | [paystack.com](https://paystack.com) |

**On BABE versions.** This plugin binds to hooks that exist in both 1.7.x and 1.8.x. It deliberately avoids `babe_payment_methods_init`, `babe_order_to_pay_by_` and `babe_checkout_payment_fields_`, which were deprecated in 1.7.24 and **deleted** in 1.8.26 — the reason the older free PayPal add-on silently stopped working on current installs.

Because BABE couples via `do_action()`/`apply_filters()` with no interface, a removed hook produces no error — the gateway would simply stop appearing. The plugin therefore asserts on the specific API symbols it needs at load time and shows an admin notice instead of failing silently.

## Installation

1. Download the latest release zip (or clone into `wp-content/plugins/`).
2. Activate **Paystack for BA Book Everything**.
3. Go to **BA Settings → Payments**, enable Paystack, and paste your keys from the [Paystack dashboard](https://dashboard.paystack.com/#/settings/developers).
4. Copy the **Webhook URL** shown on that settings screen into Paystack → Settings → API Keys & Webhooks.
5. Leave **Test mode** on and make a test booking before going live.

### Paystack test cards

In test mode Paystack's checkout offers Success / Bank Authentication / Declined buttons, so no card number is needed. If you want one anyway: `4084 0840 8408 4081`, any future expiry, CVV `408`, PIN `0000`, OTP `123456`.

## How it works

```
Guest picks dates → BABE checkout → chooses Paystack
   │
   ├─ babe_order_start_paying_with_paystack
   │     POST /transaction/initialize   (amount × 100, unique reference)
   │     redirect → checkout.paystack.com
   │
   ├─ Guest pays
   │
   ├─ Browser callback ─┐
   │                     ├─→ GET /transaction/verify/{reference}
   └─ Webhook ───────────┘      check data.status === "success"
         (charge.success)       check amount + currency
                                idempotency guard
                                   │
                                   └─ BABE_Payments::do_complete_order()
```

Both paths converge on one idempotent routine, so whichever arrives first wins and the second is a no-op.

## Security notes

Payment code fails badly when it fails quietly, so the non-obvious decisions are spelled out here.

- **`data.status`, not `status`.** Paystack responses carry two `status` fields: the envelope reports whether the API call worked, `data.status` reports whether money moved. Only `success` in the latter is honoured.
- **Verify always, trust the redirect never.** Per Paystack's docs: *"Just because the callback_url was visited doesn't prove that transaction was successful."* Every fulfilment re-verifies against the API, including the webhook, whose payload is signature-proven but still only a snapshot.
- **HMAC over the raw body.** The signature is computed on the exact bytes received via `php://input`, not a re-encoded array, and compared with `hash_equals()`.
- **Amount and currency re-checked** against the figure this plugin stored when initialising — never against anything the browser supplied.
- **Fresh reference per attempt.** Re-using a reference is rejected by Paystack as a duplicate, which would strand a guest retrying after an abandoned attempt.
- **Failure never sets `payment_processing` or `canceled`.** Both permanently block further payment attempts in BABE. Failed payments leave the order alone so the guest can retry.

## Known limitations

- **Refunds are manual.** BABE's `babe_refund_{method}` filter is declared by other add-ons but never applied by core — it is dead code. Refund through the Paystack dashboard and mark the booking manually.
- **Deposits/partial payments are only lightly exercised.** The amount resolution prefers `$args['amount']`, then `get_order_prepaid_amount()`, then the order total. Full payment is verified; deposit flows are not.
- **Webhooks need a host that accepts server-to-server POSTs.** The handler itself is verified, but delivery is a property of your hosting. Some free hosts (InfinityFree and relatives) serve a bot challenge to non-browser clients, which silently blocks Paystack's webhook — the redirect flow still completes bookings there, but a guest who closes the tab mid-payment will not be marked paid. Confirm delivery in Paystack's dashboard before relying on it.
- **Live mode has never been run.** Everything is verified with `sk_test_` keys. Do a small real transaction before opening to guests.
- **USD needs enabling on your Paystack account.** Nigerian merchants get NGN by default; USD requires an international payments request and a Zenith Bank USD domiciliary account for payouts. Foreign guests can pay NGN prices without any of that — their bank converts.

## Contributing

Issues and pull requests welcome, particularly from anyone running this against a live BA Book Everything install. If you hit a hook that has changed, please include your BABE version.

## Credits

Built by reading two GPL reference implementations:

- [Knit Pay](https://wordpress.org/plugins/knit-pay/)'s `BaBookEverything` extension, whose comments document several BABE order-status traps that are otherwise learned the hard way.
- [HBL Payment Gateway for BA Book Everything](https://github.com/rohanstha02/HBL-Payment-Gateway-Plugin-for-BA-Book-Everything), a standalone single-gateway plugin for BABE.

Neither is copied; both were invaluable for mapping an undocumented API.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

Not affiliated with or endorsed by Paystack or Booking Algorithms.

## Verified against

| | |
|---|---|
| BA Book Everything | 1.8.16 (payment API identical in 1.8.26) |
| WordPress | 6.9.4 |
| PHP | 8.5.6 |
| MySQL | 9.7.1 |
| Currency | NGN |

Verified flow: room page → dates → services → checkout (Paystack tab selected) →
Paystack hosted checkout (test Success) → callback → server-side verify →
`do_complete_order()` → order marked paid with Amount Due ₦0.00, and BA's
customer + admin emails delivered.
