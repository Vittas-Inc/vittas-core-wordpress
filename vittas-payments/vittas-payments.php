<?php
/**
 * Plugin Name:       Vittas Payments
 * Plugin URI:        https://github.com/Vittas-Inc/vittas-core-wordpress
 * Description:       Accept bank transfer payments with the Vittas payment widget — as a WooCommerce gateway, or as a [vittas_pay] button on any WordPress page.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Vittas
 * Author URI:        https://vittasinternational.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       vittas-payments
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

define( 'VITTAS_PAYMENTS_VERSION', '0.1.0' );
define( 'VITTAS_PAYMENTS_FILE', __FILE__ );
define( 'VITTAS_PAYMENTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'VITTAS_PAYMENTS_URL', plugin_dir_url( __FILE__ ) );

require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-logger.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-settings.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-api.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-admin.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-order-handler.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-webhook.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-shortcode.php';
require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-updater.php';

// Registered on admin_init rather than init: update checks only matter in the
// dashboard and during cron, and there is no reason to load the checker on
// every front-end page view a shopper triggers.
add_action( 'admin_init', array( 'Vittas_Updater', 'init' ) );
add_action( 'wp_loaded', function () {
	if ( wp_doing_cron() ) {
		Vittas_Updater::init();
	}
} );

/**
 * Bootstraps every piece of the plugin that does not depend on WooCommerce.
 *
 * WooCommerce wiring is deferred to `plugins_loaded` (below) because the
 * gateway class extends WC_Payment_Gateway, which does not exist until
 * WooCommerce itself has loaded.
 */
function vittas_payments_init() {
	Vittas_Settings::init();
	Vittas_Admin::init();
	Vittas_Webhook::init();
	Vittas_Shortcode::init();
}
add_action( 'init', 'vittas_payments_init' );

/**
 * Registers the WooCommerce gateway when WooCommerce is active.
 */
function vittas_payments_woocommerce_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-wc-gateway.php';

	Vittas_WC_Gateway::register_ajax();

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'Vittas_WC_Gateway';
			return $gateways;
		}
	);
}
add_action( 'plugins_loaded', 'vittas_payments_woocommerce_init' );

/**
 * Declares compatibility with WooCommerce features that require an opt-in.
 *
 * Without the `custom_order_tables` declaration WooCommerce shows the plugin as
 * incompatible with High-Performance Order Storage and refuses to enable HPOS.
 * The plugin only ever touches orders through the CRUD API (wc_get_order,
 * update_meta_data, save), so it is HPOS-safe.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VITTAS_PAYMENTS_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VITTAS_PAYMENTS_FILE, true );
	}
);

/**
 * Registers the block-based checkout integration.
 *
 * Classic checkout and block checkout are two separate registries — a gateway
 * registered only via `woocommerce_payment_gateways` is invisible on a store
 * using the Checkout block, which is the default for new stores.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once VITTAS_PAYMENTS_PATH . 'includes/class-vittas-wc-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new Vittas_WC_Blocks_Support() );
			}
		);
	}
);

/**
 * Adds a Settings link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=vittas-payments' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vittas-payments' ) . '</a>' );
		return $links;
	}
);

/**
 * Flushes rewrite rules on activation so /wp-json/vittas/v1/webhook resolves
 * immediately on sites using pretty permalinks.
 */
register_activation_hook( __FILE__, 'flush_rewrite_rules' );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
