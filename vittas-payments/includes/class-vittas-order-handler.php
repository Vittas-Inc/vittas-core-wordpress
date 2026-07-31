<?php
/**
 * Applies Vittas session outcomes to WooCommerce orders.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns a session status into an order status.
 *
 * Two independent paths reach this class — the webhook (authoritative, may
 * arrive before or after the payer returns) and the browser redirect confirm
 * (which re-verifies server-side before calling in). They race by design, so
 * every method here is written to be idempotent.
 */
class Vittas_Order_Handler {

	const META_SESSION_ID    = '_vittas_session_id';
	const META_REFERENCE     = '_vittas_reference';
	const META_ENVIRONMENT   = '_vittas_environment';
	const META_DELIVERIES    = '_vittas_deliveries';
	const META_CLIENT_SECRET = '_vittas_client_secret';

	/**
	 * Applies a terminal session status to an order.
	 *
	 * @param WC_Order $order   Order to update.
	 * @param string   $status  Session status: successful, mismatch, expired, cancelled, failed.
	 * @param array    $data    Session payload (webhook `data` object or session status response).
	 * @param string   $source  Where the update came from, for the order note.
	 * @return bool True when the order was changed.
	 */
	public static function apply( $order, $status, array $data, $source = 'webhook' ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		switch ( $status ) {
			case 'successful':
				return self::mark_paid( $order, $data, $source );

			case 'mismatch':
				return self::mark_mismatch( $order, $data, $source );

			case 'expired':
			case 'cancelled':
			case 'failed':
				return self::mark_unpaid( $order, $status, $source );
		}

		return false;
	}

