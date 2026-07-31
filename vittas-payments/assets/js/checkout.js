/**
 * Opens the Vittas widget on the WooCommerce order-pay page and settles the
 * order once payment is confirmed.
 *
 * The widget's onSuccess callback is treated as a hint, not proof: it triggers
 * a server-side re-read of the session (admin-ajax → Vittas API with the secret
 * key) and only that result decides where the customer is sent. The webhook
 * remains the authoritative path and may well have settled the order first.
 */
( function ( window, document ) {
	'use strict';

	var config = window.vittasCheckout;
	if ( ! config || ! config.clientSecret ) {
		return;
	}

	var statusEl = document.getElementById( 'vittas-receipt-status' );
	var retryEl = document.getElementById( 'vittas-receipt-retry' );
	var i18n = config.i18n || {};

	function setStatus( message ) {
		if ( statusEl ) {
			statusEl.textContent = message;
		}
	}

	function showRetry( label ) {
		if ( ! retryEl ) {
			return;
		}
		if ( label ) {
			retryEl.textContent = label;
		}
		retryEl.hidden = false;
	}

	function hideRetry() {
		if ( retryEl ) {
			retryEl.hidden = true;
		}
	}

	/**
	 * Asks the server what actually happened to this session.
	 *
	 * @return {Promise<Object>} Resolves with the server's payload.
	 */
	function confirmOnServer() {
		var body = new window.URLSearchParams();
		body.append( 'action', 'vittas_confirm_order' );
		body.append( 'nonce', config.nonce );
		body.append( 'order_id', config.orderId );
		body.append( 'order_key', config.orderKey );

		return window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( response ) {
				return response.json();
			} );
	}

	/**
	 * Confirms with a short retry window.
	 *
	 * The widget reports success as soon as the payer's transfer lands, which
	 * can be a beat before the session row is updated. Rather than dead-ending
	 * the customer, retry a few times before falling back to the "contact us"
	 * message — the webhook will settle the order either way.
	 *
	 * @param {number} attempt Current attempt, starting at 0.
	 */
	function confirmWithRetry( attempt ) {
		confirmOnServer()
			.then( function ( result ) {
				if ( result && result.success && result.data && result.data.redirect ) {
					window.location.href = result.data.redirect;
					return;
				}

				if ( attempt < 4 ) {
					window.setTimeout( function () {
						confirmWithRetry( attempt + 1 );
					}, 2000 );
					return;
				}

				setStatus( i18n.failed || 'We could not confirm your payment.' );
				showRetry( i18n.retry );
			} )
			.catch( function () {
				if ( attempt < 4 ) {
					window.setTimeout( function () {
						confirmWithRetry( attempt + 1 );
					}, 2000 );
					return;
				}
				setStatus( i18n.failed || 'We could not confirm your payment.' );
				showRetry( i18n.retry );
			} );
	}

	function openWidget() {
		if ( ! window.VittasPay || typeof window.VittasPay.init !== 'function' ) {
			setStatus( i18n.failed || 'The payment widget could not be loaded.' );
			showRetry( i18n.retry );
			return;
		}

		hideRetry();
		setStatus( i18n.opening || 'Opening secure payment…' );

		window.VittasPay.init( {
			clientSecret: config.clientSecret,
			onSuccess: function () {
				setStatus( i18n.verifying || 'Confirming your payment…' );
				confirmWithRetry( 0 );
			},
			onError: function ( error ) {
				setStatus( ( error && error.message ) || i18n.failed );
				showRetry( i18n.retry );
			},
			onCancel: function () {
				setStatus( i18n.cancelled || 'Payment cancelled.' );
				showRetry( i18n.retry );
			},
		} );
	}

	if ( retryEl ) {
		retryEl.addEventListener( 'click', function () {
			// A cancelled or expired session cannot be reopened, so every retry
			// goes back through the order-pay page, which mints a fresh one.
			window.location.href = config.retryUrl;
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', openWidget );
	} else {
		openWidget();
	}
} )( window, document );
