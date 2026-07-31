=== Vittas Payments ===
Contributors: vittas
Tags: payments, woocommerce, bank transfer, nigeria, ngn
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept instant bank transfers with Vittas — as a WooCommerce gateway or a pay button on any WordPress page.

== Description ==

Vittas Payments adds instant bank transfer checkout to WordPress.

* **WooCommerce gateway** — works on both classic checkout and the Checkout block.
* **`[vittas_pay]` shortcode and block** — a pay button for donations, deposits or one-off payments on sites without WooCommerce.
* **Signed webhooks** — orders settle from a verified server-to-server callback, not from anything the browser claims.

Your secret key never leaves your server: WordPress creates the payment session server-to-server and hands the browser only a session-scoped client secret.

== Installation ==

1. Upload the `vittas-payments` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New.
2. Activate the plugin.
3. Go to **Vittas Payments** in the admin menu and enter your API keys.
4. Copy the **Allowed origin** and **Webhook URL** shown on that page into your Vittas dashboard.
5. For WooCommerce, enable **Bank Transfer (Vittas)** under WooCommerce → Settings → Payments.

== Frequently Asked Questions ==

= Which currencies are supported? =

NGN. The gateway hides itself when the store currency is anything else.

= Do I need webhooks? =

Yes for production. The plugin also re-verifies server-side when the customer returns, so a local site without a public URL can still be tested end to end — but a customer who closes the tab is only settled by the webhook.

= Are refunds supported? =

Not programmatically. Refund from the Vittas dashboard and record it in WooCommerce.

== Changelog ==

= 0.1.0 =
* Initial release: WooCommerce gateway (classic + blocks), `[vittas_pay]` shortcode and block, signed webhook receiver.
