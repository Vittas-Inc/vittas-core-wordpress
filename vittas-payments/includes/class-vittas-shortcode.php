<?php
/**
 * The [vittas_pay] shortcode and its Gutenberg block wrapper.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a pay button on any WordPress page, with no WooCommerce required.
 *
 * The hard problem here is that the amount is authored in page content and the
 * session is created by an endpoint the public can call. If the browser were
 * allowed to name its own amount, anyone could pay ₦1 for a ₦50,000 item. So
 * the shortcode emits an HMAC-signed token describing the charge, and the
 * endpoint charges what the token says — never what the request says.
 */
class Vittas_Shortcode {

	const NONCE_ACTION = 'vittas_public_session';

	/**
	 * Registers the shortcode, block and AJAX endpoints.
	 */
	public static function init() {
		add_shortcode( 'vittas_pay', array( __CLASS__, 'render' ) );

		add_action( 'wp_ajax_vittas_create_session', array( __CLASS__, 'ajax_create_session' ) );
		add_action( 'wp_ajax_nopriv_vittas_create_session', array( __CLASS__, 'ajax_create_session' ) );
		add_action( 'wp_ajax_vittas_confirm_payment', array( __CLASS__, 'ajax_confirm_payment' ) );
		add_action( 'wp_ajax_nopriv_vittas_confirm_payment', array( __CLASS__, 'ajax_confirm_payment' ) );

		add_action( 'init', array( __CLASS__, 'register_block' ), 20 );
	}

