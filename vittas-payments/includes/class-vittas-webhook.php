<?php
/**
 * Inbound webhook receiver.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes POST /wp-json/vittas/v1/webhook and verifies its signature.
 *
 * This is the authoritative confirmation channel. The widget's onSuccess
 * callback fires in the payer's browser and can be forged, replayed or simply
 * never reached (closed tab, dead battery) — the webhook is what actually
 * settles an order.
 */
class Vittas_Webhook {

	const REST_NAMESPACE = 'vittas/v1';
	const ROUTE          = '/webhook';

	/** Reject deliveries whose timestamp is further away than this, in seconds. */
	const TOLERANCE = 300;

	/**
	 * Hooks the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the webhook route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Authentication is the HMAC signature, checked inside the
				// callback against the raw body. There is no WordPress user here.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles a delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body      = $request->get_body();
		$signature = $request->get_header( 'x_vittas_signature' );
		$delivery  = (string) $request->get_header( 'x_vittas_delivery' );

		if ( ! self::verify( $body, (string) $signature ) ) {
			Vittas_Logger::error( 'Rejected webhook with an invalid signature', array( 'delivery' => $delivery ) );
			// 401 tells Vittas the delivery failed so it will retry — which is
			// what a merchant who has just pasted the wrong signing secret wants.
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['event'] ) || ! isset( $payload['data'] ) || ! is_array( $payload['data'] ) ) {
			return new WP_REST_Response( array( 'error' => 'malformed payload' ), 400 );
		}

		$event = (string) $payload['event'];
		$data  = $payload['data'];

		Vittas_Logger::debug(
			'Webhook received',
			array(
				'event'     => $event,
				'delivery'  => $delivery,
				'sessionId' => isset( $data['sessionId'] ) ? $data['sessionId'] : null,
				'reference' => isset( $data['reference'] ) ? $data['reference'] : null,
			)
		);

		/**
		 * Fires for every verified Vittas webhook, before order handling.
		 *
		 * Non-WooCommerce sites (shortcode payments) hook this to fulfil.
		 *
		 * @param string $event Event type, e.g. payment.successful.
		 * @param array  $data  Session payload.
		 */
		do_action( 'vittas_webhook_received', $event, $data );

		$status = self::status_for_event( $event, $data );
		if ( null === $status ) {
			// Unknown event types are acknowledged so Vittas stops retrying.
			return new WP_REST_Response( array( 'received' => true, 'handled' => false ), 200 );
		}

		$order = Vittas_Order_Handler::locate( $data );
		if ( ! $order ) {
			// A shortcode payment, or an order from another site on the same
			// merchant account. Already broadcast above for listeners.
			return new WP_REST_Response( array( 'received' => true, 'handled' => false ), 200 );
		}

		if ( ! Vittas_Order_Handler::claim_delivery( $order, $delivery ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$changed = Vittas_Order_Handler::apply( $order, $status, $data, 'webhook' );

		return new WP_REST_Response(
			array(
				'received' => true,
				'handled'  => true,
				'changed'  => $changed,
				'order'    => $order->get_id(),
			),
			200
		);
	}

	/**
	 * Verifies the `X-Vittas-Signature` header against the raw request body.
	 *
	 * Header format is `t=<unix seconds>,v1=<hex hmac-sha256 of "t.body">`.
	 *
	 * @param string $body   Raw request body, byte for byte as received.
	 * @param string $header Signature header.
	 * @return bool
	 */
	private static function verify( $body, $header ) {
		$secrets = Vittas_Settings::webhook_secrets();
		if ( empty( $secrets ) || '' === $header ) {
			return false;
		}

		$timestamp = '';
		$provided  = '';
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$provided = $pair[1];
			}
		}

		if ( '' === $timestamp || '' === $provided || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		// Bounds the replay window for a captured delivery.
		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			Vittas_Logger::error( 'Rejected webhook outside the timestamp tolerance', array( 'skew' => time() - (int) $timestamp ) );
			return false;
		}

		$signed = $timestamp . '.' . $body;
		foreach ( $secrets as $secret ) {
			if ( hash_equals( hash_hmac( 'sha256', $signed, $secret ), $provided ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Maps a Vittas event type to a session status.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Session payload.
	 * @return string|null Null when the event is not one the plugin acts on.
	 */
	private static function status_for_event( $event, array $data ) {
		switch ( $event ) {
			case 'payment.successful':
				return 'successful';
			case 'payment.mismatch':
				return 'mismatch';
			case 'payment.expired':
				return 'expired';
			case 'payment.cancelled':
				return 'cancelled';
		}

		// Fall back to the status carried in the payload so a newly added event
		// type still settles correctly instead of being dropped.
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'successful', 'mismatch', 'expired', 'cancelled', 'failed' ), true ) ) {
			return (string) $data['status'];
		}

		return null;
	}
}
