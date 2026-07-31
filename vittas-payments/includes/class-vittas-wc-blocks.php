<?php
/**
 * WooCommerce Blocks (Checkout block) integration.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Vittas with the block checkout's payment method registry.
 *
 * The Checkout block is the default for stores created on recent WooCommerce
 * versions and reads from a registry entirely separate from the classic
 * `woocommerce_payment_gateways` filter. Without this class the gateway simply
 * does not appear for those stores.
 */
class Vittas_WC_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method slug — must match Vittas_WC_Gateway::$id.
	 *
	 * @var string
	 */
	protected $name = 'vittas';

	/**
	 * Loads the gateway's stored settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_vittas_settings', array() );
	}

	/**
	 * Whether the method should be offered in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->get_gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Registers the front-end script and returns its handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'vittas-blocks';

		wp_register_script(
			$handle,
			VITTAS_PAYMENTS_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			VITTAS_PAYMENTS_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'vittas-payments' );
		}

		return array( $handle );
	}

	/**
	 * Data handed to the block script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->get_gateway();

		return array(
			'title'       => $gateway ? $gateway->title : __( 'Bank Transfer (Vittas)', 'vittas-payments' ),
			'description' => $gateway ? $gateway->description : '',
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products' ),
		);
	}

	/**
	 * Resolves the classic gateway instance backing this method.
	 *
	 * @return Vittas_WC_Gateway|null
	 */
	private function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}
}