	/**
	 * Registers a block that renders through the same shortcode callback.
	 *
	 * The editor script is what actually puts the block in the inserter — a
	 * server-side-only registration gives you a render callback nobody can
	 * reach from the editor.
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'vittas-block-editor',
			VITTAS_PAYMENTS_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			VITTAS_PAYMENTS_VERSION,
			true
		);

		register_block_type(
			'vittas/pay-button',
			array(
				'api_version'     => 2,
				'title'           => __( 'Vittas Pay Button', 'vittas-payments' ),
				'category'        => 'widgets',
				'editor_script'   => 'vittas-block-editor',
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => array(
					'amount'      => array( 'type' => 'string', 'default' => '0' ),
					'currency'    => array( 'type' => 'string', 'default' => 'NGN' ),
					'label'       => array( 'type' => 'string', 'default' => '' ),
					'description' => array( 'type' => 'string', 'default' => '' ),
					'reference'   => array( 'type' => 'string', 'default' => '' ),
					'redirect'    => array( 'type' => 'string', 'default' => '' ),
					'min'         => array( 'type' => 'string', 'default' => '100' ),
					'max'         => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Renders the button.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				// Major units (naira), because that is what a page author types.
				'amount'      => '0',
				'currency'    => 'NGN',
				'label'       => __( 'Pay now', 'vittas-payments' ),
				'description' => '',
				// Prefix for the generated reference, e.g. DONATION.
				'reference'   => 'WP',
				'redirect'    => '',
				// Only used when amount is 0 (payer chooses the amount).
				'min'         => '100',
				'max'         => '',
			),
			is_array( $atts ) ? $atts : array(),
			'vittas_pay'
		);

		// Block attributes always arrive, including empty strings for fields the
		// author left blank — so re-apply the defaults the shortcode would have.
		if ( '' === trim( $atts['label'] ) ) {
			$atts['label'] = __( 'Pay now', 'vittas-payments' );
		}
		if ( '' === trim( $atts['reference'] ) ) {
			$atts['reference'] = 'WP';
		}
		if ( '' === trim( $atts['currency'] ) ) {
			$atts['currency'] = 'NGN';
		}
		if ( '' === trim( (string) $atts['min'] ) ) {
			$atts['min'] = '100';
		}

		if ( ! Vittas_Settings::is_configured() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="vittas-notice vittas-notice--error">' .
					esc_html__( 'Vittas Payments is not configured — add your secret key under Vittas Payments → Settings. (Only administrators see this message.)', 'vittas-payments' ) .
					'</p>';
			}
			return '';
		}

		$amount_kobo = self::to_kobo( $atts['amount'] );
		$min_kobo    = max( 100, self::to_kobo( $atts['min'] ) );
		$max_kobo    = '' === $atts['max'] ? 0 : self::to_kobo( $atts['max'] );
		$open_amount = $amount_kobo < 100;

		$charge = array(
			'amount'    => $open_amount ? 0 : $amount_kobo,
			'currency'  => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $atts['currency'] ), 0, 3 ) ) ?: 'NGN',
			'prefix'    => substr( preg_replace( '/[^A-Za-z0-9\-]/', '', $atts['reference'] ), 0, 20 ),
			'min'       => $min_kobo,
			'max'       => $max_kobo,
			'redirect'  => $atts['redirect'] ? esc_url_raw( $atts['redirect'] ) : '',
			'permalink' => get_permalink() ? get_permalink() : home_url(),
		);

		self::enqueue_assets();

		$id = 'vittas-pay-' . wp_generate_uuid4();

		ob_start();
		?>
		<div class="vittas-pay" id="<?php echo esc_attr( $id ); ?>"
			data-charge="<?php echo esc_attr( self::sign( $charge ) ); ?>"
			data-open-amount="<?php echo $open_amount ? '1' : '0'; ?>"
			data-redirect="<?php echo esc_attr( $charge['redirect'] ); ?>">

			<?php if ( '' !== $atts['description'] ) : ?>
				<p class="vittas-pay__description"><?php echo esc_html( $atts['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( $open_amount ) : ?>
				<label class="vittas-pay__amount-label" for="<?php echo esc_attr( $id ); ?>-amount">
					<?php esc_html_e( 'Amount', 'vittas-payments' ); ?>
				</label>
				<input class="vittas-pay__amount" type="number" inputmode="decimal" step="0.01"
					id="<?php echo esc_attr( $id ); ?>-amount"
					min="<?php echo esc_attr( number_format( $min_kobo / 100, 2, '.', '' ) ); ?>"
					<?php echo $max_kobo ? 'max="' . esc_attr( number_format( $max_kobo / 100, 2, '.', '' ) ) . '"' : ''; ?>
					placeholder="<?php echo esc_attr( number_format( $min_kobo / 100, 2, '.', '' ) ); ?>" />
			<?php endif; ?>

			<label class="vittas-pay__email-label" for="<?php echo esc_attr( $id ); ?>-email">
				<?php esc_html_e( 'Email (for your receipt)', 'vittas-payments' ); ?>
			</label>
			<input class="vittas-pay__email" type="email" id="<?php echo esc_attr( $id ); ?>-email"
				placeholder="you@example.com" autocomplete="email" />

			<button type="button" class="vittas-pay__button">
				<?php echo esc_html( $atts['label'] ); ?>
			</button>

			<p class="vittas-pay__status" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enqueues the shortcode assets.
	 */
	private static function enqueue_assets() {
		wp_enqueue_style(
			'vittas-payments',
			VITTAS_PAYMENTS_URL . 'assets/css/vittas.css',
			array(),
			VITTAS_PAYMENTS_VERSION
		);

		wp_enqueue_script( 'vittas-widget', Vittas_Settings::widget_src(), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_enqueue_script(
			'vittas-shortcode',
			VITTAS_PAYMENTS_URL . 'assets/js/shortcode.js',
			array( 'vittas-widget' ),
			VITTAS_PAYMENTS_VERSION,
			true
		);

		wp_localize_script(
			'vittas-shortcode',
			'vittasPay',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'preparing'   => __( 'Preparing payment…', 'vittas-payments' ),
					'verifying'   => __( 'Confirming your payment…', 'vittas-payments' ),
					'success'     => __( 'Payment received. Thank you!', 'vittas-payments' ),
					'cancelled'   => __( 'Payment cancelled. You have not been charged.', 'vittas-payments' ),
					'failed'      => __( 'Payment could not be completed.', 'vittas-payments' ),
					'badAmount'   => __( 'Please enter a valid amount.', 'vittas-payments' ),
					'unconfirmed' => __( 'We are still confirming your payment. You will get an email once it clears.', 'vittas-payments' ),
				),
			)
		);
	}

	/**
	 * Creates a session for a signed charge.
	 */
	public static function ajax_create_session() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::within_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'Too many payment attempts. Please wait a minute and try again.', 'vittas-payments' ) ), 429 );
		}

		$token  = isset( $_POST['charge'] ) ? sanitize_text_field( wp_unslash( $_POST['charge'] ) ) : '';
		$charge = self::verify( $token );

		if ( null === $charge ) {
			wp_send_json_error( array( 'message' => __( 'This payment button is no longer valid. Please reload the page.', 'vittas-payments' ) ), 400 );
		}

		// The signed token fixes the amount for a fixed-price button. Only when
		// the author configured an open amount is the request allowed to name
		// one, and it is clamped to the signed bounds.
		if ( (int) $charge['amount'] > 0 ) {
			$amount = (int) $charge['amount'];
		} else {
			$requested = isset( $_POST['amount'] ) ? self::to_kobo( wp_unslash( $_POST['amount'] ) ) : 0;
			$min       = max( 100, (int) $charge['min'] );
			$max       = (int) $charge['max'];

			if ( $requested < $min || ( $max > 0 && $requested > $max ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter an amount within the allowed range.', 'vittas-payments' ) ), 400 );
			}
			$amount = $requested;
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( '' === $email && is_user_logged_in() ) {
			$email = wp_get_current_user()->user_email;
		}

		$prefix    = '' !== $charge['prefix'] ? $charge['prefix'] : 'WP';
		$reference = $prefix . '-' . strtoupper( wp_generate_password( 10, false, false ) );

		$session = Vittas_API::create_session(
			array(
				'amount'      => $amount,
				'currency'    => $charge['currency'],
				'reference'   => $reference,
				'payer_email' => $email ? $email : null,
				'payer_name'  => is_user_logged_in() ? wp_get_current_user()->display_name : null,
				'metadata'    => array(
					'source'    => 'wordpress-shortcode',
					'site_url'  => untrailingslashit( home_url() ),
					'permalink' => $charge['permalink'],
				),
			)
		);

		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => $session->get_error_message() ), 200 );
		}

		wp_send_json_success(
			array(
				'clientSecret' => $session['clientSecret'],
				'sessionId'    => $session['sessionId'],
				// Proof that this browser is the one that started this session.
				// Without it, anyone holding a session id could call the confirm
				// endpoint and re-fire the site's fulfilment hook.
				'confirmToken' => self::sign_session( $session['sessionId'] ),
				'reference'    => $reference,
			)
		);
	}

	/**
	 * Re-reads a session server-side and broadcasts the outcome.
	 *
	 * Non-WooCommerce sites hook `vittas_shortcode_payment` to fulfil. This is
	 * a convenience path for immediate feedback — the webhook remains the
	 * reliable channel, since a payer can close the tab before this runs.
	 */
	public static function ajax_confirm_payment() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( '' === $session_id || ! hash_equals( self::sign_session( $session_id ), $token ) ) {
			wp_send_json_error( array( 'message' => __( 'This payment could not be verified.', 'vittas-payments' ) ), 403 );
		}

		$state = Vittas_API::get_session( $session_id );

		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ), 200 );
		}

		$status = isset( $state['status'] ) ? (string) $state['status'] : 'pending';

		// A page refresh or a double-click must not fulfil twice. The webhook
		// path is idempotent for the same reason.
		$once = 'vittas_done_' . md5( $session_id );

		if ( 'successful' === $status && ! get_transient( $once ) ) {
			set_transient( $once, 1, DAY_IN_SECONDS );

			/**
			 * Fires when a shortcode payment is confirmed server-side.
			 *
			 * @param array $state Session status payload.
			 */
			do_action( 'vittas_shortcode_payment', $state );
		}

		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Signs a session id so only the browser that created it can confirm it.
	 *
	 * @param string $session_id Session id.
	 * @return string
	 */
	private static function sign_session( $session_id ) {
		return hash_hmac( 'sha256', 'session:' . $session_id, self::signing_key() );
	}

	/**
	 * Throttles public session creation per client.
	 *
	 * This endpoint is intentionally reachable by logged-out visitors, and every
	 * call makes Vittas allocate a real temporary virtual account. Without a cap
	 * a bot could exhaust that pool from a single page. WordPress nonces do not
	 * help here — they are trivially fetched by loading the page.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		/**
		 * Filters how many payment sessions one client may start per minute.
		 *
		 * @param int $limit Maximum sessions per minute. Zero disables the cap.
		 */
		$limit = (int) apply_filters( 'vittas_session_rate_limit', 5 );
		if ( $limit <= 0 ) {
			return true;
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'vittas_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			Vittas_Logger::error( 'Rate limited a session request', array( 'ip' => $ip ) );
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Signs a charge description so the browser cannot alter it.
	 *
	 * @param array $charge Charge data.
	 * @return string
	 */
	private static function sign( array $charge ) {
		$json    = wp_json_encode( $charge );
		$encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, self::signing_key() );
	}

	/**
	 * Verifies and decodes a signed charge token.
	 *
	 * @param string $token Signed token.
	 * @return array|null Null when the signature does not check out.
	 */
	private static function verify( $token ) {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $encoded, $signature ) = $parts;

		if ( ! hash_equals( hash_hmac( 'sha256', $encoded, self::signing_key() ), $signature ) ) {
			return null;
		}

		$json   = base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$charge = json_decode( (string) $json, true );

		if ( ! is_array( $charge ) || ! isset( $charge['amount'], $charge['currency'] ) ) {
			return null;
		}

		return wp_parse_args(
			$charge,
			array(
				'prefix'    => 'WP',
				'min'       => 100,
				'max'       => 0,
				'redirect'  => '',
				'permalink' => home_url(),
			)
		);
	}

	/**
	 * Key used to sign charge tokens.
	 *
	 * Derived from the site's own auth salts, so tokens are unforgeable without
	 * wp-config.php and are invalidated automatically if the salts rotate.
	 *
	 * @return string
	 */
	private static function signing_key() {
		return wp_salt( 'vittas_pay_charge' );
	}

	/**
	 * Converts a major-unit amount string to kobo.
	 *
	 * @param string $amount Amount such as "5000" or "1,250.50".
	 * @return int
	 */
	private static function to_kobo( $amount ) {
		$normalised = preg_replace( '/[^0-9.]/', '', (string) $amount );
		if ( '' === $normalised ) {
			return 0;
		}
		return (int) round( (float) $normalised * 100 );
	}
}
