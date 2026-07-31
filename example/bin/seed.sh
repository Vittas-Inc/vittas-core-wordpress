#!/bin/sh
# Provisions the demo store. Idempotent — re-run it after changing .env.
set -eu

WP="wp --path=/var/www/html"

log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }

# wp-cli writes a cache directory under $HOME; the numeric uid this container
# runs as has no home of its own.
export HOME=/tmp

# The wordpress service copies core into the shared volume and then writes
# wp-config.php last, so that file is the signal that the volume is ready.
log "Waiting for WordPress"
tries=0
until [ -f /var/www/html/wp-config.php ]; do
	tries=$((tries + 1))
	if [ "$tries" -gt 90 ]; then
		echo "wp-config.php never appeared — is the wordpress service running? (docker compose logs wordpress)" >&2
		exit 1
	fi
	sleep 2
done

log "Installing WordPress"
if $WP core is-installed 2>/dev/null; then
	echo "already installed"
else
	$WP core install \
		--url="$SITE_URL" \
		--title="Vittas Demo Store" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASSWORD" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
fi

# The tunnel gives the site a different public URL than localhost. Keeping
# home/siteurl in sync with SITE_URL matters because both the widget's allowed
# origin check and the webhook URL are derived from it.
$WP option update home "$SITE_URL"
$WP option update siteurl "$SITE_URL"
$WP rewrite structure '/%postname%/' --hard >/dev/null

# The official WordPress image only unpacks core files into an *empty* volume,
# so bumping the image tag leaves an existing install on its original version.
# WooCommerce enforces a hard WordPress floor and refuses to install below it,
# which surfaces as a confusing "plugin could not be found". Upgrade in place
# rather than making people delete the volume.
INSTALLED=$($WP core version 2>/dev/null || echo "0")
log "WordPress $INSTALLED — checking for core updates"
$WP core update
$WP core update-db 2>/dev/null || true

log "Installing WooCommerce"
if $WP plugin is-installed woocommerce; then
	$WP plugin activate woocommerce >/dev/null
else
	$WP plugin install woocommerce --activate
fi

log "Activating Vittas Payments"
$WP plugin activate vittas-payments

log "Configuring the store"
$WP option update woocommerce_currency NGN
$WP option update woocommerce_default_country 'NG:LA'
$WP option update woocommerce_store_country 'NG:LA'
$WP option update woocommerce_enable_guest_checkout yes
$WP option update woocommerce_calc_taxes no
# Skip the setup wizard so the demo lands on a usable store. These are cosmetic
# and their option names drift between WooCommerce releases, so a rejected write
# must not abort the seed — the store works either way.
$WP option update woocommerce_onboarding_profile '{"skipped":true,"completed":true}' --format=json || true
$WP option update woocommerce_task_list_hidden yes || true
$WP option update woocommerce_admin_notices '[]' --format=json || true

# WooCommerce creates its pages on activation, but only once the install
# routine has run — force it so /checkout exists on a fresh volume.
$WP wc tool run install_pages --user="$ADMIN_USER" >/dev/null 2>&1 || true

log "Enabling the Vittas gateway"
$WP option update woocommerce_vittas_settings '{"enabled":"yes","title":"Bank Transfer (Vittas)","description":"Pay by instant bank transfer. You will get account details to transfer to, and your order is confirmed automatically."}' --format=json

log "Writing Vittas credentials"
if [ -z "${VITTAS_TEST_SECRET_KEY:-}" ]; then
	echo "! VITTAS_TEST_SECRET_KEY is empty — set it in example/.env and re-run this step."
	echo "  The store will load, but no payment session can be created."
fi

SETTINGS=$(cat <<JSON
{
  "mode": "test",
  "test_secret_key": "${VITTAS_TEST_SECRET_KEY:-}",
  "test_webhook_secret": "${VITTAS_TEST_WEBHOOK_SECRET:-}",
  "live_secret_key": "",
  "live_webhook_secret": "",
  "api_base": "${VITTAS_API_BASE:-}",
  "widget_src": "${VITTAS_WIDGET_SRC:-}",
  "debug": "yes"
}
JSON
)
$WP option update vittas_payments_settings "$SETTINGS" --format=json

log "Creating a demo product"
if [ -z "$($WP post list --post_type=product --name=vittas-demo-hoodie --field=ID)" ]; then
	$WP wc product create \
		--user="$ADMIN_USER" \
		--name="Vittas Demo Hoodie" \
		--slug="vittas-demo-hoodie" \
		--sku="VITTAS-DEMO-1" \
		--type=simple \
		--regular_price=5000 \
		--virtual=true \
		--description="A demo product priced at ₦5,000 for exercising the Vittas checkout." \
		--status=publish
else
	echo "already exists"
fi

log "Creating the shortcode demo page"
if [ -z "$($WP post list --post_type=page --name=donate --field=ID)" ]; then
	$WP post create \
		--post_type=page \
		--post_title="Support our work" \
		--post_name="donate" \
		--post_status=publish \
		--post_content='<h2>Fixed amount</h2>
[vittas_pay amount="2500" label="Donate ₦2,500" reference="DONATION" description="A one-off donation of ₦2,500."]

<h2>Choose your own amount</h2>
[vittas_pay amount="0" min="500" max="500000" label="Donate" reference="DONATION" description="Enter any amount between ₦500 and ₦500,000."]'
else
	echo "already exists"
fi

log "Done"
cat <<EOF

  Store:      $SITE_URL/shop/
  Donate:     $SITE_URL/donate/
  Admin:      $SITE_URL/wp-admin/  ($ADMIN_USER / $ADMIN_PASSWORD)
  Settings:   $SITE_URL/wp-admin/admin.php?page=vittas-payments

  In the Vittas dashboard, register:
    Allowed origin  $SITE_URL
    Webhook URL     $SITE_URL/wp-json/vittas/v1/webhook

EOF