	/**
	 * Completes payment on an order, guarding against underpayment.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $data   Session payload.
	 * @param string   $source Update source.
	 * @return bool
	 */
	private static function mark_paid( $order, array $data, $source ) {
		if ( $order->is_paid() ) {
			Vittas_Logger::debug( 'Order already paid, ignoring duplicate success', array( 'order' => $order->get_id() ) );
			return false;
		}

		$expected = (int) round( (float) $order->get_total() * 100 );
		$paid     = self::paid_kobo( $data );

		// A "successful" session that does not cover the order total should never
		// silently release goods. Vittas allocates an EXACT-amount virtual account
		// so this is rare, but a partial credit or a fee-bearer misconfiguration
		// would land here and must stop for a human.
		if ( null !== $paid && $paid < $expected ) {
			return self::mark_mismatch( $order, $data, $source );
		}

		$transaction_id = isset( $data['sessionId'] ) ? (string) $data['sessionId'] : '';

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount paid, 2: Vittas reference, 3: update source. */
				__( 'Vittas payment received: %1$s (reference %2$s, via %3$s).', 'vittas-payments' ),
				self::format_amount( $paid, $data ),
				isset( $data['reference'] ) ? $data['reference'] : '—',
				$source
			)
		);

		$order->payment_complete( $transaction_id );

		if ( null !== $paid ) {
			$order->update_meta_data( '_vittas_paid_amount', $paid );
			$order->save();
		}

		Vittas_Logger::debug( 'Order marked paid', array( 'order' => $order->get_id(), 'source' => $source ) );

		/**
		 * Fires after a WooCommerce order is marked paid by Vittas.
		 *
		 * @param WC_Order $order Order.
		 * @param array    $data  Session payload.
		 */
		do_action( 'vittas_order_paid', $order, $data );

		return true;
	}

	/**
	 * Puts an order on hold after a wrong-amount payment.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $data   Session payload.
	 * @param string   $source Update source.
	 * @return bool
	 */
	private static function mark_mismatch( $order, array $data, $source ) {
		if ( $order->has_status( 'on-hold' ) || $order->is_paid() ) {
			return false;
		}

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: amount received, 2: amount expected, 3: update source. */
				__( 'Vittas reported an amount mismatch — received %1$s, expected %2$s. Review before fulfilling (via %3$s).', 'vittas-payments' ),
				self::format_amount( self::paid_kobo( $data ), $data ),
				wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ),
				$source
			)
		);

		Vittas_Logger::error( 'Order held on amount mismatch', array( 'order' => $order->get_id() ) );

		/**
		 * Fires when Vittas reports a payment amount that does not match the order.
		 *
		 * @param WC_Order $order Order.
		 * @param array    $data  Session payload.
		 */
		do_action( 'vittas_order_mismatch', $order, $data );

		return true;
	}

	/**
	 * Fails or cancels an order whose session ended without payment.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Session status.
	 * @param string   $source Update source.
	 * @return bool
	 */
	private static function mark_unpaid( $order, $status, $source ) {
		// Never walk back an order that has money against it, and never touch an
		// order the shop has already moved on from — the payer may simply have
		// let one session lapse and paid with a second.
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			return false;
		}

		$note = sprintf(
			/* translators: 1: session status, 2: update source. */
			__( 'Vittas payment session %1$s — no payment received (via %2$s).', 'vittas-payments' ),
			$status,
			$source
		);

		if ( 'cancelled' === $status ) {
			$order->update_status( 'cancelled', $note );
		} else {
			$order->update_status( 'failed', $note );
		}

		return true;
	}

	/**
	 * Locates the order a session belongs to.
	 *
	 * Matching is deliberately strict: the metadata we set at creation time must
	 * line up with the order's stored session id, and the payload must have come
	 * from this site. A merchant account can serve several sites, and a webhook
	 * for one of them must never mutate an order on another.
	 *
	 * @param array $data Session payload.
	 * @return WC_Order|null
	 */
	public static function locate( array $data ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$metadata   = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();
		$session_id = isset( $data['sessionId'] ) ? (string) $data['sessionId'] : '';

		if ( ! empty( $metadata['site_url'] ) && untrailingslashit( $metadata['site_url'] ) !== untrailingslashit( home_url() ) ) {
			Vittas_Logger::debug( 'Ignoring session for another site', array( 'site' => $metadata['site_url'] ) );
			return null;
		}

		if ( empty( $metadata['order_id'] ) ) {
			return null;
		}

		$order = wc_get_order( (int) $metadata['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$stored = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' !== $session_id && '' !== $stored && ! hash_equals( $stored, $session_id ) ) {
			Vittas_Logger::error(
				'Session id does not match the order it claims',
				array( 'order' => $order->get_id(), 'session' => $session_id )
			);
			return null;
		}

		return $order;
	}

	/**
	 * Records a webhook delivery id and reports whether it is new.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $delivery_id Delivery id from the X-Vittas-Delivery header.
	 * @return bool True when this delivery has not been seen before.
	 */
	public static function claim_delivery( $order, $delivery_id ) {
		if ( ! $order instanceof WC_Order || '' === $delivery_id ) {
			return true;
		}

		$seen = $order->get_meta( self::META_DELIVERIES );
		$seen = is_array( $seen ) ? $seen : array();

		if ( in_array( $delivery_id, $seen, true ) ) {
			return false;
		}

		// Keep the list bounded — a session produces a handful of events at most.
		$seen[] = $delivery_id;
		$order->update_meta_data( self::META_DELIVERIES, array_slice( $seen, -20 ) );
		$order->save();

		return true;
	}

	/**
	 * Extracts the amount actually paid, in kobo.
	 *
	 * The webhook payload calls it `amountPaid`; the session status endpoint
	 * calls it `paidAmount`.
	 *
	 * @param array $data Session payload.
	 * @return int|null
	 */
	private static function paid_kobo( array $data ) {
		foreach ( array( 'amountPaid', 'paidAmount' ) as $key ) {
			if ( isset( $data[ $key ] ) && null !== $data[ $key ] && '' !== $data[ $key ] ) {
				return (int) $data[ $key ];
			}
		}
		return null;
	}

	/**
	 * Formats a kobo amount for an order note.
	 *
	 * @param int|null $kobo Amount in kobo.
	 * @param array    $data Session payload, for the currency.
	 * @return string
	 */
	private static function format_amount( $kobo, array $data ) {
		if ( null === $kobo ) {
			return __( 'an unknown amount', 'vittas-payments' );
		}
		$currency = isset( $data['currency'] ) ? $data['currency'] : 'NGN';
		return wp_strip_all_tags( wc_price( $kobo / 100, array( 'currency' => $currency ) ) );
	}
}
