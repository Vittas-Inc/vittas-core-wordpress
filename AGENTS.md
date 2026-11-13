# AGENTS.md: vittas-core-wordpress

The Vittas Payments WordPress/WooCommerce plugin (`vittas-payments/`) that
merchants install on their own sites, plus a docker-compose demo store
(`example/`). This is a public repository.

## Code Review Rules

### Payment integrity

- An order is marked paid only from the signed webhook (HMAC checked with
  `hash_equals` in `class-vittas-webhook.php`) or a server-side verify call to
  the Vittas API. Never from a browser redirect, a JS callback or a request
  parameter. Treat any path that completes an order on client-supplied data
  as P0.
- The charge amount and currency come from the WooCommerce order (or the
  shortcode's signed config) on the server, never from POST/GET data.
- The secret key (`sk_…`) stays in PHP. The browser only receives the
  session client secret.

### WordPress security

- Every AJAX/REST handler checks a nonce (`check_ajax_referer` or
  `wp_verify_nonce`). Every admin or settings action checks
  `current_user_can()`. REST routes declare a real `permission_callback`;
  `__return_true` is allowed only on the signed webhook route.
- Sanitize input (`sanitize_text_field`, `absint`, …). Escape output
  (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`). Use `$wpdb->prepare()` for
  every query with variables.
- Don't log secret keys, webhook secrets or full payloads through
  `class-vittas-logger.php`.

### Compatibility and releases

- Supports PHP 7.4+ and WordPress 6.0+ (see `readme.txt`). Flag syntax or
  functions newer than that.
- A release bumps the version in all three places together: the
  `vittas-payments.php` header, `VITTAS_PAYMENTS_VERSION`, and the
  `readme.txt` "Stable tag". The self-updater relies on them matching.
