# Vittas Payments — demo store

A throwaway WordPress + WooCommerce store for exercising the plugin end to end:
a ₦5,000 product checked out through the Vittas gateway, and a donation page
using the `[vittas_pay]` shortcode.

## Requirements

Docker with Compose v2, and a Vittas **test** API key.

## Quick start

```bash
cd example
cp .env.example .env          # add your sk_test_ key
docker compose up -d
docker compose run --rm seed
```

Then open <http://localhost:8080/shop/>.

The plugin folder is bind-mounted, so editing PHP in `../vittas-payments`
applies on the next page load.

## Testing webhooks

Vittas cannot reach `localhost`, so a first pass on localhost settles orders
only through the return-to-site path (the plugin re-verifies server-side when
the customer comes back). To exercise the real webhook:

```bash
docker compose --profile tunnel up -d tunnel
docker compose logs tunnel | grep trycloudflare
```

1. Put the printed `https://….trycloudflare.com` URL into `.env` as `SITE_URL`.
2. `docker compose run --rm seed` — rewrites WordPress's home/siteurl to match.
3. In the Vittas dashboard, register the webhook endpoint
   `https://….trycloudflare.com/wp-json/vittas/v1/webhook` and copy the signing
   secret into `.env` as `VITTAS_TEST_WEBHOOK_SECRET`.
4. `docker compose run --rm seed` once more to write the secret.

Also add the same tunnel URL to **Allowed origins** in your Vittas widget
settings, or the widget refuses to open with "this page is not authorised".

> Free `trycloudflare.com` URLs change every time the tunnel restarts, and both
> the allowed origin and the webhook URL are tied to it. For repeated testing,
> use a named Cloudflare tunnel or ngrok with a reserved domain.

## Walkthrough

**WooCommerce checkout**

1. <http://localhost:8080/shop/> → add *Vittas Demo Hoodie* to the cart.
2. Check out and choose **Bank Transfer (Vittas)**.
3. WooCommerce redirects to the order-pay page, which opens the widget with a
   session created server-side.
4. Transfer to the displayed account in your test environment, or drive the
   session to `successful` from the Vittas dashboard.
5. The order moves to Processing and the note records the reference.

**Shortcode**

<http://localhost:8080/donate/> has both a fixed ₦2,500 button and an
open-amount one. Pay either; the confirmation is server-verified before the
success message appears.

**Blocks checkout** — the seeded store uses the classic shortcode checkout page.
To verify the block integration, edit the Checkout page, replace its content
with the *Checkout* block, and repeat the flow.

## Useful commands

```bash
docker compose logs -f wordpress                      # PHP + Apache
docker compose run --rm --entrypoint wp seed --path=/var/www/html \
  wc shop_order list --user=admin                     # orders
docker compose run --rm --entrypoint wp seed --path=/var/www/html \
  option get vittas_payments_settings --format=json   # plugin settings
```

Plugin logs land in **WooCommerce → Status → Logs**, source `vittas` (debug
logging is on by default in this demo).

## Reset

```bash
docker compose down -v      # deletes the database and WordPress install
```
