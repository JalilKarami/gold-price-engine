/**
 * Polls GoldMate for the current gold rate and refreshes banners / boards / product totals.
 */
( function () {
	'use strict';

	if ( typeof goldmateLive === 'undefined' || ! goldmateLive.ajaxUrl ) {
		return;
	}

	var interval = parseInt( goldmateLive.interval, 10 ) || 60000;
	var productId = parseInt( goldmateLive.productId, 10 ) || 0;
	var items = Array.isArray( goldmateLive.items ) ? goldmateLive.items.slice() : [];

	function collectBoardItems() {
		document.querySelectorAll( '[data-goldmate-board][data-goldmate-item]' ).forEach( function ( el ) {
			var slug = el.getAttribute( 'data-goldmate-item' );
			if ( slug && items.indexOf( slug ) === -1 ) {
				items.push( slug );
			}
		} );
	}

	function request( extra ) {
		var url = goldmateLive.ajaxUrl + '?action=goldmate_live_rate';
		if ( productId > 0 && ! ( extra && extra.item ) ) {
			url += '&product_id=' + encodeURIComponent( productId );
		}
		if ( extra && extra.item ) {
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
				apply( payload.data, extra && extra.item ? extra.item : '' );
			} catch ( e ) {
				// Ignore malformed responses.
			}
		};
		xhr.send();
	}

	function pollAll() {
		collectBoardItems();
		request( {} );
		items.forEach( function ( slug ) {
			if ( slug ) {
				request( { item: slug } );
			}
		} );
	}

	function apply( data, itemSlug ) {
		if ( ! itemSlug ) {
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

			if ( data.live_total_html ) {
				updateProductPrice( data );
			}

			if ( data.breakdown_html ) {
				if ( ! document.querySelector( '.variations_form' ) ) {
					var block = document.querySelector( '.goldmate-breakdown' );
					if ( block && block.parentNode ) {
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
				}
			}

			try {
				window.dispatchEvent( new CustomEvent( 'goldmate:rate', { detail: data } ) );
			} catch ( e2 ) {
				// Older browsers.
			}
			return;
		}

		updateBoards( data, itemSlug );
	}

	function updateBanners( data ) {
		var banners = document.querySelectorAll( '[data-goldmate-banner], .goldmate-price-banner' );
		var displayRate = data.batch_running ? data.applied_rate : data.rate;
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
				// Default poll updates unscoped boards only; item boards wait for item poll.
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
		var hooked = document.querySelectorAll( '[data-goldmate-live-price]' );
		if ( hooked.length ) {
			hooked.forEach( function ( el ) {
				el.innerHTML = data.live_total_html;
			} );
			return;
		}
		if ( document.querySelector( '.variations_form' ) ) {
			return;
		}
		var price = document.querySelector( '.product .summary p.price' );
		if ( price ) {
			price.innerHTML = data.live_total_html;
		}
	}

	pollAll();
	setInterval( pollAll, interval );
} )();
