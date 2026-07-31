<?php
/**
 * WooCommerce payment gateway.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Pay with Vittas" — bank transfer checkout for WooCommerce.
 *
 * Flow: process_payment() creates the session server-side with the secret key
 * and redirects to WooCommerce's order-pay page, where the widget opens with
 * the resulting client secret. Redirect-style gateways work unchanged on both
 * classic and block checkout, which is why the session is created here rather
 * than over AJAX from the checkout form.
 */
class Vittas_WC_Gateway extends WC_Payment_Gateway {

	/**
	 * Currencies the Vittas bank transfer rail supports.
	 *
	 * @var string[]
	 */
	const SUPPORTED_CURRENCIES = array( 'NGN' );

	/**
	 * Sets up the gateway.
	 */
	public function __construct() {
		$this->id                 = 'vittas';
		$this->method_title       = __( 'Vittas', 'vittas-payments' );
		$this->method_description = __( 'Accept instant bank transfers through the Vittas payment widget. API keys are configured under Vittas Payments → Settings.', 'vittas-payments' );
		$this->has_fields         = false;
		// Refunds are deliberately not declared: the bank transfer rail has no
		// programmatic refund yet, and declaring support would give merchants a
		// refund button that can only ever fail.
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Bank Transfer (Vittas)', 'vittas-payments' ) );
		$this->description = $this->get_option( 'description', __( 'Pay by instant bank transfer. You will get account details to transfer to, and your order is confirmed automatically.', 'vittas-payments' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	/**
	 * Registers the confirmation endpoint.
	 *
	 * Deliberately not done in the constructor: WooCommerce only instantiates
	 * gateways once something asks for them, which is not guaranteed during an
	 * admin-ajax request. A handler that exists only when a gateway happens to
	 * have been built is a handler that intermittently returns "0".
	 */
	public static function register_ajax() {
		// Guests check out too, so the nopriv variant is required.
		add_action( 'wp_ajax_vittas_confirm_order', array( __CLASS__, 'ajax_confirm_order' ) );
		add_action( 'wp_ajax_nopriv_vittas_confirm_order', array( __CLASS__, 'ajax_confirm_order' ) );
	}

	/**
	 * Admin settings shown under WooCommerce → Settings → Payments → Vittas.
	 */
	public function init_form_fields() {
		$settings_url = admin_url( 'admin.php?page=vittas-payments' );

		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'vittas-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Vittas bank transfer', 'vittas-payments' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'vittas-payments' ),
				'type'        => 'text',
				'description' => __( 'What customers see as the payment method name.', 'vittas-payments' ),
				'default'     => __( 'Bank Transfer (Vittas)', 'vittas-payments' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'vittas-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'vittas-payments' ),
				'default'     => __( 'Pay by instant bank transfer. You will get account details to transfer to, and your order is confirmed automatically.', 'vittas-payments' ),
			),
			'credentials' => array(
				'title'       => __( 'API keys', 'vittas-payments' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings page URL. */
					__( 'Keys, mode and the webhook secret live on the shared <a href="%s">Vittas Payments settings page</a> so the shortcode can use them too.', 'vittas-payments' ),
					esc_url( $settings_url )
				),
			),
		);
	}

	/**
	 * Whether the gateway can be offered on the current cart.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! Vittas_Settings::is_configured() ) {
			return false;
		}
		if ( ! in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Creates the payment session and sends the customer to the widget.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'That order could not be found.', 'vittas-payments' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( ! in_array( $order->get_currency(), self::SUPPORTED_CURRENCIES, true ) ) {
			$message = sprintf(
				/* translators: %s: currency code. */
				__( 'Vittas cannot process %s payments.', 'vittas-payments' ),
				$order->get_currency()
			);
			wc_add_notice( $message, 'error' );
			return array( 'result' => 'failure' );
		}

		$session = $this->start_session( $order );

		if ( is_wp_error( $session ) ) {
			wc_add_notice( $session->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Creates a fresh payment session for an order and stores it on the order.
	 *
	 * Each call counts as a new attempt so the Vittas reference stays unique —
	 * references are the idempotency key on that side, and a retry must not
	 * collide with the attempt that just expired.
	 *
	 * @param WC_Order $order Order.
	 * @return array|WP_Error Session payload with sessionId and clientSecret.
	 */
	private function start_session( $order ) {
		$attempt = (int) $order->get_meta( '_vittas_attempt' ) + 1;
		$order->update_meta_data( '_vittas_attempt', $attempt );

		$session = Vittas_API::create_session(
			array(
				'amount'      => (int) round( (float) $order->get_total() * 100 ),
				'currency'    => $order->get_currency(),
				'reference'   => $this->build_reference( $order, $attempt ),
				'payer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'payer_email' => $order->get_billing_email(),
				'payer_phone' => $order->get_billing_phone(),
				'metadata'    => $this->build_metadata( $order ),
			)
		);

		if ( is_wp_error( $session ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message. */
					__( 'Vittas session could not be created: %s', 'vittas-payments' ),
					$session->get_error_message()
				)
			);
			$order->save();
			return $session;
		}

		$order->update_meta_data( Vittas_Order_Handler::META_SESSION_ID, $session['sessionId'] );
		$order->update_meta_data( Vittas_Order_Handler::META_CLIENT_SECRET, $session['clientSecret'] );
		$order->update_meta_data( Vittas_Order_Handler::META_REFERENCE, $this->build_reference( $order, $attempt ) );
		$order->update_meta_data( Vittas_Order_Handler::META_ENVIRONMENT, Vittas_Settings::mode() );
		// Deliveries are scoped to a session; a new attempt starts a clean slate.
		$order->update_meta_data( Vittas_Order_Handler::META_DELIVERIES, array() );
		$order->save();

		Vittas_Logger::debug(
			'Session created for order',
			array( 'order' => $order->get_id(), 'session' => $session['sessionId'], 'attempt' => $attempt )
		);

		return $session;
	}

	/**
	 * Renders the order-pay page that opens the widget.
	 *
	 * @param int $order_id Order id.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p class="vittas-notice">' . esc_html__( 'This order is already paid.', 'vittas-payments' ) . '</p>';
			return;
		}

		// An expired or cancelled session cannot be reopened, so the retry button
		// comes back here with a nonced flag asking for a brand new one.
		if ( $this->is_restart_request( $order ) ) {
			$restarted = $this->start_session( $order );
			if ( is_wp_error( $restarted ) ) {
				echo '<p class="vittas-notice">' . esc_html( $restarted->get_error_message() ) . '</p>';
				return;
			}
		}

		$client_secret = (string) $order->get_meta( Vittas_Order_Handler::META_CLIENT_SECRET );
		if ( '' === $client_secret ) {
			echo '<p class="vittas-notice">' . esc_html__( 'This payment session is no longer available. Please place the order again.', 'vittas-payments' ) . '</p>';
			return;
		}

		wp_enqueue_style(
			'vittas-payments',
			VITTAS_PAYMENTS_URL . 'assets/css/vittas.css',
			array(),
			VITTAS_PAYMENTS_VERSION
		);

		// Loaded straight from the Vittas CDN — the widget and the sandboxed
		// iframe it opens are resolved relative to this script's own URL, so it
		// cannot be bundled or self-hosted without also hosting the iframe.
		wp_enqueue_script( 'vittas-widget', Vittas_Settings::widget_src(), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_enqueue_script(
			'vittas-checkout',
			VITTAS_PAYMENTS_URL . 'assets/js/checkout.js',
			array( 'vittas-widget' ),
			VITTAS_PAYMENTS_VERSION,
			true
		);

		wp_localize_script(
			'vittas-checkout',
			'vittasCheckout',
			array(
				'clientSecret' => $client_secret,
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'vittas_confirm_order' ),
				'orderId'      => $order->get_id(),
				'orderKey'     => $order->get_order_key(),
				'retryUrl'     => $this->restart_url( $order ),
				'i18n'         => array(
					'opening'   => __( 'Opening secure payment…', 'vittas-payments' ),
					'verifying' => __( 'Confirming your payment…', 'vittas-payments' ),
					'cancelled' => __( 'Payment cancelled. You have not been charged.', 'vittas-payments' ),
					'failed'    => __( 'We could not confirm your payment. If you have been debited, contact us with your order number and we will resolve it.', 'vittas-payments' ),
					'retry'     => __( 'Try again', 'vittas-payments' ),
				),
			)
		);
		?>
		<div class="vittas-receipt" id="vittas-receipt">
			<p class="vittas-receipt__status" id="vittas-receipt-status">
				<?php esc_html_e( 'Opening secure payment…', 'vittas-payments' ); ?>
			</p>
			<button type="button" class="button vittas-receipt__retry" id="vittas-receipt-retry" hidden>
				<?php esc_html_e( 'Pay now', 'vittas-payments' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Confirms a payment server-side after the widget reports success.
	 *
	 * The widget's onSuccess runs in the payer's browser, so this handler
	 * ignores everything the browser claims about the outcome and re-reads the
	 * session from the Vittas API with the secret key before touching the order.
	 * It exists so that a merchant whose webhook endpoint is unreachable (local
	 * development, a firewalled host) still gets orders settled on return.
	 */
	public static function ajax_confirm_order() {
		check_ajax_referer( 'vittas_confirm_order', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		$order = wc_get_order( $order_id );

		// The order key is WooCommerce's own bearer token for guest access to an
		// order, and hash_equals keeps the comparison constant-time.
		if ( ! $order instanceof WC_Order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => __( 'That order could not be verified.', 'vittas-payments' ) ), 403 );
		}

		if ( $order->is_paid() ) {
			wp_send_json_success(
				array(
					'status'   => 'successful',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		$session_id = (string) $order->get_meta( Vittas_Order_Handler::META_SESSION_ID );
		$state      = Vittas_API::get_session( $session_id );

		if ( is_wp_error( $state ) ) {
			// The webhook is still the backstop, so this is not fatal for the
			// order — only for this page's ability to report an outcome.
			wp_send_json_error( array( 'message' => $state->get_error_message() ), 200 );
		}

		$status = isset( $state['status'] ) ? (string) $state['status'] : 'pending';

		if ( 'pending' !== $status ) {
			// locate() re-derives the order from metadata; here it is already
			// known and verified, so apply directly.
			$state['sessionId'] = $session_id;
			Vittas_Order_Handler::apply( $order, $status, $state, 'browser return' );
		}

		$order = wc_get_order( $order_id );

		wp_send_json_success(
			array(
				'status'   => $status,
				'paid'     => $order->is_paid(),
				'redirect' => $order->is_paid() ? $order->get_checkout_order_received_url() : '',
			)
		);
	}

	/**
	 * URL that reopens the order-pay page with a fresh payment session.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function restart_url( $order ) {
		return wp_nonce_url(
			add_query_arg( 'vittas_restart', '1', $order->get_checkout_payment_url( true ) ),
			'vittas_restart_' . $order->get_id(),
			'vittas_nonce'
		);
	}

	/**
	 * Whether the current request is a valid, nonced retry.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_restart_request( $order ) {
		if ( empty( $_GET['vittas_restart'] ) ) {
			return false;
		}
		$nonce = isset( $_GET['vittas_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['vittas_nonce'] ) ) : '';
		return (bool) wp_verify_nonce( $nonce, 'vittas_restart_' . $order->get_id() );
	}

	/**
	 * Builds the merchant reference sent to Vittas.
	 *
	 * References are the merchant-side idempotency key, so a retry of the same
	 * order must not reuse one that already has a session against it — the
	 * attempt counter makes each distinct while staying readable in the Vittas
	 * dashboard.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $attempt Attempt number, starting at 1.
	 * @return string
	 */
	private function build_reference( $order, $attempt = 1 ) {
		$reference = 'WC-' . $order->get_order_number();
		if ( $attempt > 1 ) {
			$reference .= '-' . $attempt;
		}

		/**
		 * Filters the Vittas reference for an order.
		 *
		 * @param string   $reference Reference.
		 * @param WC_Order $order     Order.
		 */
		return apply_filters( 'vittas_order_reference', $reference, $order );
	}

	/**
	 * Metadata echoed back on every webhook for this session.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_metadata( $order ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				// Kobo, matching the session amount's unit.
				'price'    => (int) round( (float) $item->get_total() * 100 ),
			);
		}

		$metadata = array(
			'source'       => 'woocommerce',
			'order_id'     => $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'site_url'     => untrailingslashit( home_url() ),
			'products'     => $products,
		);

		/**
		 * Filters the metadata attached to a Vittas session for an order.
		 *
		 * @param array    $metadata Metadata.
		 * @param WC_Order $order    Order.
		 */
		return apply_filters( 'vittas_order_metadata', $metadata, $order );
	}
}
