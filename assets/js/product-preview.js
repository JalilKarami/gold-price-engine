/**
 * Live price preview on the product edit screen.
 *
 * Posts the gold tab's unsaved fields to Goldmate_Product_Preview and paints
 * the returned price into its `.goldmate-preview` box. All price
 * rules live in PHP; this file only collects values and writes HTML back.
 */
jQuery( function ( $ ) {
	'use strict';

	if ( typeof goldmateProductPreview === 'undefined' ) {
		return;
	}

	var cfg = goldmateProductPreview;
	var $boxes = $( '.goldmate-preview' );
	var PANEL = '#goldmate_product_data';
	// Accessory groups do not change the price.
	var IGNORE = '.goldmate-accessory-admin :input';
	var timer = null;
	var pending = null;

	if ( ! $boxes.length ) {
		return;
	}

	function enabled() {
		return $( '#_goldmate_enabled' ).is( ':checked' );
	}

	function productType() {
		return $( '#product-type' ).val() || 'simple';
	}

	function paint( total, html, prices ) {
		$boxes.removeClass( 'is-calculating' );
		$boxes.find( '[data-preview="total"]' ).text( total );
		$boxes.find( '[data-preview="body"]' ).html( html );

		// Variable products: one price per row of the variation table.
		$( PANEL ).find( '[data-var-price]' ).each( function () {
			var id = $( this ).attr( 'data-var-price' );
			$( this ).text( prices && prices[ id ] ? prices[ id ] : '—' );
		} );
		$( PANEL ).find( '.goldmate-var-table' ).removeClass( 'is-calculating' );
	}

	function message( text ) {
		paint( '—', $( '<p class="goldmate-preview-message">' ).text( text ) );
	}

	function calculate() {
		if ( pending ) {
			pending.abort();
		}

		var data = $( PANEL ).find( ':input' ).not( IGNORE ).serializeArray();

		data.push(
			{ name: 'action', value: 'goldmate_product_preview' },
			{ name: 'nonce', value: cfg.nonce },
			{ name: 'post_id', value: $( '#post_ID' ).val() },
			{ name: 'product_type', value: productType() }
		);

		pending = $.post( cfg.ajaxUrl, data )
			.done( function ( payload ) {
				if ( payload && payload.success ) {
					paint( payload.data.total, payload.data.html, payload.data.prices );
				} else {
					message( ( payload && payload.data && payload.data.message ) || '—' );
				}
			} )
			.fail( function ( xhr, status ) {
				if ( 'abort' !== status ) {
					message( '—' );
				}
			} )
			.always( function () {
				pending = null;
			} );
	}

	function refresh() {
		if ( ! enabled() ) {
			return;
		}

		// admin-ajax boots all of WordPress, so the answer can lag a second or
		// more. Dim the old numbers meanwhile: a price from stale inputs that
		// looks current is worse than one that is visibly pending.
		$boxes.addClass( 'is-calculating' );
		$( PANEL ).find( '.goldmate-var-table' ).addClass( 'is-calculating' );

		clearTimeout( timer );
		timer = setTimeout( calculate, 400 );
	}

	$( PANEL ).on( 'input change', ':input', function () {
		if ( ! $( this ).is( IGNORE ) ) {
			refresh();
		}
	} );
	$( document.body ).on( 'woocommerce-product-type-change', refresh );

	// The variation table was rebuilt (variations added, removed or saved).
	$( PANEL ).on( 'goldmate:variations-reloaded', refresh );

	refresh();
} );
