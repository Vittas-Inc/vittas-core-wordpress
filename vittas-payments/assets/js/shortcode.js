/**
 * Drives [vittas_pay] buttons.
 *
 * The button never decides what to charge — it posts a server-signed token to
 * admin-ajax, and the server derives the amount from that token.
 */
( function ( window, document ) {
	'use strict';

	var config = window.vittasPay;
	if ( ! config ) {
		return;
	}

	var i18n = config.i18n || {};

	function post( action, fields ) {
		var body = new window.URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

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

	function setup( container ) {
		var button = container.querySelector( '.vittas-pay__button' );
		var status = container.querySelector( '.vittas-pay__status' );
		var amountInput = container.querySelector( '.vittas-pay__amount' );
		var emailInput = container.querySelector( '.vittas-pay__email' );

		if ( ! button ) {
			return;
		}

		function say( message, tone ) {
			if ( ! status ) {
				return;
			}
			status.textContent = message || '';
			status.className = 'vittas-pay__status' + ( tone ? ' is-' + tone : '' );
		}

		function busy( isBusy ) {
			button.disabled = isBusy;
			container.classList.toggle( 'is-busy', isBusy );
		}

		/**
		 * Confirms server-side, retrying briefly while the session settles.
		 *
		 * @param {string} sessionId Session id.
		 * @param {string} token     Server-issued proof that this browser started the session.
		 * @param {number} attempt   Current attempt.
		 */
		function confirm( sessionId, token, attempt ) {
			post( 'vittas_confirm_payment', { session_id: sessionId, token: token } )
				.then( function ( result ) {
					var settled = result && result.success && result.data && 'successful' === result.data.status;

					if ( settled ) {
						if ( container.dataset.redirect ) {
							window.location.href = container.dataset.redirect;
							return;
						}
						say( i18n.success, 'success' );
						busy( false );
						return;
					}

					if ( attempt < 4 ) {
						window.setTimeout( function () {
							confirm( sessionId, token, attempt + 1 );
						}, 2000 );
						return;
					}

					// The webhook still settles this; the payer just does not get
					// instant confirmation here.
					say( i18n.unconfirmed, 'success' );
					busy( false );
				} )
				.catch( function () {
					say( i18n.unconfirmed, 'success' );
					busy( false );
				} );
		}

		button.addEventListener( 'click', function () {
			if ( ! window.VittasPay || typeof window.VittasPay.init !== 'function' ) {
				say( i18n.failed, 'error' );
				return;
			}

			var fields = { charge: container.dataset.charge };

			if ( '1' === container.dataset.openAmount ) {
				var typed = amountInput ? amountInput.value.trim() : '';
				if ( ! typed || isNaN( parseFloat( typed ) ) ) {
					say( i18n.badAmount, 'error' );
					if ( amountInput ) {
						amountInput.focus();
					}
					return;
				}
				fields.amount = typed;
			}

			if ( emailInput && emailInput.value.trim() ) {
				fields.email = emailInput.value.trim();
			}

			busy( true );
			say( i18n.preparing );

			post( 'vittas_create_session', fields )
				.then( function ( result ) {
					if ( ! result || ! result.success || ! result.data || ! result.data.clientSecret ) {
						say( ( result && result.data && result.data.message ) || i18n.failed, 'error' );
						busy( false );
						return;
					}

					var sessionId = result.data.sessionId;
					var confirmToken = result.data.confirmToken;

					window.VittasPay.init( {
						clientSecret: result.data.clientSecret,
						onSuccess: function () {
							say( i18n.verifying );
							confirm( sessionId, confirmToken, 0 );
						},
						onError: function ( error ) {
							say( ( error && error.message ) || i18n.failed, 'error' );
							busy( false );
						},
						onCancel: function () {
							say( i18n.cancelled );
							busy( false );
						},
					} );
				} )
				.catch( function () {
					say( i18n.failed, 'error' );
					busy( false );
				} );
		} );
	}

	function boot() {
		var containers = document.querySelectorAll( '.vittas-pay' );
		Array.prototype.forEach.call( containers, setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window, document );
