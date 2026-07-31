<?php
/**
 * Logging helper.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log (WooCommerce → Status → Logs, source "vittas")
 * when WooCommerce is present, and falls back to error_log otherwise so the
 * plugin still leaves a trail on non-WooCommerce sites.
 */
class Vittas_Logger {

	/**
	 * WooCommerce logger instance, resolved lazily.
	 *
	 * @var WC_Logger|null
	 */
	private static $wc_logger = null;

	/**
	 * Logs an informational message. Suppressed unless debug logging is on.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended as JSON.
	 */
	public static function debug( $message, array $context = array() ) {
		if ( ! Vittas_Settings::debug_enabled() ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Logs an error. Always written, regardless of the debug setting.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended as JSON.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Writes one line to the active log sink.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	private static function write( $level, $message, array $context ) {
		$line = $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			if ( null === self::$wc_logger ) {
				self::$wc_logger = wc_get_logger();
			}
			self::$wc_logger->log( $level, $line, array( 'source' => 'vittas' ) );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[vittas][%s] %s', $level, $line ) );
	}

	/**
	 * Masks anything that looks like a key or secret before it reaches a log file.
	 *
	 * Logs are frequently pasted into support tickets, so a leaked `sk_live_…`
	 * here is a real credential disclosure — not a hypothetical one.
	 *
	 * @param array $context Context data.
	 * @return array
	 */
	private static function redact( array $context ) {
		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( preg_match( '/^(sk|pk|cs)_(test|live)_/', $value ) ) {
				$context[ $key ] = substr( $value, 0, 11 ) . '…';
			}
		}
		return $context;
	}
}
