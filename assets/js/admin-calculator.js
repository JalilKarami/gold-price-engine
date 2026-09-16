/**
 * Admin gold price calculator — posts the form to the PHP calculator and paints
 * the breakdown it sends back.
 *
 * Every price rule lives in Goldmate_Calculator: this file only collects field
 * values and writes the returned strings into their `data-out` slots.
 */
( function () {
	'use strict';

	if ( typeof goldmateAdminCalc === 'undefined' ) {
		return;
	}

	var root = document.getElementById( 'goldmate-admin-calc' );
	if ( ! root ) {
		return;
	}

	var cfg = goldmateAdminCalc;
	var calcTimer = null;
	var similarTimer = null;
	var pending = null;
	var panel = root.querySelector( '.goldmate-calc-result' );

	// Raw values; the endpoint sanitises them, Persian digits included.
	var FIELDS = [
		'karat',
		'rate_18',
		'weight',
		'wage_mode',
		'wage_pct',
		'wage_fixed',
		'profit_pct',
		'accessories',
		'tax_pct'
	];

	var FLAGS = [ 'profit_accessories', 'tax_accessories' ];

	function el( sel ) {
		return root.querySelector( sel );
	}

	function field( name ) {
		return el( '[name="' + name + '"]' );
	}

	function value( name ) {
		var node = field( name );
		return node ? String( node.value || '' ) : '';
	}

	function isChecked( name ) {
		var node = field( name );
		return !! ( node && node.checked );
	}

	function syncWageFields() {
		var mode = value( 'wage_mode' );
		el( '.goldmate-calc-wage-pct-wrap' ).style.display =
			mode === 'pct' || mode === 'combined' ? '' : 'none';
		el( '.goldmate-calc-wage-fixed-wrap' ).style.display =
			mode === 'fixed' || mode === 'combined' ? '' : 'none';
	}

	function requestBody() {
		var parts = [
			'action=goldmate_admin_calc',
			'nonce=' + encodeURIComponent( cfg.nonce )
		];

		FIELDS.forEach( function ( name ) {
			parts.push( name + '=' + encodeURIComponent( value( name ) ) );
		} );

		FLAGS.forEach( function ( name ) {
			if ( isChecked( name ) ) {
				parts.push( name + '=1' );
			}
		} );

		return parts.join( '&' );
	}

	function settled() {
		if ( panel ) {
			panel.classList.remove( 'is-calculating' );
		}
	}

	function clearOutputs( message ) {
		var nodes = root.querySelectorAll( '[data-out]' );

		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].innerHTML =
				nodes[ i ].className.indexOf( 'amount' ) !== -1 ? '—' : '';
		}

		var extras = el( '[data-out="components"]' );
		if ( extras && message ) {
			extras.textContent = message;
		}

		settled();
	}

	function paint( data ) {
		var out = data.out || {};

		for ( var key in out ) {
			if ( ! Object.prototype.hasOwnProperty.call( out, key ) ) {
				continue;
			}
			var node = el( '[data-out="' + key + '"]' );
			if ( node ) {
				node.textContent = out[ key ];
			}
		}

		var extras = el( '[data-out="components"]' );
		if ( extras ) {
			extras.innerHTML = data.components || '';
		}

		var display = field( 'rate_display' );
		if ( display ) {
			display.value = data.unitRate || '';
		}

		settled();
	}

	function calculate() {
		if ( ! cfg.ajaxUrl ) {
			return;
		}

		// An in-flight answer is already stale once a field changed again.
		if ( pending ) {
			pending.abort();
		}

		var xhr = new XMLHttpRequest();
		pending = xhr;

		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onload = function () {
			pending = null;
			if ( xhr.status !== 200 ) {
				clearOutputs();
				return;
			}
			try {
				var payload = JSON.parse( xhr.responseText );
				if ( ! payload.success ) {
					clearOutputs( payload.data && payload.data.message );
					return;
				}
				paint( payload.data );
			} catch ( e ) {
				clearOutputs();
			}
		};

		xhr.onerror = function () {
			pending = null;
			clearOutputs();
		};

		xhr.send( requestBody() );
	}

	function refresh() {
		// admin-ajax costs a full WordPress bootstrap, so the panel can sit on the
		// previous inputs' numbers for a second or more. Mark it stale meanwhile:
		// a plausible-looking price from the wrong inputs is worse than a visibly
		// pending one.
		if ( panel ) {
			panel.classList.add( 'is-calculating' );
		}

		clearTimeout( calcTimer );
		calcTimer = setTimeout( calculate, 400 );

		clearTimeout( similarTimer );
		similarTimer = setTimeout( loadSimilar, 400 );
	}

	function loadSimilar() {
		var box = el( '[data-similar]' );
		if ( ! box || ! cfg.ajaxUrl ) {
			return;
		}

		var weight = value( 'weight' );

		if ( ! parseFloat( weight ) ) {
			box.innerHTML = '<p class="description">وزن را وارد کنید تا محصولات مشابه نمایش داده شوند.</p>';
			return;
		}

		box.innerHTML = '<p class="description">در حال جستجو…</p>';

		var url =
			cfg.ajaxUrl +
			'?action=goldmate_admin_similar&weight=' +
			encodeURIComponent( weight ) +
			'&nonce=' +
			encodeURIComponent( cfg.nonce );

		var xhr = new XMLHttpRequest();
		xhr.open( 'GET', url, true );
		xhr.onload = function () {
			if ( xhr.status !== 200 ) {
				box.innerHTML = '<p class="description">خطا در دریافت محصولات.</p>';
				return;
			}
			try {
				var payload = JSON.parse( xhr.responseText );
				if ( ! payload.success ) {
					box.innerHTML = '<p class="description">محصول مشابهی پیدا نشد.</p>';
					return;
				}
				box.innerHTML = payload.data.html || '<p class="description">محصول مشابهی پیدا نشد.</p>';
			} catch ( e ) {
				box.innerHTML = '<p class="description">خطا در پاسخ سرور.</p>';
			}
		};
		xhr.send();
	}

	root.addEventListener( 'input', refresh );
	root.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.name === 'wage_mode' ) {
			syncWageFields();
		}
		refresh();
	} );

	syncWageFields();
	refresh();
} )();
