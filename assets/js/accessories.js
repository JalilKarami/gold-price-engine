(function ($) {
	'use strict';

	function parsePrice(raw) {
		if (raw === undefined || raw === null || raw === '') {
			return 0;
		}
		var n = parseFloat(String(raw).replace(/,/g, ''));
		return isFinite(n) ? n : 0;
	}

	function decodeEntities(str) {
		if (!str) {
			return '';
		}
		var textarea = document.createElement('textarea');
		textarea.innerHTML = String(str);
		return textarea.value.replace(/\u00a0/g, ' ').trim();
	}

	function formatMoney(amount) {
		var cfg = (window.goldmateAccessories && goldmateAccessories.currencyFormat) || {};
		var decimals = typeof cfg.decimal === 'number' ? cfg.decimal : 0;
		var decSep = cfg.decimalSep || '.';
		var thouSep = cfg.thousand || ',';
		var symbol = decodeEntities(cfg.currency || '');
		var format = decodeEntities(cfg.priceFormat || '%1$s%2$s');

		var fixed = amount.toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		var number = parts.length > 1 ? parts[0] + decSep + parts[1] : parts[0];

		return format.replace('%1$s', symbol).replace('%2$s', number);
	}

	function basePrice($form) {
		var fromVariation = $form.data('goldmateBasePrice');

		if (typeof fromVariation === 'number' && isFinite(fromVariation)) {
			return fromVariation;
		}

		var $root = $form.find('.goldmate-accessories');
		var attr = $root.attr('data-base-price');
		if (attr !== undefined && attr !== '') {
			return parsePrice(attr);
		}

		var $price = $('.summary .price .amount, .summary .woocommerce-Price-amount').last();
		if ($price.length) {
			var text = $price.first().text().replace(/[^\d.,]/g, '').replace(/,/g, '');
			var n = parseFloat(text);
			if (isFinite(n)) {
				return n;
			}
		}

		return 0;
	}

	function accessorySum($root) {
		var sum = 0;
		$root.find('.goldmate-accessory-select').each(function () {
			var $opt = $(this).find('option:selected');
			sum += parsePrice($opt.data('price'));
		});
		return sum;
	}

	function updateTotal($root) {
		var $form = $root.closest('form.cart');
		var total = basePrice($form) + accessorySum($root);
		var $box = $root.find('.goldmate-accessory-total');
		var label = (window.goldmateAccessories && goldmateAccessories.i18n && goldmateAccessories.i18n.total) || '';

		if (accessorySum($root) <= 0) {
			$box.attr('hidden', 'hidden');
			return;
		}

		$box.find('.label').text(label);
		$box.find('.amount').text(formatMoney(total));
		$box.removeAttr('hidden');
	}

	$(function () {
		var $root = $('.goldmate-accessories');
		if (!$root.length) {
			return;
		}

		var $form = $root.closest('form.cart');

		$form.on('show_variation', function (event, variation) {
			if (variation && typeof variation.display_price === 'number') {
				$form.data('goldmateBasePrice', variation.display_price);
			}
			updateTotal($root);
		});

		$form.on('hide_variation', function () {
			$form.removeData('goldmateBasePrice');
			updateTotal($root);
		});

		$root.on('change', '.goldmate-accessory-select', function () {
			updateTotal($root);
		});

		updateTotal($root);
	});
})(jQuery);
