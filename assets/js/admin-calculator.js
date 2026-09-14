/**
 * Admin gold price calculator — live breakdown (Ratesbox-style).
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
	var debounceTimer = null;

	function el( sel ) {
		return root.querySelector( sel );
	}

	function num( sel ) {
		var node = el( sel );
		if ( ! node ) {
			return 0;
		}
		var raw = String( node.value || '' )
			.replace( /[۰-۹]/g, function ( d ) {
				return '۰۱۲۳۴۵۶۷۸۹'.indexOf( d );
			} )
			.replace( /[٠-٩]/g, function ( d ) {
				return '٠١٢٣٤٥٦٧٨٩'.indexOf( d );
			} )
			.replace( /[,\s،]/g, '' );
		var n = parseFloat( raw );
		return isFinite( n ) && n >= 0 ? n : 0;
	}

	function roundTotal( total, step, mode ) {
		if ( ! step || step <= 0 ) {
			return Math.round( total * 100 ) / 100;
		}
		var steps = total / step;
		if ( mode === 'ceil' ) {
			steps = Math.ceil( steps );
		} else if ( mode === 'floor' ) {
			steps = Math.floor( steps );
		} else {
			steps = Math.round( steps );
		}
		return steps * step;
	}

	function formatMoney( amount ) {
		var n = Math.round( amount );
		var s = String( Math.abs( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		if ( n < 0 ) {
			s = '-' + s;
		}
		return s + ' ' + ( cfg.currency || 'تومان' );
	}

	function formatNum( n, digits ) {
		var d = typeof digits === 'number' ? digits : 2;
		var x = Number( n );
		if ( ! isFinite( x ) ) {
			return '0';
		}
		var fixed = x.toFixed( d );
		var parts = fixed.split( '.' );
		parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		if ( d === 0 || parts[1] === undefined || /^0+$/.test( parts[1] ) ) {
			return parts[0];
		}
		return parts[0] + '.' + parts[1].replace( /0+$/, '' );
	}

	function setText( sel, text ) {
		var node = el( sel );
		if ( node ) {
			node.textContent = text;
		}
	}

	function setHtml( sel, html ) {
		var node = el( sel );
		if ( node ) {
			node.innerHTML = html;
		}
	}

	function syncWageFields() {
		var mode = el( '[name="wage_mode"]' ).value;
		el( '.goldmate-calc-wage-pct-wrap' ).style.display =
			mode === 'pct' || mode === 'combined' ? '' : 'none';
		el( '.goldmate-calc-wage-fixed-wrap' ).style.display =
			mode === 'fixed' || mode === 'combined' ? '' : 'none';
	}

	function unitRate( rate18, karat ) {
		return rate18 * ( karat / 18 );
	}

	function calculate() {
		var rate18 = num( '[name="rate_18"]' );
		var karat = num( '[name="karat"]' ) || 18;
		var weight = num( '[name="weight"]' );
		var wageMode = el( '[name="wage_mode"]' ).value;
		var wagePct = num( '[name="wage_pct"]' );
		var wageFixed = num( '[name="wage_fixed"]' );
		var accessories = num( '[name="accessories"]' );
		var profitPct = num( '[name="profit_pct"]' );
		var taxPct = num( '[name="tax_pct"]' );
		var taxOnAcc = el( '[name="tax_accessories"]' ).checked;
		var profitOnAcc = el( '[name="profit_accessories"]' ).checked;

		var rate = unitRate( rate18, karat );
		var gold = weight * rate;
		var wage = 0;

		if ( wageMode === 'fixed' ) {
			wage = weight * wageFixed;
		} else if ( wageMode === 'combined' ) {
			wage = weight * wageFixed + gold * ( wagePct / 100 );
		} else {
			wage = gold * ( wagePct / 100 );
		}

		var profitBase = gold + wage + ( profitOnAcc ? accessories : 0 );
		var profit = profitBase * ( profitPct / 100 );

		var taxable = wage + profit + ( taxOnAcc ? accessories : 0 );
		var tax = taxable * ( taxPct / 100 );

		var total = gold + wage + profit + accessories + tax;
		total = roundTotal( total, cfg.roundTo || 0, cfg.roundMode || 'round' );

		var karatLabel = 'عیار ' + formatNum( karat, 1 );

		setText( '[data-out="rate_unit"]', formatMoney( rate ) );
		setText( '[data-out="gold"]', formatMoney( gold ) );
		setHtml(
			'[data-out="gold_formula"]',
			formatNum( weight, 3 ) + ' گرم × ' + formatMoney( rate )
		);

		setText( '[data-out="wage"]', formatMoney( wage ) );
		if ( wageMode === 'fixed' ) {
			setHtml(
				'[data-out="wage_formula"]',
				formatNum( weight, 3 ) + ' گرم × ' + formatMoney( wageFixed ) + ' /گرم'
			);
		} else if ( wageMode === 'combined' ) {
			setHtml(
				'[data-out="wage_formula"]',
				'(' +
					formatNum( weight, 3 ) +
					' × ' +
					formatMoney( wageFixed ) +
					') + (' +
					formatMoney( gold ) +
					' × ' +
					formatNum( wagePct, 2 ) +
					'٪)'
			);
		} else {
			setHtml(
				'[data-out="wage_formula"]',
				'قیمت طلا × ' + formatNum( wagePct, 2 ) + '٪'
			);
		}

		setText( '[data-out="profit"]', formatMoney( profit ) );
		setHtml(
			'[data-out="profit_formula"]',
			'(' +
				( profitOnAcc ? 'طلا + اجرت + ملحقات' : 'طلا + اجرت' ) +
				') × ' +
				formatNum( profitPct, 2 ) +
				'٪'
		);

		setText( '[data-out="accessories"]', formatMoney( accessories ) );
		setText( '[data-out="tax"]', formatMoney( tax ) );
		setHtml(
			'[data-out="tax_formula"]',
			'(' +
				( taxOnAcc ? 'اجرت + سود + ملحقات' : 'اجرت + سود' ) +
				') × ' +
				formatNum( taxPct, 2 ) +
				'٪'
		);

		setText( '[data-out="total"]', formatMoney( total ) );
		setText( '[data-out="karat_label"]', karatLabel );

		scheduleSimilar( weight );
	}

	function scheduleSimilar( weight ) {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( function () {
			loadSimilar( weight );
		}, 400 );
	}

	function loadSimilar( weight ) {
		var box = el( '[data-similar]' );
		if ( ! box || ! cfg.ajaxUrl ) {
			return;
		}
		if ( ! weight || weight <= 0 ) {
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

	root.addEventListener( 'input', calculate );
	root.addEventListener( 'change', function ( e ) {
		if ( e.target && e.target.name === 'wage_mode' ) {
			syncWageFields();
		}
		if ( e.target && e.target.name === 'karat' ) {
			var rate18 = num( '[name="rate_18"]' );
			var karat = num( '[name="karat"]' ) || 18;
			el( '[name="rate_display"]' ).value = formatNum( unitRate( rate18, karat ), 0 );
		}
		calculate();
	} );

	// Keep unit rate field in sync when base 18k rate changes.
	el( '[name="rate_18"]' ).addEventListener( 'input', function () {
		var rate18 = num( '[name="rate_18"]' );
		var karat = num( '[name="karat"]' ) || 18;
		el( '[name="rate_display"]' ).value = formatNum( unitRate( rate18, karat ), 0 );
	} );

	syncWageFields();
	calculate();
} )();
