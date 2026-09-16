/**
 * "نرخ خودکار" product tab: the on/off switch and the one-line summaries on
 * the collapsible sections, so a closed section still says what is inside.
 */
jQuery( function ( $ ) {
	'use strict';

	var $panel = $( '#goldmate_product_data' );

	if ( ! $panel.length ) {
		return;
	}

	function number( selector ) {
		var value = parseFloat( String( $( selector ).val() || '' ).replace( /,/g, '' ) );
		return isFinite( value ) && value > 0 ? value : 0;
	}

	function money( value ) {
		return Math.round( value ).toLocaleString( 'en-US' ) + ' تومان';
	}

	var SUMMARIES = {
		extras: function () {
			var parts = [];
			if ( number( '#_goldmate_stone' ) ) {
				parts.push( 'سنگ ' + money( number( '#_goldmate_stone' ) ) );
			}
			if ( number( '#_goldmate_leather' ) ) {
				parts.push( 'چرم ' + money( number( '#_goldmate_leather' ) ) );
			}
			return parts.length ? parts.join( ' · ' ) : 'ندارد';
		},

		discount: function () {
			var active = [];
			$panel.find( '.goldmate-discount-table tbody tr' ).each( function () {
				var amount = parseFloat( $( this ).find( 'input[type=number]' ).val() );
				if ( amount > 0 ) {
					active.push( $.trim( $( this ).find( 'td' ).first().text() ) );
				}
			} );
			if ( ! active.length ) {
				return 'خاموش';
			}
			var text = active.join( '، ' );
			if ( $( '#_goldmate_discounts_to' ).val() ) {
				text += ' — تا ' + $( '#_goldmate_discounts_to' ).val();
			}
			return text;
		},

		advanced: function () {
			var parts = [];
			if ( $( '#_goldmate_formula' ).val() ) {
				parts.push( 'فرمول اختصاصی' );
			}
			if ( '' !== $.trim( $( '#_goldmate_profit_pct' ).val() || '' ) ) {
				parts.push( 'سود ' + $( '#_goldmate_profit_pct' ).val() + '٪' );
			}
			if ( $( '#_goldmate_tax_exempt' ).is( ':checked' ) ) {
				parts.push( 'معاف از مالیات' );
			}
			return parts.length ? parts.join( ' · ' ) : 'پیش‌فرض فروشگاه';
		},

		accessories: function () {
			var count = 0;
			$( '#goldmate-accessory-groups > .goldmate-accessory-group' ).each( function () {
				var ids = $( this ).find( 'select.wc-product-search' ).val();
				if ( ids && ids.length ) {
					count++;
				}
			} );
			return count ? count + ' گروه' : 'ندارد';
		}
	};

	function syncSummaries() {
		$panel.find( '.goldmate-section' ).each( function () {
			var build = SUMMARIES[ $( this ).data( 'section' ) ];
			if ( build ) {
				$( this ).find( '> summary .goldmate-section-summary' ).text( build() );
			}
		} );
	}

	// With automatic pricing on, the manual price fields would only be overwritten.
	function syncEnabled() {
		var on = $( '#_goldmate_enabled' ).is( ':checked' );
		$panel.find( '.goldmate-when-on' ).toggle( on );
		$( '._regular_price_field, ._sale_price_field' ).closest( '.options_group' ).toggle( ! on );
		$( '._regular_price_field, ._sale_price_field' ).toggle( ! on );
	}

	/* Variation table ------------------------------------------------- */

	var PARENT_FIELDS = {
		wage_pct: '#_goldmate_wage_pct',
		wage_fixed: '#_goldmate_wage_fixed',
		stone: '#_goldmate_stone',
		leather: '#_goldmate_leather'
	};

	// Empty override fields show what the variation inherits.
	function syncInherited() {
		$.each( PARENT_FIELDS, function ( key, selector ) {
			var value = $.trim( $( selector ).val() || '' );
			$panel.find( 'input[data-inherit="' + key + '"]' ).attr( 'placeholder', value !== '' ? value : '0' );
		} );
	}

	function syncExcluded() {
		$panel.find( '.goldmate-var-row' ).each( function () {
			$( this ).toggleClass( 'is-excluded', $( this ).find( 'input[type=checkbox]' ).is( ':checked' ) );
		} );
	}

	$panel.on( 'click', '.goldmate-var-more', function ( e ) {
		e.preventDefault();
		var id = $( this ).closest( 'tr' ).attr( 'data-id' );
		var $extra = $panel.find( '.goldmate-var-extra[data-id="' + id + '"]' );
		var open = $extra.prop( 'hidden' );
		$extra.prop( 'hidden', ! open );
		$( this ).attr( 'aria-expanded', open ? 'true' : 'false' );
	} );

	/**
	 * Rebuilds the table after variations were added, removed or saved, keeping
	 * whatever was typed into it but not saved yet.
	 */
	function reloadTable() {
		var $wrap = $panel.find( '.goldmate-var-table-wrap' );

		if ( ! $wrap.length || typeof goldmateProductPreview === 'undefined' ) {
			return;
		}

		var typed = {};
		var opened = {};

		$wrap.find( ':input[name]' ).each( function () {
			typed[ this.name ] = this.type === 'checkbox' ? this.checked : $( this ).val();
		} );
		$wrap.find( '.goldmate-var-extra' ).each( function () {
			opened[ $( this ).attr( 'data-id' ) ] = ! $( this ).prop( 'hidden' );
		} );

		$.post( goldmateProductPreview.ajaxUrl, {
			action: 'goldmate_variation_table',
			nonce: goldmateProductPreview.nonce,
			post_id: $( '#post_ID' ).val()
		} ).done( function ( payload ) {
			if ( ! payload || ! payload.success ) {
				return;
			}

			var $fresh = $( '<div>' ).html( payload.data.html ).children( '.goldmate-var-table-wrap' );

			$fresh.find( ':input[name]' ).each( function () {
				if ( ! Object.prototype.hasOwnProperty.call( typed, this.name ) ) {
					return;
				}
				if ( this.type === 'checkbox' ) {
					this.checked = typed[ this.name ];
				} else {
					$( this ).val( typed[ this.name ] );
				}
			} );
			// Keep the last prices showing until the preview re-prices the rows.
			$fresh.find( '[data-var-price]' ).each( function () {
				var old = $wrap.find( '[data-var-price="' + $( this ).attr( 'data-var-price' ) + '"]' ).text();
				if ( old ) {
					$( this ).text( old );
				}
			} );
			$fresh.find( '.goldmate-var-extra' ).each( function () {
				var id = $( this ).attr( 'data-id' );
				if ( Object.prototype.hasOwnProperty.call( opened, id ) ) {
					$( this ).prop( 'hidden', ! opened[ id ] );
				}
			} );

			$wrap.replaceWith( $fresh );
			syncInherited();
			syncExcluded();
			$panel.trigger( 'goldmate:variations-reloaded' );
		} );
	}

	$( '#woocommerce-product-data' ).on(
		'woocommerce_variations_added woocommerce_variations_removed woocommerce_variations_saved',
		reloadTable
	);

	$panel.on( 'input change', ':input', syncSummaries );
	$panel.on( 'input change', '#_goldmate_wage_pct, #_goldmate_wage_fixed, #_goldmate_stone, #_goldmate_leather', syncInherited );
	$panel.on( 'change', '.goldmate-var-row input[type=checkbox]', syncExcluded );
	$panel.on( 'change', '#_goldmate_enabled', syncEnabled );
	$( document.body ).on( 'woocommerce-product-type-change', syncEnabled );
	$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', syncEnabled );

	syncEnabled();
	syncSummaries();
	syncInherited();
} );
