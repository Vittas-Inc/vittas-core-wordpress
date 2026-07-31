/**
 * Registers Vittas on the WooCommerce Checkout block.
 *
 * Deliberately dependency-free (no JSX, no build step): it uses the globals
 * WooCommerce already ships so the plugin stays installable as plain PHP.
 *
 * Vittas is a redirect gateway — the block checkout posts to the same
 * process_payment() as classic checkout and follows the redirect it returns,
 * so there is nothing to collect here beyond the label and description.
 */
( function ( window ) {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if ( ! registry || ! settingsApi || ! element ) {
		return;
	}

	var settings = settingsApi.getSetting( 'vittas_data', {} );
	var decode = htmlEntities ? htmlEntities.decodeEntities : function ( value ) {
		return value;
	};

	var label = decode( settings.title || 'Bank Transfer (Vittas)' );

	function Description() {
		return element.createElement(
			'div',
			{ className: 'vittas-blocks-description' },
			decode( settings.description || '' )
		);
	}

	registry.registerPaymentMethod( {
		name: 'vittas',
		label: label,
		ariaLabel: label,
		content: element.createElement( Description, null ),
		edit: element.createElement( Description, null ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window );
