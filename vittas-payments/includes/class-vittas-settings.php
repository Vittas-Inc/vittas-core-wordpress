<?php
/**
 * Plugin settings store.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for API keys, mode and endpoints.
 *
 * Keys live here rather than in the WooCommerce gateway settings so that the
 * shortcode works on sites without WooCommerce, and so a merchant running both
 * enters their credentials exactly once.
 */
class Vittas_Settings {

	const OPTION = 'vittas_payments_settings';

	/**
	 * Default API hosts per mode.
	 *
	 * Test keys are only valid against the dev backend and live keys only
	 * against production — the same split the widget applies client-side by
	 * inspecting the `cs_test_` / `cs_live_` prefix.
	 */
	const API_TEST = 'https://dev-api.core.vittasinternational.com';
	const API_LIVE = 'https://api.core.vittasinternational.com';

	/** Default widget bundle per mode. */
	const CDN_TEST = 'https://dev-cdn.core.vittasinternational.com/latest/widget.js';
	const CDN_LIVE = 'https://cdn.core.vittasinternational.com/latest/widget.js';

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Registers the settings entry.
	 */
	public static function init() {
		register_setting(
			'vittas_payments',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'                => 'test',
			// Only secret keys are stored. The widget authenticates with the
			// session client secret this plugin fetches server-side, so the
			// merchant's public key has no role in the flow.
			'test_secret_key'     => '',
			'live_secret_key'     => '',
			'test_webhook_secret' => '',
			'live_webhook_secret' => '',
			'api_base'            => '',
			'widget_src'          => '',
			'debug'               => 'no',
		);
	}

	/**
	 * Setting keys that hold credentials.
	 *
	 * @return string[]
	 */
	public static function credential_keys() {
		return array( 'test_secret_key', 'live_secret_key', 'test_webhook_secret', 'live_webhook_secret' );
	}

	/**
	 * Returns all settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored     = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();
		return isset( $all[ $key ] ) && '' !== $all[ $key ] ? $all[ $key ] : $default;
	}

	/**
	 * Clears the in-request cache. Used after a save.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitises submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean = self::defaults();
		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$clean['mode']  = ( isset( $input['mode'] ) && 'live' === $input['mode'] ) ? 'live' : 'test';
		$clean['debug'] = ( isset( $input['debug'] ) && 'yes' === $input['debug'] ) ? 'yes' : 'no';

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Credential fields render empty, so an empty submission means "leave it
		// alone" rather than "clear it" — this is what keeps live secrets out of
		// the page HTML. Clearing is done with the explicit checkbox below.
		foreach ( self::credential_keys() as $key ) {
			$submitted = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			$clear     = ! empty( $input[ 'clear_' . $key ] );

			if ( $clear ) {
				$clean[ $key ] = '';
			} elseif ( '' !== $submitted ) {
				$clean[ $key ] = $submitted;
			} else {
				$clean[ $key ] = isset( $stored[ $key ] ) ? $stored[ $key ] : '';
			}
		}

		foreach ( array( 'api_base', 'widget_src' ) as $key ) {
			$value         = isset( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			$clean[ $key ] = '' === $value ? '' : esc_url_raw( $value );
		}

		self::flush_cache();
		return $clean;
	}

	/**
	 * Whether the plugin is in live mode.
	 *
	 * @return bool
	 */
	public static function is_live() {
		return 'live' === self::get( 'mode', 'test' );
	}

	/**
	 * Current mode as a string.
	 *
	 * @return string 'live' or 'test'.
	 */
	public static function mode() {
		return self::is_live() ? 'live' : 'test';
	}

	/**
	 * Secret key for the active mode. Never expose this to the browser.
	 *
	 * @return string
	 */
	public static function secret_key() {
		return (string) self::get( self::mode() . '_secret_key', '' );
	}

	/**
	 * A safe-to-display fingerprint of a stored credential.
	 *
	 * @param string $key Setting key.
	 * @return string Empty when nothing is stored.
	 */
	public static function masked( $key ) {
		$value = (string) self::get( $key, '' );
		if ( '' === $value ) {
			return '';
		}
		$head = substr( $value, 0, 8 );
		$tail = strlen( $value ) > 12 ? substr( $value, -4 ) : '';
		return $head . str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * Webhook signing secrets to verify inbound deliveries against.
	 *
	 * Both modes are returned because a signature is the authenticator here —
	 * trying each configured secret is safe, and it keeps a merchant who is
	 * testing in one mode while live traffic runs in the other from silently
	 * dropping half their webhooks.
	 *
	 * @return string[]
	 */
	public static function webhook_secrets() {
		$secrets = array(
			(string) self::get( 'test_webhook_secret', '' ),
			(string) self::get( 'live_webhook_secret', '' ),
		);
		return array_values( array_filter( array_unique( $secrets ) ) );
	}

	/**
	 * Base URL of the Vittas API for the active mode.
	 *
	 * @return string
	 */
	public static function api_base() {
		$custom = (string) self::get( 'api_base', '' );
		if ( '' !== $custom ) {
			return untrailingslashit( $custom );
		}
		return self::is_live() ? self::API_LIVE : self::API_TEST;
	}

	/**
	 * URL of the widget bundle for the active mode.
	 *
	 * @return string
	 */
	public static function widget_src() {
		$custom = (string) self::get( 'widget_src', '' );
		if ( '' !== $custom ) {
			return $custom;
		}
		return self::is_live() ? self::CDN_LIVE : self::CDN_TEST;
	}

	/**
	 * Whether verbose logging is on.
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		return 'yes' === self::get( 'debug', 'no' );
	}

	/**
	 * Whether the plugin has the credentials it needs to create sessions.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$key    = self::secret_key();
		$prefix = self::is_live() ? 'sk_live_' : 'sk_test_';
		return 0 === strpos( $key, $prefix );
	}

	/**
	 * The origin the merchant must whitelist in the Vittas portal.
	 *
	 * The widget refuses to open when `window.location.origin` is missing from
	 * the session's allowedOrigins list, and that failure surfaces as a generic
	 * "not authorised" error — so the exact string is shown in the admin UI.
	 *
	 * @return string
	 */
	public static function site_origin() {
		$parts = wp_parse_url( home_url() );
		if ( empty( $parts['host'] ) ) {
			return home_url();
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$origin = $scheme . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	/**
	 * The URL to register as the webhook endpoint in the Vittas portal.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( Vittas_Webhook::REST_NAMESPACE . Vittas_Webhook::ROUTE );
	}
}
