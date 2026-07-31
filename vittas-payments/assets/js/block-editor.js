/**
 * Editor UI for the Vittas Pay Button block.
 *
 * No JSX and no build step — the block is defined with wp.element.createElement
 * against the script handles WordPress already ships, so the plugin installs as
 * plain PHP with no npm artefacts to keep in sync.
 *
 * The preview is a ServerSideRender of the same PHP callback the front end
 * uses, so what the author sees is what the shortcode renders.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'vittas/pay-button', {
		apiVersion: 2,
		title: __( 'Vittas Pay Button', 'vittas-payments' ),
		description: __( 'A bank transfer payment button powered by Vittas.', 'vittas-payments' ),
		icon: 'money-alt',
		category: 'widgets',
		keywords: [ __( 'payment', 'vittas-payments' ), __( 'donate', 'vittas-payments' ), 'vittas' ],

		attributes: {
			amount: { type: 'string', default: '0' },
			currency: { type: 'string', default: 'NGN' },
			label: { type: 'string', default: '' },
			description: { type: 'string', default: '' },
			reference: { type: 'string', default: '' },
			redirect: { type: 'string', default: '' },
			min: { type: 'string', default: '100' },
			max: { type: 'string', default: '' },
		},

		edit: function ( props ) {
			var attributes = props.attributes;

			function field( key, label, help ) {
				return el( TextControl, {
					key: key,
					label: label,
					help: help,
					value: attributes[ key ],
					onChange: function ( value ) {
						var update = {};
						update[ key ] = value;
						props.setAttributes( update );
					},
				} );
			}

			var isOpenAmount = ! parseFloat( attributes.amount );

			var controls = el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Payment', 'vittas-payments' ), initialOpen: true },
					field( 'amount', __( 'Amount', 'vittas-payments' ), __( 'In naira, not kobo. Leave at 0 to let the payer choose.', 'vittas-payments' ) ),
					isOpenAmount ? field( 'min', __( 'Minimum amount', 'vittas-payments' ) ) : null,
					isOpenAmount ? field( 'max', __( 'Maximum amount', 'vittas-payments' ), __( 'Leave blank for no ceiling.', 'vittas-payments' ) ) : null,
					field( 'currency', __( 'Currency', 'vittas-payments' ) ),
					field( 'reference', __( 'Reference prefix', 'vittas-payments' ), __( 'Prefixes the reference shown in your Vittas dashboard.', 'vittas-payments' ) )
				),
				el(
					PanelBody,
					{ title: __( 'Appearance', 'vittas-payments' ), initialOpen: false },
					field( 'label', __( 'Button text', 'vittas-payments' ) ),
					field( 'description', __( 'Description', 'vittas-payments' ) ),
					field( 'redirect', __( 'Redirect after payment', 'vittas-payments' ), __( 'Optional URL to send the payer to once payment is confirmed.', 'vittas-payments' ) )
				)
			);

			var preview = ServerSideRender
				? el( ServerSideRender, {
						key: 'preview',
						block: 'vittas/pay-button',
						attributes: attributes,
				  } )
				: el( 'p', { key: 'preview' }, __( 'Vittas Pay Button', 'vittas-payments' ) );

			return el(
				'div',
				wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {},
				[ controls, preview ]
			);
		},

		// Rendered by PHP on the front end.
		save: function () {
			return null;
		},
	} );
} )( window.wp );
