<?php
/**
 * Admin settings screen.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders Vittas Payments → Settings.
 */
class Vittas_Admin {

	const PAGE = 'vittas-payments';

	/**
	 * Hooks the admin screens.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'configuration_notice' ) );
		add_action( 'wp_ajax_vittas_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * Adds the top-level menu.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Vittas Payments', 'vittas-payments' ),
			__( 'Vittas Payments', 'vittas-payments' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-money-alt',
			56
		);
	}

	/**
	 * Nags on the plugins/settings screens until keys are entered.
	 */
	public static function configuration_notice() {
		if ( ! current_user_can( 'manage_options' ) || Vittas_Settings::is_configured() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Vittas Payments needs your API keys before it can take payments.', 'vittas-payments' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Add them now', 'vittas-payments' )
		);
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Vittas_Settings::all();
		$mode     = Vittas_Settings::mode();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vittas Payments', 'vittas-payments' ); ?></h1>

			<h2><?php esc_html_e( 'Set these in your Vittas dashboard', 'vittas-payments' ); ?></h2>
			<p><?php esc_html_e( 'The widget and the webhook both refuse traffic that has not been registered on the Vittas side. Copy these two values into your Vittas dashboard before testing.', 'vittas-payments' ); ?></p>
			<table class="widefat striped" style="max-width:52rem">
				<tbody>
					<tr>
						<td style="width:16rem"><strong><?php esc_html_e( 'Allowed origin', 'vittas-payments' ); ?></strong><br />
							<span class="description"><?php esc_html_e( 'Widget settings → Allowed origins. The widget will not open on a page whose origin is missing here.', 'vittas-payments' ); ?></span>
						</td>
						<td><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( Vittas_Settings::site_origin() ); ?>" /></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Webhook URL', 'vittas-payments' ); ?></strong><br />
							<span class="description"><?php esc_html_e( 'Developers → Webhooks. Copy the signing secret it gives you into the field below.', 'vittas-payments' ); ?></span>
						</td>
						<td><input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( Vittas_Settings::webhook_url() ); ?>" /></td>
					</tr>
				</tbody>
			</table>

			<?php if ( 0 === strpos( Vittas_Settings::webhook_url(), 'http://' ) ) : ?>
				<div class="notice notice-info inline" style="max-width:52rem">
					<p><?php esc_html_e( 'This site is on http:// and is probably not reachable from the internet, so webhooks will not arrive. Orders still settle when the customer returns to the site — for full webhook testing, expose the site over HTTPS (the bundled example project uses a Cloudflare tunnel for this).', 'vittas-payments' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'vittas_payments' ); ?>

