<?php
/**
 * Removes plugin data on uninstall.
 *
 * Order meta is deliberately left in place — it is part of the payment record
 * for orders that already exist, and a merchant uninstalling the plugin should
 * not lose the Vittas reference attached to a completed order.
 *
 * @package Vittas_Payments
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vittas_payments_settings' );
delete_option( 'woocommerce_vittas_settings' );
