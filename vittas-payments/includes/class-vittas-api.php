<?php
/**
 * HTTP client for the Vittas widget API.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the two server-to-server endpoints the plugin needs.
 *
 * Every call here is made with the merchant's secret key and must therefore
 * stay on the server — no method in this class may be reachable from an
 * unauthenticated front-end request without an amount the server controls.
 */
class Vittas_API {

	/**
	 * Creates a payment session and returns its id plus client secret.
	 *
	 * @param array $args {
	 *     Session arguments.
	 *
	 *     @type int    $amount      Amount in kobo. Required, minimum 100.
	 *     @type string $currency    ISO 4217 code. Required.
	 *     @type string $reference   Merchant reference. Optional.
	 *     @type string $payer_name  Optional.
	 *     @type string $payer_email Optional.
	 *     @type string $payer_phone Optional.
	 *     @type array  $metadata    Optional. Echoed back in webhooks.
	 * }
	 * @return array|WP_Error Array with `sessionId` and `clientSecret`.
	 */
	public static function create_session( array $args ) {
		$amount = isset( $args['amount'] ) ? (int) $args['amount'] : 0;
		if ( $amount < 100 ) {
			return new WP_Error(
				'vittas_invalid_amount',
				__( 'The payment amount must be at least 100 kobo (₦1.00).', 'vittas-payments' )
			);
		}

		$body = array(
			'amount'   => $amount,
			'currency' => isset( $args['currency'] ) ? strtoupper( $args['currency'] ) : 'NGN',
		);

		$optional = array(
			'reference'   => 'reference',
			'payer_name'  => 'payerName',
			'payer_email' => 'payerEmail',
			'payer_phone' => 'payerPhone',
		);
		foreach ( $optional as $local => $remote ) {
			if ( ! empty( $args[ $local ] ) ) {
				$body[ $remote ] = $args[ $local ];
			}
		}

		if ( ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ) {
			$body['metadata'] = $args['metadata'];
		}

		return self::request( 'POST', '/api/widget/sessions', $body );
	}

	/**
	 * Fetches the authoritative status of a session.
	 *
	 * This is the only trustworthy confirmation available at redirect time —
	 * the widget's onSuccess callback runs in the payer's browser and must
	 * never be the sole basis for marking an order paid.
	 *
	 * @param string $session_id Session id.
	 * @return array|WP_Error Array with id, status, reference, amount, paidAmount, currency.
	 */
	public static function get_session( $session_id ) {
		$session_id = trim( (string) $session_id );
		if ( '' === $session_id ) {
			return new WP_Error( 'vittas_missing_session', __( 'No payment session id was supplied.', 'vittas-payments' ) );
		}
		return self::request( 'GET', '/api/widget/sessions/' . rawurlencode( $session_id ) );
	}

	/**
	 * Performs a signed request and unwraps the API response envelope.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with a slash.
	 * @param array|null $body   Optional JSON body.
	 * @return array|WP_Error
	 */
	private static function request( $method, $path, $body = null ) {
		$secret = Vittas_Settings::secret_key();
		if ( '' === $secret ) {
			return new WP_Error(
				'vittas_not_configured',
				__( 'Vittas Payments is not configured — add your secret key in Vittas Payments → Settings.', 'vittas-payments' )
			);
		}

		$url  = Vittas_Settings::api_base() . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'VittasPayments-WordPress/' . VITTAS_PAYMENTS_VERSION . '; ' . home_url(),
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		Vittas_Logger::debug( "API {$method} {$path}", array( 'body' => $body ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Vittas_Logger::error( "API {$method} {$path} transport error", array( 'error' => $response->get_error_message() ) );
			return new WP_Error(
				'vittas_http_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach Vittas: %s', 'vittas-payments' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			Vittas_Logger::error( "API {$method} {$path} returned non-JSON", array( 'status' => $status, 'body' => substr( $raw, 0, 500 ) ) );
			return new WP_Error(
				'vittas_bad_response',
				__( 'Vittas returned an unreadable response. Please try again.', 'vittas-payments' )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = '';
			if ( isset( $json['meta']['message'] ) && is_string( $json['meta']['message'] ) ) {
				$message = $json['meta']['message'];
			} elseif ( isset( $json['message'] ) ) {
				$message = is_array( $json['message'] ) ? implode( ' ', $json['message'] ) : (string) $json['message'];
			}
			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Vittas returned HTTP %d.', 'vittas-payments' ),
					$status
				);
			}
			Vittas_Logger::error( "API {$method} {$path} failed", array( 'status' => $status, 'message' => $message ) );
			return new WP_Error( 'vittas_api_error', $message, array( 'status' => $status ) );
		}

		if ( ! isset( $json['data']['result'] ) ) {
			return new WP_Error( 'vittas_bad_response', __( 'Vittas returned an unexpected response shape.', 'vittas-payments' ) );
		}

		return $json['data']['result'];
	}
}