				<h2><?php esc_html_e( 'Credentials', 'vittas-payments' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'vittas-payments' ); ?></th>
						<td>
							<fieldset>
								<label style="margin-right:1rem">
									<input type="radio" name="<?php echo esc_attr( Vittas_Settings::OPTION ); ?>[mode]" value="test" <?php checked( 'test', $mode ); ?> />
									<?php esc_html_e( 'Test', 'vittas-payments' ); ?>
								</label>
								<label>
									<input type="radio" name="<?php echo esc_attr( Vittas_Settings::OPTION ); ?>[mode]" value="live" <?php checked( 'live', $mode ); ?> />
									<?php esc_html_e( 'Live', 'vittas-payments' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Test keys only work against the Vittas sandbox and never move real money.', 'vittas-payments' ); ?></p>
							</fieldset>
						</td>
					</tr>

					<?php
					self::render_key_row( 'test_secret_key', __( 'Test secret key', 'vittas-payments' ), 'sk_test_…' );
					self::render_key_row( 'test_webhook_secret', __( 'Test webhook secret', 'vittas-payments' ), __( 'from Developers → Webhooks', 'vittas-payments' ) );
					self::render_key_row( 'live_secret_key', __( 'Live secret key', 'vittas-payments' ), 'sk_live_…' );
					self::render_key_row( 'live_webhook_secret', __( 'Live webhook secret', 'vittas-payments' ), __( 'from Developers → Webhooks', 'vittas-payments' ) );
					?>
				</table>
				<p class="description">
					<?php esc_html_e( 'Your public key is not needed — the plugin creates each payment session server-side with the secret key, and the browser only ever receives a session-scoped client secret.', 'vittas-payments' ); ?>
				</p>

				<h2><?php esc_html_e( 'Advanced', 'vittas-payments' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vittas-api-base"><?php esc_html_e( 'API base URL', 'vittas-payments' ); ?></label></th>
						<td>
							<input type="url" id="vittas-api-base" class="regular-text code"
								name="<?php echo esc_attr( Vittas_Settings::OPTION ); ?>[api_base]"
								value="<?php echo esc_attr( $settings['api_base'] ); ?>"
								placeholder="<?php echo esc_attr( Vittas_Settings::api_base() ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank unless Vittas gave you a dedicated host. Overriding this also requires a matching widget bundle below.', 'vittas-payments' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vittas-widget-src"><?php esc_html_e( 'Widget bundle URL', 'vittas-payments' ); ?></label></th>
						<td>
							<input type="url" id="vittas-widget-src" class="regular-text code"
								name="<?php echo esc_attr( Vittas_Settings::OPTION ); ?>[widget_src]"
								value="<?php echo esc_attr( $settings['widget_src'] ); ?>"
								placeholder="<?php echo esc_attr( Vittas_Settings::widget_src() ); ?>" />
							<p class="description"><?php esc_html_e( 'Pin a specific version (…/v0.5.0/widget.js) instead of latest if you want release control.', 'vittas-payments' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug logging', 'vittas-payments' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Vittas_Settings::OPTION ); ?>[debug]" value="yes" <?php checked( 'yes', $settings['debug'] ); ?> />
								<?php esc_html_e( 'Log API calls and webhooks', 'vittas-payments' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Written to WooCommerce → Status → Logs (source: vittas), or the PHP error log without WooCommerce. Keys are masked.', 'vittas-payments' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Check your keys', 'vittas-payments' ); ?></h2>
			<p>
				<button type="button" class="button" id="vittas-test-connection"><?php esc_html_e( 'Test connection', 'vittas-payments' ); ?></button>
				<span id="vittas-test-result" style="margin-left:.5rem"></span>
			</p>

			<script>
			( function () {
				var button = document.getElementById( 'vittas-test-connection' );
				var out = document.getElementById( 'vittas-test-result' );
				if ( ! button ) { return; }
				button.addEventListener( 'click', function () {
					button.disabled = true;
					out.textContent = <?php echo wp_json_encode( __( 'Checking…', 'vittas-payments' ) ); ?>;
					var body = new URLSearchParams();
					body.append( 'action', 'vittas_test_connection' );
					body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'vittas_test_connection' ) ); ?> );
					fetch( ajaxurl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: body.toString()
					} ).then( function ( r ) { return r.json(); } ).then( function ( result ) {
						out.textContent = result && result.data && result.data.message ? result.data.message : '';
						out.style.color = result && result.success ? '#067647' : '#b42318';
						button.disabled = false;
					} ).catch( function () {
						out.textContent = <?php echo wp_json_encode( __( 'The check could not run.', 'vittas-payments' ) ); ?>;
						out.style.color = '#b42318';
						button.disabled = false;
					} );
				} );
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * Renders one credential row.
	 *
	 * The stored value is never written into the page. A live secret key in the
	 * HTML is one browser cache, screen share or admin-side XSS away from being
	 * someone else's, and nothing here needs to read it back — so the field
	 * shows a fingerprint, submits empty, and empty means "keep what you have".
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $placeholder Placeholder shown when nothing is stored yet.
	 */
	private static function render_key_row( $key, $label, $placeholder ) {
		$id     = 'vittas-' . str_replace( '_', '-', $key );
		$masked = Vittas_Settings::masked( $key );
		$name   = Vittas_Settings::OPTION;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="password" id="<?php echo esc_attr( $id ); ?>"
					class="regular-text code" autocomplete="off" value=""
					name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]"
					placeholder="<?php echo esc_attr( $masked ? $masked : $placeholder ); ?>" />
				<?php if ( $masked ) : ?>
					<label style="margin-left:.75rem">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name ); ?>[clear_<?php echo esc_attr( $key ); ?>]" />
						<?php esc_html_e( 'Remove', 'vittas-payments' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Saved. Leave blank to keep it, or type a new value to replace it.', 'vittas-payments' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Verifies that the configured secret key reaches Vittas and authenticates.
	 *
	 * There is no dedicated ping endpoint, so this reads a session id that
	 * cannot exist: a 401 means the key was rejected, while a 404 means the key
	 * was accepted and the lookup simply found nothing — which is exactly the
	 * signal we want.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'vittas_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'vittas-payments' ) ), 403 );
		}

		if ( ! Vittas_Settings::is_configured() ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: expected key prefix. */
						__( 'No %s key is set for the selected mode.', 'vittas-payments' ),
						Vittas_Settings::is_live() ? 'sk_live_' : 'sk_test_'
					),
				)
			);
		}

		$result = Vittas_API::get_session( '00000000-0000-0000-0000-000000000000' );

		if ( ! is_wp_error( $result ) ) {
			wp_send_json_success( array( 'message' => __( 'Connected.', 'vittas-payments' ) ) );
		}

		$data   = $result->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;

		if ( 404 === $status ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: mode name. */
						__( 'Connected — %s key accepted.', 'vittas-payments' ),
						Vittas_Settings::mode()
					),
				)
			);
		}

		if ( 401 === $status || 403 === $status ) {
			wp_send_json_error( array( 'message' => __( 'Vittas rejected this key. Check the key and the selected mode.', 'vittas-payments' ) ) );
		}

		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
}
