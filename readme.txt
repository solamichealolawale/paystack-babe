=== Booking Gateway for Paystack and BA Book Everything ===
Contributors: solamichealolawale
Tags: paystack, booking, payment gateway, ba book everything, hotel
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Paystack payments for bookings made with the BA Book Everything plugin. Card, bank transfer, USSD and mobile money.

== Description ==

**⚠️ Not production ready — do not install on a live site.** An independent review found defects that make this unsafe against real money, the most serious being that the settings cannot be saved through the admin UI at all. Fixes are in progress. Do not use this release to take payments.

BA Book Everything is a capable booking plugin, but out of the box it only offers "Pay later" and "Pay by Coupon". Online payments come from paid, vendor-built add-ons, and there is no Paystack one — which leaves hotels, guesthouses and rental businesses across Nigeria, Ghana, Kenya and South Africa unable to take card payments for bookings.

This plugin fills that gap. It is free and GPLv3.

Guests pay on Paystack's own hosted checkout, so no card details ever touch your site.

= Features =

* Card, bank transfer, USSD and mobile money via Paystack's hosted checkout
* Separate test and live API keys, with a test-mode switch
* Server-side verification of every transaction before a booking is marked paid
* Signed webhooks (HMAC-SHA512) so payments still complete if a guest closes their browser
* Idempotent fulfilment — the browser callback and the webhook cannot double-complete a booking
* Amount and currency re-checked against the stored booking total before value is given
* Currencies: NGN, GHS, ZAR, KES, USD, XOF

= How it works =

1. The guest picks a room and dates and reaches the BA Book Everything checkout.
2. They choose Paystack and are redirected to Paystack's hosted checkout.
3. After paying, Paystack returns them to your site.
4. Your site verifies the transaction directly with Paystack before marking the booking paid.
5. A webhook from Paystack acts as a backstop in case the guest never makes it back.

= Requirements =

* The [BA Book Everything](https://wordpress.org/plugins/ba-book-everything/) plugin, active
* A [Paystack](https://paystack.com) account

== External services ==

This plugin sends data to Paystack in order to take payments. This is the plugin's sole purpose and it only happens once you have entered your own Paystack API keys.

**Service:** Paystack (https://paystack.com)

**When it is contacted:**

* When a guest chooses Paystack and submits the booking checkout, the plugin calls `https://api.paystack.co/transaction/initialize` to create the payment.
* When the guest returns, or when Paystack sends a webhook, the plugin calls `https://api.paystack.co/transaction/verify/{reference}` to confirm the payment before marking the booking paid.

**What is sent:** the guest's email address, the amount and currency, a unique payment reference, the return URL, and the booking's ID as metadata. No card details pass through your site — those are entered on Paystack's own checkout page.

Paystack terms of service: https://paystack.com/terms
Paystack privacy policy: https://paystack.com/privacy

== Installation ==

1. Install and activate BA Book Everything first.
2. Upload this plugin to `/wp-content/plugins/` and activate it.
3. Go to **BA Settings → Payments**, enable Paystack, and paste your API keys from your [Paystack dashboard](https://dashboard.paystack.com/#/settings/developers).
4. Copy the **Webhook URL** shown on that settings screen into Paystack → Settings → API Keys & Webhooks.
5. Leave **Test mode** on and make a test booking before going live.

== Frequently Asked Questions ==

= Do I need the webhook? =

Yes, for reliability. The browser redirect marks most bookings paid on its own. The webhook covers the case where a guest pays and then closes the tab, loses signal, or their battery dies before returning to your site — without it, that payment is taken but the booking stays unpaid.

= Which currencies are supported? =

NGN, GHS, ZAR, KES, USD and XOF. Note that a currency also has to be enabled on your own Paystack account. Nigerian accounts get NGN by default; USD requires a separate request to Paystack and a domiciliary account for payouts.

= Do foreign guests need me to price in dollars? =

No. Paystack converts on the customer's side, so a visitor abroad can pay your Naira prices with their own card.

= Are refunds supported? =

Not automatically. BA Book Everything declares a refund filter but never applies it, so there is nothing for this plugin to hook. Refund from your Paystack dashboard and update the booking manually.

= Does this work with WooCommerce? =

This plugin is for BA Book Everything bookings only. If you also sell products, Paystack's own official WooCommerce plugin handles those separately.

== Changelog ==

= 0.4.0 =
* Renamed to comply with the WordPress.org plugin naming guidelines.
* Added readme.txt and documented the external service.

= 0.3.0 =
* Added the Paystack mark to the checkout payment tab.
* Padded the payment tab strip and description panel.

= 0.2.0 =
* Fixed customer email resolution. The previous release called a BA Book Everything method that does not exist in 1.8.x; the guard meant it silently fell back to the site admin address, so receipts went to the operator rather than the guest.
* Verified end to end in test mode against BA Book Everything 1.8.16.

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.2.0 =
Fixes payment receipts being addressed to the site administrator instead of the guest. Recommended for all users.

== Disclaimer ==

This plugin is not affiliated with, endorsed by, or sponsored by Paystack or Booking Algorithms. "Paystack" and "BA Book Everything" are the property of their respective owners; both names are used here only to describe what this plugin connects.
