/**
 * Polls GoldMate for the current gold rate and refreshes banners / boards / product totals.
 * Supports Woodmart single-price layouts, selected variations, and goldmate-priced loop cards.
 */
( function () {
	'use strict';

	if ( typeof goldmateLive === 'undefined' || ! goldmateLive.ajaxUrl ) {
		return;
	}

	var interval = parseInt( goldmateLive.interval, 10 ) || 60000;
	var productId = parseInt( goldmateLive.productId, 10 ) || 0;
	var isVariable = !! goldmateLive.isVariable;
	var items = Array.isArray( goldmateLive.items ) ? goldmateLive.items.slice() : [];
	var variationId = 0;
	var lastRate = null;

	function collectBoardItems() {
		document.querySelectorAll( '[data-goldmate-board][data-goldmate-item]' ).forEach( function ( el ) {
			var slug = el.getAttribute( 'data-goldmate-item' );
			if ( slug && items.indexOf( slug ) === -1 ) {
				items.push( slug );
			}
		} );
	}

	/**
	 * Price nodes Woodmart / classic WC / explicit hooks may use on a single product.
	 *
	 * @return {Element[]}
	 */
	function findSinglePriceNodes() {
		var hooked = document.querySelectorAll( '[data-goldmate-live-price]' );
		if ( hooked.length ) {
			return Array.prototype.slice.call( hooked );
		}

		var selectors = [
			'.wd-single-price .price:not(.price-unit)',
			'.single-product-page .wd-single-price .price:not(.price-unit)',
			'.summary-inner > .price:not(.price-unit)',
			'.product .summary p.price',
			'.product .summary .price:not(.price-unit)',
			'.summary > p.price',
			'.summary > .price',
			'p.price.goldmate-live-target',
			'.price.goldmate-live-target'
		];

		var found = [];
		var seen = [];

		selectors.forEach( function ( sel ) {
			document.querySelectorAll( sel ).forEach( function ( el ) {
				// Skip loop / related / upsell cards on the same page.
				if ( el.closest( '.related, .upsells, .products, .wd-carousel, .wd-products' ) ) {
					return;
				}
				if ( seen.indexOf( el ) !== -1 ) {
					return;
				}
				seen.push( el );
				found.push( el );
			} );
		} );

		return found;
	}

	/**
	 * Write a price HTML string into a WC/Woodmart price node without destroying the wrapper.
	 *
	 * @param {Element} el
	 * @param {string} html
	 */
	function setPriceHtml( el, html ) {
		if ( ! el || ! html ) {
			return;
		}
		// If theme stored a full <p class="price"> / <span class="price">, keep the outer node.
		if ( /\bprice\b/.test( el.className || '' ) ) {
			el.innerHTML = html;
			return;
		}
		el.innerHTML = html;
	}

	function request( extra ) {
		var url = goldmateLive.ajaxUrl + '?action=goldmate_live_rate';
		extra = extra || {};

		if ( extra.product_ids && extra.product_ids.length ) {
			url += '&product_ids=' + encodeURIComponent( extra.product_ids.join( ',' ) );
		} else if ( productId > 0 && ! extra.item ) {
			url += '&product_id=' + encodeURIComponent( productId );
			var vid = extra.variation_id != null ? extra.variation_id : variationId;
			if ( vid > 0 ) {
				url += '&variation_id=' + encodeURIComponent( vid );
			}
		}

		if ( extra.item ) {
			url += '&item=' + encodeURIComponent( extra.item );
		}

		var xhr = new XMLHttpRequest();
		xhr.open( 'GET', url, true );
		xhr.onload = function () {
			if ( xhr.status !== 200 ) {
				return;
			}
			try {
				var payload = JSON.parse( xhr.responseText );
				if ( ! payload || ! payload.success || ! payload.data ) {
					return;
				}
				apply( payload.data, extra );
			} catch ( e ) {
				// Ignore malformed responses.
			}
		};
		xhr.send();
	}

	function collectArchiveProductIds() {
		var ids = [];
		document.querySelectorAll( '.goldmate-priced' ).forEach( function ( el ) {
			var id =
				el.getAttribute( 'data-product_id' ) ||
				el.getAttribute( 'data-id' ) ||
				el.getAttribute( 'data-product-id' ) ||
				'';
			if ( ! id ) {
				var m = ( el.className || '' ).match( /(?:^|\s)post-(\d+)(?:\s|$)/ );
				if ( m ) {
					id = m[ 1 ];
				}
			}
			id = parseInt( id, 10 ) || 0;
			if ( id > 0 && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		} );
		document.querySelectorAll( '[data-goldmate-live-price][data-goldmate-product-id]' ).forEach( function ( el ) {
			var id = parseInt( el.getAttribute( 'data-goldmate-product-id' ), 10 ) || 0;
			if ( id > 0 && ids.indexOf( id ) === -1 ) {
				ids.push( id );
			}
		} );
		return ids.slice( 0, 24 );
	}

	function pollAll() {
		collectBoardItems();
		request( {} );
		items.forEach( function ( slug ) {
			if ( slug ) {
				request( { item: slug } );
			}
		} );

		// Shop / category cards: refresh when we are not on a single product poll.
		if ( ! productId ) {
			var archiveIds = collectArchiveProductIds();
			if ( archiveIds.length ) {
				request( { product_ids: archiveIds } );
			}
		}
	}

	function apply( data, extra ) {
		extra = extra || {};

		if ( extra.item ) {
			updateBoards( data, extra.item );
			return;
		}

		if ( window.goldmatePrice ) {
			window.goldmatePrice.rate = data.rate;
			window.goldmatePrice.rate_html = data.rate_html || '';
			window.goldmatePrice.applied_rate = data.applied_rate;
			window.goldmatePrice.change_pct = data.change_pct;
			window.goldmatePrice.updated_human = data.updated_human;
		} else {
			window.goldmatePrice = {
				rate: data.rate,
				rate_html: data.rate_html || '',
				applied_rate: data.applied_rate,
				change_pct: data.change_pct,
				updated_human: data.updated_human
			};
		}
		window.goldmateSimpleProduct = window.goldmatePrice;

		updateBanners( data );
		updateBoards( data, '' );

		if ( data.products ) {
			updateArchivePrices( data.products );
		}

		var rateChanged = lastRate === null || Number( lastRate ) !== Number( data.rate );
		lastRate = data.rate;

		if ( data.live_total_html ) {
			updateProductPrice( data );
		}
		// Keep variation JSON rate fields in sync for the theme gold-price line.
		patchSelectedVariation( data );

		if ( data.breakdown_html ) {
			updateBreakdown( data );
		}

		try {
			window.dispatchEvent(
				new CustomEvent( 'goldmate:rate', {
					detail: Object.assign( {}, data, { rateChanged: rateChanged } )
				} )
			);
		} catch ( e2 ) {
			// Older browsers.
		}
	}

	function updateBreakdown( data ) {
		// Variable: only refresh when a variation is selected (payload is for that variation).
		if ( isVariable && ! ( variationId > 0 ) ) {
			return;
		}

		var wrap = document.querySelector( '[data-goldmate-wrap]' );
		if ( wrap ) {
			wrap.innerHTML = data.breakdown_html;
			return;
		}

		if ( isVariable ) {
			return;
		}

		var block = document.querySelector( '.goldmate-breakdown' );
		if ( ! block || ! block.parentNode ) {
			return;
		}
		var holder = document.createElement( 'div' );
		holder.innerHTML = data.breakdown_html;
		var parent = block.parentNode;
		while (
			parent.firstChild &&
			parent.firstChild.classList &&
			( parent.firstChild.classList.contains( 'goldmate-breakdown' ) ||
				parent.firstChild.classList.contains( 'goldmate-formula' ) )
		) {
			parent.removeChild( parent.firstChild );
		}
		while ( holder.firstChild ) {
			parent.insertBefore( holder.firstChild, parent.firstChild );
		}
	}

	function updateBanners( data ) {
		var banners = document.querySelectorAll( '[data-goldmate-banner], .goldmate-price-banner' );
		var displayHtml = data.batch_running ? data.applied_rate_html : data.rate_html;

		banners.forEach( function ( el ) {
			el.classList.toggle( 'goldmate-price-banner--updating', !! data.batch_running );
			el.classList.toggle( 'goldmate-price-banner--stale', !! data.stale && ! data.batch_running );

			var value = el.querySelector( '.goldmate-price-banner-value' );
			if ( value && displayHtml ) {
				value.innerHTML = displayHtml;
			}

			var time = el.querySelector( '.goldmate-price-banner-time' );
			if ( time && data.updated_human && ! data.batch_running ) {
				time.textContent = 'به‌روزرسانی: ' + data.updated_human;
			}

			var dot = el.querySelector( '.goldmate-price-banner-dot' );
			if ( dot ) {
				dot.style.background = data.batch_running
					? '#fa941a'
					: data.stale
						? '#ef5350'
						: '#26a69a';
			}
		} );
	}

	function updateBoards( data, itemSlug ) {
		document.querySelectorAll( '[data-goldmate-board]' ).forEach( function ( el ) {
			var boardItem = el.getAttribute( 'data-goldmate-item' ) || '';
			if ( itemSlug && boardItem && boardItem !== itemSlug ) {
				return;
			}
			if ( itemSlug && ! boardItem ) {
				return;
			}
			if ( ! itemSlug && boardItem ) {
				return;
			}

			var rate = el.querySelector( '[data-goldmate-board-rate]' );
			if ( rate && data.rate_html ) {
				rate.innerHTML = data.rate_html;
			}
			var time = el.querySelector( '[data-goldmate-board-time]' );
			if ( time && data.updated_human ) {
				time.textContent = data.updated_human;
			}
			var change = el.querySelector( '[data-goldmate-board-change]' );
			if ( change ) {
				change.textContent = data.change_pct_html || '—';
				change.setAttribute(
					'data-dir',
					data.change_pct > 0 ? 'up' : data.change_pct < 0 ? 'down' : 'flat'
				);
				if ( data.change_pct > 0 ) {
					change.style.color = '#1e7e34';
				} else if ( data.change_pct < 0 ) {
					change.style.color = '#b32d2e';
				}
			}
		} );
	}

	function updateProductPrice( data ) {
		if ( ! data.live_total_html ) {
			return;
		}
		/*
		 * Variable PDPs: Woodmart + child custom_js own the main price via
		 * variation.price_html / found_variation. Writing a live-recalculated
		 * total here races them (DB price ↔ live price) and causes flashing.
		 * Per-gram rate still updates through window.goldmatePrice + goldmate:rate.
		 */
		if ( isVariable ) {
			return;
		}
		findSinglePriceNodes().forEach( function ( el ) {
			setPriceHtml( el, data.live_total_html );
		} );
	}

	function updateArchivePrices( products ) {
		Object.keys( products ).forEach( function ( id ) {
			var row = products[ id ];
			if ( ! row || ! row.live_total_html ) {
				return;
			}
			var html = row.live_total_html;
			document
				.querySelectorAll(
					'[data-goldmate-live-price][data-goldmate-product-id="' + id + '"]'
				)
				.forEach( function ( el ) {
					setPriceHtml( el, html );
				} );
			document.querySelectorAll( '.goldmate-priced' ).forEach( function ( card ) {
				var cid =
					card.getAttribute( 'data-product_id' ) ||
					card.getAttribute( 'data-id' ) ||
					'';
				if ( ! cid ) {
					var m = ( card.className || '' ).match( /(?:^|\s)post-(\d+)(?:\s|$)/ );
					if ( m ) {
						cid = m[ 1 ];
					}
				}
				if ( String( cid ) !== String( id ) ) {
					return;
				}
				var price = card.querySelector( '.price:not(.price-unit), p.price, span.price' );
				if ( price ) {
					setPriceHtml( price, html );
				}
			} );
		} );
	}

	/**
	 * Keep WooCommerce / Woodmart variation JSON rate fields in sync.
	 * Do not rewrite price_html on variable products — that belongs to the
	 * catalogue / theme and fighting it causes visible price flashing.
	 *
	 * @param {object} data
	 */
	function patchSelectedVariation( data ) {
		var vid = data.variation_id || variationId;
		if ( ! window.jQuery ) {
			return;
		}
		var $ = window.jQuery;
		$( '.variations_form' ).each( function () {
			var $form = $( this );
			var variations = $form.data( 'product_variations' );
			if ( ! variations || ! variations.length ) {
				return;
			}
			var changed = false;
			variations.forEach( function ( row ) {
				if ( vid && parseInt( row.variation_id, 10 ) !== parseInt( vid, 10 ) ) {
					return;
				}
				if ( data.rate != null ) {
					row.goldmate_rate_18 = data.rate;
					row.goldmate_rate_18_html = data.rate_html || '';
					changed = true;
				}
				if ( ! isVariable && data.live_total_html ) {
					row.price_html = '<span class="price">' + data.live_total_html + '</span>';
					changed = true;
				}
				if ( ! isVariable && data.breakdown_html != null ) {
					row.goldmate_breakdown_html = data.breakdown_html;
					changed = true;
				}
			} );
			if ( changed ) {
				$form.data( 'product_variations', variations );
			}
		} );
	}

	function bindVariationTracking() {
		if ( ! isVariable || ! window.jQuery ) {
			return;
		}
		var $ = window.jQuery;
		$( function () {
			$( document.body )
				.on( 'show_variation', '.variations_form', function ( event, variation ) {
					variationId = variation && variation.variation_id ? parseInt( variation.variation_id, 10 ) : 0;
					// Intentionally no live total re-fetch: theme price_html is the display source.
				} )
				.on( 'hide_variation reset_data', '.variations_form', function () {
					variationId = 0;
				} );

			$( '.variations_form' ).each( function () {
				var $form = $( this );
				var current = $form.find( 'input[name="variation_id"]' ).val();
				current = parseInt( current, 10 ) || 0;
				if ( current > 0 ) {
					variationId = current;
				}
			} );
		} );
	}

	bindVariationTracking();
	pollAll();
	setInterval( pollAll, interval );
} )();
