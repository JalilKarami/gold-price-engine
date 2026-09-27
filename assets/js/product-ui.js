(function ($) {
	'use strict';

	if (!$) {
		return;
	}

	/* Guard: a copy still pasted into the theme's Custom JS must not bind twice. */
	if (window.goldmateProductUi) {
		return;
	}
	window.goldmateProductUi = true;

	/*
	|--------------------------------------------------------------------------
	| CONFIG
	|--------------------------------------------------------------------------
	*/

	/*
	 * Every WC variation attribute row gets the goldmate-vd trigger/modal,
	 * except SKIP_STYLE_ATTRIBUTES (e.g. Color keeps Woodmart swatches).
	 * CONTROLLING_ATTRIBUTES still drives size→weight filtering only
	 * (behaviour, not appearance).
	 */
	var SKIP_STYLE_ATTRIBUTES = [
		'رنگ',
		'color',
		'Color'
	];

	var ATTRIBUTE_LABELS = []; /* unused; kept so older diffs stay readable */

	/*
	 * ONLY these attributes are allowed to control
	 * dependent attributes.
	 *
	 * سایز → controls وزن
	 * سایز انگشتر → controls وزن
	 *
	 * وزن → controls nothing
	 */
	var CONTROLLING_ATTRIBUTES = [
		'سایز',
		'سایز انگشتر'
	];

	var WEIGHT_LABEL = 'وزن';

	var I18N = {
		choose: 'انتخاب کنید',
		unavailable: 'ناموجود',
		close: 'بستن',
		goldPrice: 'قیمت روز طلا',
		gram: 'گرم',
		perGram: 'هر گرم',
		chooseSize: 'انتخاب سایز',
		chooseWeight: 'انتخاب وزن طلا',
		finalPrice: 'قیمت نهایی'
	};

	function fillTrigger($value, attrValue, isWeight) {

		if (!attrValue) {
			$value.text(I18N.choose);
			return;
		}

		var text = String(attrValue);

		if (isWeight) {
			text = text + ' ' + I18N.gram;
		}

		$value.text(text);

	}

	function selectorHeading(attrName) {

		if (isWeightAttribute(attrName)) {
			return I18N.chooseWeight;
		}

		if (
			attrName === 'سایز' ||
			attrName === 'سایز انگشتر'
		) {
			return I18N.chooseSize;
		}

		return 'انتخاب ' + (attrName || '');

	}

	function setupCleanRow($row, heading) {

		$row.addClass('goldmate-vd-clean-row');

		$row
			.children('th.label')
			.attr('hidden', true)
			.attr('aria-hidden', 'true');

		var $cell = $row.children('td.value');

		if (!$cell.children('.goldmate-vd-heading').length) {
			$cell.prepend(
				$('<div class="goldmate-vd-heading"></div>').text(heading)
			);
		}

	}

	function setTriggerLoading($trigger, loading) {

		if (!$trigger || !$trigger.length) {
			return;
		}

		var timer = $trigger.data('goldmateSpinTimer');

		if (timer) {
			clearTimeout(timer);
			$trigger.removeData('goldmateSpinTimer');
		}

		if (loading) {
			$trigger.data('goldmateSpinStarted', Date.now());
			$trigger.addClass('is-loading');
			$trigger.attr('aria-busy', 'true');
			return;
		}

		/*
		 * Keep a short minimum so the spinner is visible,
		 * but not long enough to feel like price flicker.
		 */
		var started = $trigger.data('goldmateSpinStarted') || 0;
		var wait = Math.max(0, 120 - (Date.now() - started));

		$trigger.data(
			'goldmateSpinTimer',
			setTimeout(function () {
				$trigger.removeClass('is-loading');
				$trigger.removeAttr('aria-busy');
				$trigger.removeData('goldmateSpinTimer');
				$trigger.removeData('goldmateSpinStarted');
			}, wait)
		);

	}

	/*
	 * True when the variation still matches the
	 * currently selected Size / Ring Size.
	 */
	function variationMatchesControllers($form, variation) {

		if (!variation || !variation.attributes) {
			return false;
		}

		var selections = getSelections($form);
		var ok = true;

		$.each(selections, function (name, value) {

			var label = getAttributeLabelByName($form, name);

			/*
			 * Every selected attribute except weight must match, not
			 * just Size: otherwise changing Color re-pins the old
			 * variation and the form keeps the previous color.
			 */
			if (isWeightAttribute(label)) {
				return;
			}

			if (!value) {
				return;
			}

			if (
				!matchesVariationValue(
					variation.attributes[name],
					value
				)
			) {
				ok = false;
				return false;
			}

		});

		return ok;

	}

	var labels = $.map(ATTRIBUTE_LABELS, function (label) {
		return $.trim(label);
	});

	var controllingLabels = $.map(
		CONTROLLING_ATTRIBUTES,
		function (label) {
			return $.trim(label);
		}
	);

	var skipStyleLabels = $.map(
		SKIP_STYLE_ATTRIBUTES,
		function (label) {
			return $.trim(label).toLowerCase();
		}
	);

	var $overlay = null;
	var closeCurrent = function () {};

	/*
	|--------------------------------------------------------------------------
	| HELPERS
	|--------------------------------------------------------------------------
	*/

	function escapeValue(value) {

		if (window.CSS && window.CSS.escape) {
			return window.CSS.escape(value);
		}

		return String(value).replace(
			/([^a-zA-Z0-9_-])/g,
			'\\$1'
		);
	}

	function isTargetLabel(label) {
		/* Styled selector for every attr except Color (keeps swatches). */
		var trimmed = $.trim(label || '');
		if (!trimmed) {
			return false;
		}
		return $.inArray(
			trimmed.toLowerCase(),
			skipStyleLabels
		) === -1;
	}

	function isControllingAttribute(label) {

		return $.inArray(
			$.trim(label || ''),
			controllingLabels
		) !== -1;
	}

	function isWeightAttribute(label) {

		/* Any label starting with WEIGHT_LABEL (e.g. a longer gold-weight label) is the weight attribute. */
		return $.trim(label || '').indexOf(WEIGHT_LABEL) === 0;
	}

	/*
	|--------------------------------------------------------------------------
	| GET ROW LABEL
	|--------------------------------------------------------------------------
	*/

	function getRowLabel($row) {

		return $.trim(
			$row
				.find('th.label label')
				.text()
		);
	}

	/*
	|--------------------------------------------------------------------------
	| GET ATTRIBUTE LABEL BY SELECT NAME
	|--------------------------------------------------------------------------
	*/

	function getAttributeLabelByName($form, name) {

		var result = '';

		$form
			.find('.variations select')
			.each(function () {

				if ($(this).attr('name') !== name) {
					return;
				}

				result = getRowLabel(
					$(this).closest('tr')
				);

				return false;

			});

		return result;
	}

	/*
	|--------------------------------------------------------------------------
	| OVERLAY
	|--------------------------------------------------------------------------
	*/

	function getOverlay() {

		if ($overlay) {
			return $overlay;
		}

		$overlay = $(
			'<div class="goldmate-vd-overlay" role="dialog" aria-modal="true">' +
				'<div class="goldmate-vd-modal">' +

					'<div class="goldmate-vd-head">' +
						'<span class="goldmate-vd-title"></span>' +
						'<button type="button" class="goldmate-vd-close" aria-label="' +
							I18N.close +
						'">×</button>' +
					'</div>' +

					'<div class="goldmate-vd-list"></div>' +

				'</div>' +
			'</div>'
		);

		$(document.body).append($overlay);

		/*
		 * Close overlay background.
		 */
		$overlay.on('click', function (event) {

			if (event.target === $overlay[0]) {
				closeCurrent();
			}

		});

		/*
		 * Close button.
		 */
		$overlay
			.find('.goldmate-vd-close')
			.on('click', function () {

				closeCurrent();

			});

		/*
		 * ESC.
		 */
		$(document).on('keydown', function (event) {

			if (
				event.key === 'Escape' &&
				$overlay.hasClass('is-open')
			) {

				closeCurrent();

			}

		});

		return $overlay;
	}

	/*
	|--------------------------------------------------------------------------
	| DIALOG
	|--------------------------------------------------------------------------
	*/

	function openDialog(
		title,
		options,
		selected,
		onPick,
		isController
	) {

		var $ov = getOverlay();

		var $list = $ov
			.find('.goldmate-vd-list')
			.empty();

		$ov
			.find('.goldmate-vd-title')
			.text(title);

		$.each(options, function (i, option) {

			var $row = $(
				'<button type="button" class="goldmate-vd-row"></button>'
			);

			/*
			 * LABEL
			 */
			$row.append(
				$('<span class="goldmate-vd-row-label"></span>')
					.text(option.label)
			);

			/*
			 * PRICE
			 *
			 * Controller attributes NEVER show price.
			 *
			 * Weight DOES show price.
			 */
			if (option.available) {

				if (
					!isController &&
					option.variation &&
					option.variation.price_html
				) {

					$row.append(
						$(
							'<span class="goldmate-vd-row-price"></span>'
						).html(
							option.variation.price_html
						)
					);

				}

			} else {

				$row.append(
					$(
						'<span class="goldmate-vd-row-price goldmate-vd-row-unavailable"></span>'
					).text(
						I18N.unavailable
					)
				);

				$row
					.addClass('is-disabled')
					.prop('disabled', true);

			}

			/*
			 * SELECTED
			 */
			if (
				String(option.value) ===
				String(selected)
			) {

				$row.addClass('is-selected');

			}

			/*
			 * CLICK
			 */
			if (option.available) {

				$row.on('click', function () {

					onPick(option.value, option);

					closeCurrent();

				});

			}

			$list.append($row);

		});

		/*
		 * Lock page scrolling.
		 */
		$(document.documentElement)
			.addClass('goldmate-vd-locked');

		$ov.addClass('is-open');

		/*
		 * Close function.
		 */
		closeCurrent = function () {

			$ov.removeClass('is-open');

			$(document.documentElement)
				.removeClass('goldmate-vd-locked');

		};
	}

	/*
	|--------------------------------------------------------------------------
	| VARIATION HELPERS
	|--------------------------------------------------------------------------
	*/

	function getVariations($form) {

		var data = $form.data(
			'product_variations'
		);

		return $.isArray(data)
			? data
			: [];
	}

	function getSelections($form) {

		var selections = {};

		$form
			.find('.variations select')
			.each(function () {

				var name = $(this).attr('name');
				var value = $(this).val();

				if (name && value) {

					selections[name] = value;

				}

			});

		return selections;
	}

	/*
	|--------------------------------------------------------------------------
	| MATCH VARIATION ATTRIBUTE
	|--------------------------------------------------------------------------
	|
	| WooCommerce:
	|
	| "" = wildcard
	|
	*/

	function matchesVariationValue(
		variationValue,
		selectedValue
	) {

		if (typeof variationValue === 'undefined') {
			return false;
		}

		if (variationValue === '') {
			return true;
		}

		return String(variationValue) ===
			String(selectedValue);
	}

	/*
	|--------------------------------------------------------------------------
	| WEIGHT / VARIATION ID HELPERS
	|--------------------------------------------------------------------------
	|
	| Two variations can share the same Size (e.g. 58 / 3g and 58 / 3.3g).
	| WooCommerce then sees two matches, clears variation_id, and the first
	| weight "wins". Weight is GoldMate meta, so we pin the chosen row
	| by variation_id after WC finishes matching.
	|
	*/

	function variationWeightKey(variation) {

		if (!variation) {
			return '';
		}

		var weight = parseFloat(variation.goldmate_weight);

		if (isNaN(weight) || weight <= 0) {
			return '';
		}

		return String(weight);
	}

	function weightsMatch(a, b) {

		if (a === '' || b === '' || a == null || b == null) {
			return false;
		}

		var left = parseFloat(a);
		var right = parseFloat(b);

		if (isNaN(left) || isNaN(right)) {
			return String(a) === String(b);
		}

		return Math.abs(left - right) < 0.0005;
	}

	function findVariationById($form, variationId) {

		var found = null;

		$.each(getVariations($form), function (index, variation) {

			if (
				variation &&
				String(variation.variation_id) === String(variationId)
			) {
				found = variation;
				return false;
			}

		});

		return found;
	}

	function findVariationByWeight($form, weightKey) {

		if (!weightKey) {
			return null;
		}

		var variations = getVariations($form);
		var selections = getSelections($form);
		var best = null;

		$.each(variations, function (index, variation) {

			if (!variation || !variation.attributes) {
				return;
			}

			if (!weightsMatch(variationWeightKey(variation), weightKey)) {
				return;
			}

			var valid = true;

			$.each(selections, function (name, value) {

				var label = getAttributeLabelByName($form, name);

				if (!isControllingAttribute(label)) {
					return;
				}

				if (!matchesVariationValue(variation.attributes[name], value)) {
					valid = false;
					return false;
				}

			});

			if (!valid) {
				return;
			}

			if (!best) {
				best = variation;
			}

			var exact = true;

			$.each(selections, function (name, value) {

				var label = getAttributeLabelByName($form, name);

				if (!isControllingAttribute(label)) {
					return;
				}

				if (variation.attributes[name] !== value) {
					exact = false;
				}

			});

			if (exact) {
				best = variation;
				return false;
			}

		});

		return best;
	}

	function enableAddToCart($form) {

		$form
			.find('.single_add_to_cart_button')
			.removeClass(
				'disabled wc-variation-selection-needed wc-variation-is-unavailable'
			)
			.addClass('wc-variation-is-available')
			.prop('disabled', false);
	}

	function forceVariationOnForm($form, variation) {

		if (!variation || !variation.variation_id) {
			return;
		}

		if ($form.data('goldmateForcing')) {
			return;
		}

		$form.data('goldmateForcing', true);
		$form.data('goldmateChosenVariationId', variation.variation_id);

		$form
			.find('input[name="variation_id"], input.variation_id')
			.val(variation.variation_id)
			.trigger('change');

		$form.trigger('found_variation', [variation]);
		$form.trigger('show_variation', [variation, true]);

		enableAddToCart($form);

		setTimeout(function () {
			$form.removeData('goldmateForcing');
		}, 0);
	}

	/*
	|--------------------------------------------------------------------------
	| FIND CONTROLLING SELECTS
	|--------------------------------------------------------------------------
	*/

	function getControllerSelects($form) {

		var result = [];

		$form
			.find('.variations select')
			.each(function () {

				var $select = $(this);

				var label = getRowLabel(
					$select.closest('tr')
				);

				if (
					isControllingAttribute(label)
				) {

					result.push({
						$select: $select,
						name: $select.attr('name'),
						label: label
					});

				}

			});

		return result;
	}

	/*
	|--------------------------------------------------------------------------
	| FIND WEIGHT VALUES
	|--------------------------------------------------------------------------
	|
	| IMPORTANT:
	|
	| Weight is NOT a WooCommerce attribute anymore.
	|
	| We therefore read:
	|
	| variation.goldmate_weight
	|
	*/

	function getAllWeightOptions($form) {

		var variations = getVariations($form);

		var map = {};

		$.each(
			variations,
			function (index, variation) {

				if (!variation) {
					return;
				}

				var key = variationWeightKey(variation);

				if (!key) {
					return;
				}

				if (!map[key]) {

					map[key] = {
						value: key,
						label: key,
						variation: null,
						available: false
					};

				}

			}
		);

		var selections =
			getSelections($form);

		/*
		 * Determine which variation belongs
		 * to the currently selected Size.
		 */
		$.each(
			map,
			function (weightKey, option) {

				var best = null;

				$.each(
					variations,
					function (index, variation) {

						if (!variation) {
							return;
						}

						if (
							!weightsMatch(
								variationWeightKey(variation),
								weightKey
							)
						) {

							return;
						}

						if (!variation.attributes) {
							return;
						}

						var attrs =
							variation.attributes;

						/*
						 * Check every selected attribute
						 * except weight (Size, Color, ...),
						 * so each weight is backed by the
						 * variation of the chosen Color too.
						 */
						var valid = true;

						$.each(
							selections,
							function (name, value) {

								var label =
									getAttributeLabelByName(
										$form,
										name
									);

								if (
									isWeightAttribute(
										label
									)
								) {

									return;

								}

								if (
									!matchesVariationValue(
										attrs[name],
										value
									)
								) {

									valid = false;

									return false;

								}

							}
						);

						if (!valid) {
							return;
						}

						/*
						 * Prefer an exact controller
						 * match over wildcard.
						 */
						if (!best) {
							best = variation;
						}

						/*
						 * Same size can have two weights.
						 * Pin this option to the variation
						 * whose GoldMate weight is this key,
						 * not the first size match.
						 */
						if (
							weightsMatch(
								variationWeightKey(variation),
								weightKey
							) &&
							variation.variation_id
						) {

							best = variation;

							return false;

						}

					}
				);

				if (best) {

					option.variation = best;

					option.available =
						best.is_in_stock !== false &&
						best.variation_is_active !== false;

				}

			}
		);

		/*
		 * Sort numerically.
		 */
		var result = [];

		$.each(
			map,
			function (key, option) {
				result.push(option);
			}
		);

		result.sort(function (a, b) {

			return (
				parseFloat(a.value) -
				parseFloat(b.value)
			);

		});

		return result;
	}

	/*
	|--------------------------------------------------------------------------
	| FIND VARIATION FOR CONTROLLER OPTION
	|--------------------------------------------------------------------------
	*/

	function findControllerVariation(
		$form,
		attributeName,
		optionValue
	) {

		var variations = getVariations($form);
		var selections = getSelections($form);

		var best = null;

		$.each(
			variations,
			function (index, variation) {

				if (
					!variation ||
					!variation.attributes
				) {

					return;
				}

				var attrs =
					variation.attributes;

				if (
					!matchesVariationValue(
						attrs[attributeName],
						optionValue
					)
				) {

					return;

				}

				var valid = true;

				$.each(
					selections,
					function (name, value) {

						if (name === attributeName) {
							return;
						}

						var label =
							getAttributeLabelByName(
								$form,
								name
							);

						/*
						 * Only other controllers
						 * matter.
						 */
						if (
							!isControllingAttribute(
								label
							)
						) {

							return;

						}

						if (
							!matchesVariationValue(
								attrs[name],
								value
							)
						) {

							valid = false;

							return false;

						}

					}
				);

				if (!valid) {
					return;
				}

				if (!best) {
					best = variation;
				}

				if (
					attrs[attributeName] ===
					optionValue
				) {

					best = variation;

					return false;

				}

			}
		);

		return best;
	}

	/*
	|--------------------------------------------------------------------------
	| APPLY VARIATION ATTRIBUTES
	|--------------------------------------------------------------------------
	|
	| Used when a Weight is selected.
	|
	| Since Weight is not a WooCommerce attribute,
	| we select the actual variation's WC attributes.
	|--------------------------------------------------------------------------
	*/

	function applyVariationAttributes(
		$form,
		variation
	) {

		if (
			!variation ||
			!variation.attributes
		) {

			return;
		}

		var attrs =
			variation.attributes;

		$form
			.find('.variations select')
			.each(function () {

				var $select = $(this);
				var name = $select.attr('name');

				if (
					typeof attrs[name] ===
					'undefined'
				) {

					return;

				}

				var value = attrs[name];

				/*
				 * Don't set wildcard attributes.
				 */
				if (value === '') {
					return;
				}

				var $option =
					$select.find(
						'option[value="' +
						escapeValue(value) +
						'"]'
					);

				/*
				 * WooCommerce/WoodMart may have
				 * temporarily removed an option.
				 */
				if (!$option.length) {

					$option = $('<option></option>')
						.attr(
							'value',
							value
						)
						.text(value);

					$select.append(
						$option
					);

				}

				$option
					.prop('disabled', false)
					.prop('hidden', false);

				$select
					.val(value)
					.trigger('change');

			});

		/*
		 * Let WooCommerce resolve the variation.
		 */
		setTimeout(function () {

			$form.trigger(
				'check_variations'
			);

		}, 0);
	}

	/*
	|--------------------------------------------------------------------------
	| SETUP NORMAL ATTRIBUTE ROW
	|--------------------------------------------------------------------------
	*/

	function setupNormalRow(
		$form,
		$row
	) {

		var $valueCell =
			$row.find('td.value');

		var $select =
			$valueCell.find('select');

		if (
			!$select.length ||
			$valueCell.hasClass(
				'goldmate-vd-enhanced'
			)
		) {

			return;

		}

		var attributeName =
			$select.attr('name');

		var attributeLabel =
			getRowLabel($row);

		/*
		 * TRIGGER
		 */
		var $trigger = $(
			'<button type="button" class="goldmate-vd-trigger"></button>'
		);

		var $triggerValue = $(
			'<span class="goldmate-vd-trigger-value"></span>'
		);

		var $triggerPrice = $(
			'<span class="goldmate-vd-trigger-price"></span>'
		);

		$trigger.append(
			$triggerValue,
			$triggerPrice
		);

		setupCleanRow($row, selectorHeading(attributeLabel));

		$valueCell.addClass('goldmate-vd-enhanced');

		var $heading = $valueCell.children('.goldmate-vd-heading');

		if ($heading.length) {
			$heading.after($trigger);
		} else {
			$valueCell.prepend($trigger);
		}

		/*
		 * FIND VARIATION FOR NORMAL ATTRIBUTE
		 */
		function findVariationForOption(
			optionValue
		) {

			var variations =
				getVariations($form);

			var selections =
				getSelections($form);

			var thisIsController =
				isControllingAttribute(
					attributeLabel
				);

			var best = null;

			$.each(
				variations,
				function (index, variation) {

					if (
						!variation ||
						!variation.attributes
					) {

						return;

					}

					var attrs =
						variation.attributes;

					if (
						!matchesVariationValue(
							attrs[attributeName],
							optionValue
						)
					) {

						return;

					}

					var valid = true;

					$.each(
						selections,
						function (name, value) {

							if (
								name ===
								attributeName
							) {

								return;

							}

							var otherLabel =
								getAttributeLabelByName(
									$form,
									name
								);

							/*
							 * Controller only.
							 */
							if (
								thisIsController
							) {

								if (
									!isControllingAttribute(
										otherLabel
									)
								) {

									return;

								}

							}

							/*
							 * Dependent option.
							 */
							else {

								if (
									!isControllingAttribute(
										otherLabel
									)
								) {

									return;

								}

							}

							if (
								!matchesVariationValue(
									attrs[name],
									value
								)
							) {

								valid = false;

								return false;

							}

						}
					);

					if (!valid) {
						return;
					}

					if (!best) {
						best = variation;
					}

					if (
						attrs[attributeName] ===
						optionValue
					) {

						best = variation;

						return false;

					}

				}
			);

			return best;
		}

		/*
		 * GET OPTIONS
		 */
		function getAllOptions() {

			var variations =
				getVariations($form);

			var map = {};

			$.each(
				variations,
				function (index, variation) {

					if (
						!variation ||
						!variation.attributes
					) {

						return;

					}

					var value =
						variation.attributes[
							attributeName
						];

					if (
						typeof value ===
							'undefined' ||
						value === ''
					) {

						return;

					}

					if (!map[value]) {

						map[value] = {
							value: value,
							label: value,
							variation: null,
							available: false
						};

					}

				}
			);

			/*
			 * Use WooCommerce labels.
			 */
			$select
				.find('option')
				.each(function () {

					var value =
						$(this).val();

					if (
						value &&
						map[value]
					) {

						map[value].label =
							$(this).text();

					}

				});

			/*
			 * Availability.
			 */
			$.each(
				map,
				function (value, option) {

					var variation =
						findVariationForOption(
							value
						);

					if (!variation) {
						return;
					}

					option.variation =
						variation;

					option.available =
						variation.is_in_stock !== false &&
						variation.variation_is_active !== false;

				}
			);

			var result = [];

			$.each(
				map,
				function (value, option) {

					result.push(option);

				}
			);

			return result;
		}

		/*
		 * UPDATE TRIGGER
		 */
		function updateTrigger() {

			var value =
				$select.val();

			if (!value) {

				fillTrigger(
					$triggerValue,
					''
				);

				$triggerPrice.empty();

				$trigger.addClass(
					'goldmate-vd-placeholder'
				);

				setTriggerLoading($trigger, false);

				return;

			}

			var variation =
				findVariationForOption(
					value
				);

			fillTrigger(
				$triggerValue,
				value
			);

			/*
			 * Size NEVER shows price.
			 */
			if (
				!isControllingAttribute(
					attributeLabel
				) &&
				variation &&
				variation.price_html
			) {

				$triggerPrice.html(
					variation.price_html
				);

			} else {

				$triggerPrice.empty();

			}

			$trigger.removeClass(
				'goldmate-vd-placeholder'
			);

			setTriggerLoading($trigger, false);
		}

		/*
		 * APPLY NORMAL ATTRIBUTE
		 */
		function applySelection(value) {

			setTriggerLoading($trigger, true);

			var isController = isControllingAttribute(
				attributeLabel
			);

			if (isController) {

				/*
				 * Block the weight-row select handler while
				 * we clear dependents + set Size. Otherwise
				 * clearing Color re-binds the old weight and
				 * goldmateApplying skips the Size sync.
				 */
				$form.data(
					'goldmateControllerChanging',
					true
				);

				var $weightTrigger = $form.find(
					'.goldmate-vd-weight-row .goldmate-vd-trigger'
				);

				setTriggerLoading($weightTrigger, true);

				/*
				 * Hide stale weight/price immediately so
				 * numbers from the previous Size do not
				 * linger while the new match resolves.
				 */
				$weightTrigger
					.find('.goldmate-vd-trigger-value')
					.text(I18N.choose);

				$weightTrigger
					.find('.goldmate-vd-trigger-price')
					.empty();

				$weightTrigger.addClass(
					'goldmate-vd-placeholder'
				);

				/*
				 * Drop the old weight pin before cascading
				 * WC change events.
				 */
				$form.removeData(
					'goldmateChosenVariationId'
				);

			}

			/*
			 * Controller changed:
			 * clear dependent WC attributes.
			 */
			if (isController) {

				$form
					.find('.variations select')
					.each(function () {

						var $otherSelect =
							$(this);

						var otherName =
							$otherSelect.attr(
								'name'
							);

						if (
							otherName ===
							attributeName
						) {

							return;

						}

						var otherLabel =
							getAttributeLabelByName(
								$form,
								otherName
							);

						if (
							!isControllingAttribute(
								otherLabel
							)
						) {

							/*
							 * Keep a non-weight choice (e.g. Color)
							 * that the new Size also comes in; clear
							 * it only when no variation has both.
							 */
							var otherValue = $otherSelect.val();

							if (
								otherValue &&
								!isWeightAttribute(otherLabel)
							) {

								var stillOffered = false;

								$.each(
									getVariations($form),
									function (i, variation) {

										if (
											variation &&
											variation.attributes &&
											matchesVariationValue(
												variation.attributes[attributeName],
												value
											) &&
											matchesVariationValue(
												variation.attributes[otherName],
												otherValue
											)
										) {
											stillOffered = true;
											return false;
										}

									}
								);

								if (stillOffered) {
									return;
								}

							}

							$otherSelect
								.val('')
								.trigger(
									'change'
								);

						}

					});

			}

			var $option =
				$select.find(
					'option[value="' +
					escapeValue(value) +
					'"]'
				);

			if (!$option.length) {

				$option =
					$('<option></option>')
						.attr(
							'value',
							value
						)
						.text(value);

				$select.append(
					$option
				);

			}

			$option
				.prop('disabled', false)
				.prop('hidden', false);

			$select
				.val(value)
				.trigger('change');

			if (isController) {

				$form.removeData(
					'goldmateControllerChanging'
				);

				/*
				 * One sync after Size is final — not during
				 * the Color-clear cascade.
				 */
				$form.trigger(
					'goldmate_sync_weight'
				);

			}

		}

		/*
		 * OPEN
		 */
		$trigger.on(
			'click',
			function (event) {

				event.preventDefault();

				if ($trigger.hasClass('is-loading')) {
					return;
				}

				openDialog(
					attributeLabel,
					getAllOptions(),
					$select.val(),
					applySelection,
					isControllingAttribute(
						attributeLabel
					)
				);

			}
		);

		/*
		 * EVENTS
		 */
		$form.on(
			'woocommerce_update_variation_values',
			updateTrigger
		);

		$form.on(
			'found_variation',
			updateTrigger
		);

		$form.on(
			'reset_data',
			updateTrigger
		);

		$form.on(
			'show_variation',
			updateTrigger
		);

		$form.on(
			'hide_variation',
			updateTrigger
		);

		$select.on(
			'change',
			updateTrigger
		);

		updateTrigger();
	}

	/*
	|--------------------------------------------------------------------------
	| SETUP CUSTOM WEIGHT ROW
	|--------------------------------------------------------------------------
	*/

	function setupWeightRow($form) {

		/*
		 * Don't create twice.
		 */
		if (
			$form.find(
				'.goldmate-vd-weight-row'
			).length
		) {

			return;
		}

		/*
		 * Find the first controller row.
		 *
		 * Weight is no longer a WC attribute,
		 * so there is no <select> for it.
		 */
		var $controllerRow =
			$form
				.find('.variations > tbody > tr')
				.filter(function () {

					return isControllingAttribute(
						getRowLabel($(this))
					);

				})
				.first();

		/*
		 * If there is no Size/Ring Size,
		 * put Weight after the variation table.
		 */
		var $tbody =
			$form.find(
				'.variations > tbody'
			);

		if (!$tbody.length) {
			return;
		}

		/*
		 * CREATE ROW
		 */
		var $row = $(
			'<tr class="goldmate-vd-weight-row goldmate-vd-clean-row">' +
				'<th class="label" hidden aria-hidden="true">' +
					'<label>' +
						WEIGHT_LABEL +
					'</label>' +
				'</th>' +
				'<td class="value cell goldmate-vd-enhanced">' +
					'<div class="goldmate-vd-heading">' +
						I18N.chooseWeight +
					'</div>' +
					'<button type="button" class="goldmate-vd-trigger goldmate-vd-placeholder">' +
						'<span class="goldmate-vd-trigger-value">' +
							I18N.choose +
						'</span>' +
						'<span class="goldmate-vd-trigger-price"></span>' +
					'</button>' +
					'<div class="goldmate-vd-gold-price"></div>' +
				'</td>' +
			'</tr>'
		);

		/*
		 * Put after the last target/controller row.
		 */
		if ($controllerRow.length) {

			$controllerRow.after($row);

		} else {

			$tbody.append($row);

		}

		var $trigger =
			$row.find(
				'.goldmate-vd-trigger'
			);

		var $triggerValue =
			$row.find(
				'.goldmate-vd-trigger-value'
			);

		var $triggerPrice =
			$row.find(
				'.goldmate-vd-trigger-price'
			);

		var $goldPrice =
			$row.find(
				'.goldmate-vd-gold-price'
			);

		function shopGoldPriceHtml(variation) {

			if (
				variation &&
				variation.goldmate_rate_18_html
			) {
				return variation.goldmate_rate_18_html;
			}

			if (
				window.goldmatePrice &&
				window.goldmatePrice.rate_html
			) {
				return window.goldmatePrice.rate_html;
			}

			return '';
		}

		function updateGoldPriceLine(variation) {

			var html = shopGoldPriceHtml(variation);

			if (!html) {
				$goldPrice.empty().hide();
				return;
			}

			$goldPrice
				.html(
					'<span class="goldmate-vd-gold-price-label">' +
						I18N.goldPrice +
					'</span>' +
					'<span class="goldmate-vd-gold-price-value">' +
						'<span class="goldmate-vd-gold-price-unit">' +
							I18N.perGram +
						'</span> ' +
						html +
					'</span>'
				)
				.show();

		}

		updateGoldPriceLine(null);

		/*
		 * CURRENT SELECTED WEIGHT
		 *
		 * Stored separately because Weight
		 * is not a WooCommerce select.
		 */
		var selectedWeight = '';

		function bindChosenVariation(variation) {

			if (!variation || !variation.variation_id) {
				return;
			}

			selectedWeight = variationWeightKey(variation);

			$form.data(
				'goldmateChosenVariationId',
				variation.variation_id
			);

			$form.data('goldmateApplying', true);

			applyVariationAttributes(
				$form,
				variation
			);

			updateFromVariation(variation);

			setTimeout(function () {

				forceVariationOnForm(
					$form,
					variation
				);

				$form.removeData('goldmateApplying');

			}, 20);

		}

		function clearChosenVariation() {

			selectedWeight = '';

			$form.removeData('goldmateChosenVariationId');

			$form
				.find(
					'input[name="variation_id"], input.variation_id'
				)
				.val('');

			fillTrigger(
				$triggerValue,
				'',
				true
			);

			$triggerPrice.empty();

			$trigger.addClass(
				'goldmate-vd-placeholder'
			);

			updateGoldPriceLine(null);

			setTriggerLoading($trigger, false);

		}

		/*
		 * FIND CURRENT WEIGHT FROM CURRENT
		 * FOUND VARIATION.
		 */
		function updateFromVariation(
			variation
		) {

			if (!variation) {

				fillTrigger(
					$triggerValue,
					'',
					true
				);

				$triggerPrice.empty();

				$trigger.addClass(
					'goldmate-vd-placeholder'
				);

				updateGoldPriceLine(null);

				setTriggerLoading($trigger, false);

				return;

			}

			var weightKey =
				variationWeightKey(variation);

			if (!weightKey) {

				/*
				 * Matched variation has no GoldMate weight —
				 * do not keep showing the previous Size weight.
				 */
				if (
					!$form.data('goldmateChosenVariationId')
				) {
					clearChosenVariation();
				}

				return;
			}

			selectedWeight = weightKey;

			fillTrigger(
				$triggerValue,
				weightKey,
				true
			);

			if (
				variation.price_html
			) {

				$triggerPrice.html(
					variation.price_html
				);

			} else {

				$triggerPrice.empty();

			}

			$trigger.removeClass(
				'goldmate-vd-placeholder'
			);

			updateGoldPriceLine(variation);

			setTriggerLoading($trigger, false);
		}

		/*
		 * FIND CURRENT VARIATION FROM
		 * CURRENT WC ATTRIBUTE SELECTION.
		 */
		function findCurrentVariation() {

			var variations =
				getVariations($form);

			var selections =
				getSelections($form);

			var best = null;

			$.each(
				variations,
				function (index, variation) {

					if (
						!variation ||
						!variation.attributes
					) {

						return;

					}

					var valid = true;

					$.each(
						selections,
						function (name, value) {

							if (
								!matchesVariationValue(
									variation.attributes[name],
									value
								)
							) {

								valid = false;

								return false;

							}

						}
					);

					if (!valid) {
						return;
					}

					if (
						selectedWeight &&
						!weightsMatch(
							variationWeightKey(variation),
							selectedWeight
						)
					) {

						return;

					}

					var chosenId = $form.data(
						'goldmateChosenVariationId'
					);

					if (
						chosenId &&
						String(variation.variation_id) ===
							String(chosenId)
					) {

						best = variation;

						return false;

					}

					/*
					 * Prefer exact match.
					 */
					if (!best) {
						best = variation;
					}

					var exact = true;

					$.each(
						selections,
						function (name, value) {

							if (
								variation.attributes[name] !==
								value
							) {

								exact = false;

							}

						}
					);

					if (exact && !selectedWeight) {

						best = variation;

						return false;

					}

					if (
						exact &&
						weightsMatch(
							variationWeightKey(variation),
							selectedWeight
						)
					) {

						best = variation;

						return false;

					}

				}
			);

			return best;
		}

		/*
		 * OPEN WEIGHT DIALOG
		 */
		$trigger.on(
			'click',
			function (event) {

				event.preventDefault();

				if ($trigger.hasClass('is-loading')) {
					return;
				}

				/*
				 * Only weights the chosen Size allows; the rest are
				 * left out rather than listed as unavailable.
				 */
				var options = $.grep(
					getAllWeightOptions($form),
					function (option) {
						return option.available;
					}
				);

				openDialog(
					WEIGHT_LABEL,
					options,
					selectedWeight,
					function (value, option) {

						setTriggerLoading($trigger, true);

						selectedWeight =
							String(value);

						/*
						 * Pin this exact variation.
						 * Size alone is not unique when
						 * two rows share سایز 58.
						 */
						if (
							option &&
							option.variation
						) {

							bindChosenVariation(
								option.variation
							);

						} else {

							var matched =
								findVariationByWeight(
									$form,
									value
								);

							if (matched) {
								bindChosenVariation(
									matched
								);
							}

						}

					},
					false
				);

			}
		);

		/*
		 * WooCommerce variation events.
		 */
		$form.on(
			'found_variation',
			function (
				event,
				variation
			) {

				if ($form.data('goldmateForcing')) {
					updateFromVariation(variation);
					return;
				}

				if (
					$form.data(
						'goldmateControllerChanging'
					)
				) {
					return;
				}

				var chosenId = $form.data(
					'goldmateChosenVariationId'
				);

				if (
					chosenId &&
					variation &&
					String(variation.variation_id) !==
						String(chosenId)
				) {

					var chosen = findVariationById(
						$form,
						chosenId
					);

					/*
					 * Only re-pin when the chosen weight
					 * still belongs to the current Size.
					 * Otherwise Size changes leave a stale
					 * weight from the previous Size.
					 */
					if (
						chosen &&
						variationMatchesControllers(
							$form,
							chosen
						)
					) {
						forceVariationOnForm(
							$form,
							chosen
						);
						return;
					}

					$form.removeData(
						'goldmateChosenVariationId'
					);
					selectedWeight = '';

				}

				updateFromVariation(
					variation
				);

			}
		);

		$form.on(
			'show_variation',
			function (
				event,
				variation
			) {

				if (
					$form.data(
						'goldmateControllerChanging'
					)
				) {
					return;
				}

				if ($form.data('goldmateForcing')) {
					updateFromVariation(variation);
					return;
				}

				updateFromVariation(
					variation
				);

			}
		);

		$form.on(
			'reset_data',
			function () {

				if (
					$form.data('goldmateApplying') ||
					$form.data('goldmateForcing') ||
					$form.data(
						'goldmateControllerChanging'
					)
				) {
					return;
				}

				var chosenId = $form.data(
					'goldmateChosenVariationId'
				);

				if (chosenId) {

					var chosen = findVariationById(
						$form,
						chosenId
					);

					if (
						chosen &&
						variationMatchesControllers(
							$form,
							chosen
						)
					) {
						return;
					}

				}

				clearChosenVariation();

			}
		);

		/*
		 * WooCommerce clears variation_id when more
		 * than one variation shares the selected Size.
		 * Put the chosen weight back afterwards —
		 * only if it still matches the current Size.
		 */
		$form.on(
			'check_variations',
			function () {

				var chosenId = $form.data(
					'goldmateChosenVariationId'
				);

				if (
					!chosenId ||
					$form.data('goldmateForcing') ||
					$form.data(
						'goldmateControllerChanging'
					)
				) {
					return;
				}

				setTimeout(function () {

					var chosen = findVariationById(
						$form,
						chosenId
					);

					if (
						!chosen ||
						!variationMatchesControllers(
							$form,
							chosen
						)
					) {
						$form.removeData(
							'goldmateChosenVariationId'
						);
						return;
					}

					var current = $form
						.find(
							'input[name="variation_id"], input.variation_id'
						)
						.first()
						.val();

					if (
						String(current) ===
						String(chosenId)
					) {
						enableAddToCart($form);
						return;
					}

					forceVariationOnForm(
						$form,
						chosen
					);

				}, 0);

			}
		);

		/*
		 * Keep weight in sync with Size.
		 * Shared by select change + Size modal apply.
		 */
		function syncWeightForControllers() {

			if (
				$form.data('goldmateApplying') ||
				$form.data('goldmateForcing') ||
				$form.data(
					'goldmateControllerChanging'
				)
			) {
				return;
			}

			var options =
				getAllWeightOptions($form);

			var available = $.grep(
				options,
				function (option) {
					return option.available;
				}
			);

			if (selectedWeight) {

				var stillValid = null;

				$.each(
					available,
					function (index, option) {

						if (
							weightsMatch(
								option.value,
								selectedWeight
							)
						) {
							stillValid = option;
							return false;
						}

					}
				);

				if (
					stillValid &&
					stillValid.variation
				) {

					bindChosenVariation(
						stillValid.variation
					);

					return;

				}

			}

			if (
				available.length === 1 &&
				available[0].variation
			) {

				bindChosenVariation(
					available[0].variation
				);

				return;

			}

			clearChosenVariation();

		}

		/*
		 * When Size changes, keep the weight only if
		 * that Size still has it. Two weights on the
		 * same Size must not collapse to the first.
		 */
		$form.on(
			'change',
			'.variations select',
			function () {

				if (
					$form.data(
						'goldmateControllerChanging'
					)
				) {
					return;
				}

				syncWeightForControllers();

			}
		);

		$form.on(
			'goldmate_sync_weight',
			function () {
				syncWeightForControllers();
			}
		);

		/*
		 * Initial state.
		 */
		var initialVariation =
			findCurrentVariation();

		if (initialVariation) {

			updateFromVariation(
				initialVariation
			);

		}

	}

	/*
	|--------------------------------------------------------------------------
	| AUTO SELECT FIRST CONTROLLER
	|--------------------------------------------------------------------------
	|
	| If Size exists:
	|
	| automatically select the first available Size.
	|
	*/

	function autoSelectFirstController($form) {

		var $controllerSelect =
			$form
				.find('.variations select')
				.filter(function () {

					return isControllingAttribute(
						getRowLabel(
							$(this).closest('tr')
						)
					);

				})
				.first();

		if (!$controllerSelect.length) {
			return;
		}

		/*
		 * Already selected.
		 */
		if ($controllerSelect.val()) {
			return;
		}

		/*
		 * Find first usable option.
		 */
		var selectedValue = '';

		$controllerSelect
			.find('option')
			.each(function () {

				var value =
					$(this).val();

				if (!value) {
					return;
				}

				if (
					$(this).prop('disabled') ||
					$(this).prop('hidden')
				) {

					return;
				}

				selectedValue = value;

				return false;

			});

		/*
		 * If WooCommerce has not populated
		 * options yet, use variation data.
		 */
		if (!selectedValue) {

			var variations =
				getVariations($form);

			var attributeName =
				$controllerSelect.attr(
					'name'
				);

			$.each(
				variations,
				function (index, variation) {

					if (
						!variation ||
						!variation.attributes
					) {

						return;
					}

					var value =
						variation.attributes[
							attributeName
						];

					if (
						typeof value === 'undefined' ||
						value === ''
					) {

						return;
					}

					selectedValue = value;

					return false;

				}
			);

		}

		if (!selectedValue) {
			return;
		}

		var $option =
			$controllerSelect.find(
				'option[value="' +
				escapeValue(selectedValue) +
				'"]'
			);

		if (!$option.length) {

			$option =
				$('<option></option>')
					.attr(
						'value',
						selectedValue
					)
					.text(selectedValue);

			$controllerSelect.append(
				$option
			);

		}

		$option
			.prop('disabled', false)
			.prop('hidden', false);

		$controllerSelect
			.val(selectedValue)
			.trigger('change');

	}

	/*
	|--------------------------------------------------------------------------
	| REMOVE UNWANTED ROW BORDERS
	|--------------------------------------------------------------------------
	*/

	function styleWeightRow($form) {

		var $row =
			$form.find(
				'.goldmate-vd-weight-row'
			);

		if (!$row.length) {
			return;
		}

		$row.css({
			'border-top': '0',
			'border-bottom': '0'
		});

		$row
			.children('th, td')
			.css({
				'border-top': '0',
				'border-bottom': '0',
				'padding-left': '0',
				'padding-right': '0'
			});

	}

	/*
	|--------------------------------------------------------------------------
	| SUMMARY PRICE
	|--------------------------------------------------------------------------
	|
	| The top price is the parent's range and never follows the choice, while
	| WC prints the chosen price again under the choosers. Put the chosen
	| price in the top price and hide the one below.
	|
	*/

	function setupSummaryPrice($form) {

		var $price = $form.siblings('p.price, .price').not('.price-unit').first();
		var original = $price.length ? $price.html() : null;

		function sync(variation) {

			$form.find('.woocommerce-variation-price').css('display', 'none');

			if (!$price.length) {
				return;
			}

			var html = variation && variation.price_html
				? $('<div/>').html(variation.price_html).find('.price').first().html()
				: null;

			$price.html(html || original);
		}

		$form.on('show_variation found_variation', function (event, variation) {
			sync(variation);
		});

		$form.on('hide_variation reset_data', function () {
			sync(null);
		});
	}

	/*
	|--------------------------------------------------------------------------
	| FINAL PRICE (above the add-to-cart row)
	|--------------------------------------------------------------------------
	|
	| Mirrors the summary price (already synced to the chosen variation) or,
	| when an accessory is picked, the accessory total. A variable product with
	| no variation chosen only has a price range, so nothing is shown.
	|
	*/

	/*
	 * Small (i) button beside the final-price label with a floating popover
	 * explaining how the price is built. Hover or focus previews it; a click
	 * pins it open until an outside click or Esc. Text comes from the
	 * appearance tab (empty = no icon).
	 */
	var priceInfoSeq = 0;

	// Icon choices from the appearance tab; '' keeps the letter "i".
	var priceInfoIcons = {
		info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
		info_fill: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2zm0-8h-2V7h2z"/></svg>',
		question: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>'
	};

	function setupPriceInfo($box) {

		var config = window.goldmateProductUiConfig || {};
		var text = config.priceInfo ? String(config.priceInfo) : '';

		if (!text) {
			return;
		}

		var popId = 'goldmate-price-info-' + (++priceInfoSeq);
		var $label = $box.find('.goldmate-final-price-label');
		var $btn = $('<button type="button" class="goldmate-price-info-btn" aria-expanded="false">i</button>');
		var $pop = $('<span class="goldmate-price-info-pop" role="tooltip" hidden></span>').attr('id', popId).text(text);

		$btn.attr('aria-label', config.priceInfoLabel || '').attr('aria-describedby', popId);

		if (priceInfoIcons[config.priceInfoIcon]) {
			$btn.addClass('is-svg').html(priceInfoIcons[config.priceInfoIcon]);
		}

		$label.wrap('<span class="goldmate-final-price-head"></span>');
		$label.after($btn);

		var $head = $box.find('.goldmate-final-price-head');
		var pinned = false;
		var canHover = 'click' !== config.priceInfoTrigger && window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

		$head.append($pop);

		// Point the arrow at the button: offset from the head's inline start.
		function placeArrow() {
			var head = $head[0].getBoundingClientRect();
			var btn = $btn[0].getBoundingClientRect();
			var center = btn.left + btn.width / 2;
			var rtl = 'rtl' === $head.css('direction');
			$pop[0].style.setProperty('--goldmate-info-arrow', Math.round(rtl ? head.right - center : center - head.left) + 'px');
		}

		function show(open) {
			$btn.attr('aria-expanded', open ? 'true' : 'false');
			if (open) {
				$pop.removeAttr('hidden');
				placeArrow();
			} else {
				pinned = false;
				$pop.attr('hidden', 'hidden');
			}
		}

		$btn.on('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			pinned = !pinned || $pop.is('[hidden]');
			show(pinned);
		}).on('focus', function () {
			show(true);
		}).on('blur', function () {
			if (!pinned) {
				show(false);
			}
		});

		if (canHover) {
			$head.on('mouseenter', function () {
				show(true);
			}).on('mouseleave', function () {
				if (!pinned && !$btn.is(':focus')) {
					show(false);
				}
			});
		}

		$(document).on('click', function (event) {
			if (!$(event.target).closest($head).length) {
				show(false);
			}
		}).on('keydown', function (event) {
			if ('Escape' === event.key && !$pop.is('[hidden]')) {
				show(false);
			}
		});
	}

	function setupFinalPrice($form) {

		if ($form.find('.goldmate-final-price').length) {
			return;
		}

		var $row = $form.find('.woocommerce-variation-add-to-cart').first();

		if (!$row.length) {
			$row = $form;
		}

		var $qty = $row.children('.quantity').first();
		var $box = $('<div class="goldmate-final-price" hidden><span class="goldmate-final-price-label"></span><span class="goldmate-final-price-amount"></span></div>');

		$box.find('.goldmate-final-price-label').text(I18N.finalPrice);

		setupPriceInfo($box);

		if ($qty.length) {
			$qty.before($box);
		} else {
			$row.prepend($box);
		}

		function topPrice() {
			return $form.closest('.summary, .product').find('p.price, .price').not('.price-unit').filter(function () {
				return !$(this).closest('form, .wd-product-nav-desc, .related, .upsells').length;
			}).first();
		}

		function refresh() {

			var $total = $form.find('.goldmate-accessory-total:not([hidden]) .amount').first();
			var isVariable = $form.hasClass('variations_form');
			var chosen = !isVariable || parseInt($form.find('input.variation_id').val(), 10) > 0;
			var html = null;

			if ($total.length) {
				html = $('<span/>').text($total.text()).html();
			} else if (chosen) {
				var $p = topPrice();
				html = $p.length ? $('<div/>').html($p.html()).find('.screen-reader-text').remove().end().html() : null;
			}

			if (!html) {
				$box.attr('hidden', 'hidden');
				return;
			}

			$box.find('.goldmate-final-price-amount').html(html);
			$box.removeAttr('hidden');
		}

		function later() {
			setTimeout(refresh, 0);
		}

		$form.on('show_variation found_variation hide_variation reset_data change', later);
		$form.on('change', '.goldmate-accessory-select', later);

		var $watch = topPrice();

		if ($watch.length && window.MutationObserver) {
			new MutationObserver(later).observe($watch[0], {childList: true, subtree: true, characterData: true});
		}

		refresh();
	}

	function setupClearButton($form) {

		/* Hidden: every choice here is required, so clearing them serves no purpose. */
		$form.find('.wd-reset-var, .reset_variations').css('display', 'none');
	}

	/*
	|--------------------------------------------------------------------------
	| SETUP FORM
	|--------------------------------------------------------------------------
	*/


	/*
	|--------------------------------------------------------------------------
	| ACCESSORY GROUPS (GoldMate linked products)
	| Same trigger + modal chrome as variation attributes.
	|--------------------------------------------------------------------------
	*/

	function setupAccessoryGroups($form) {

		$form.find('.goldmate-accessory-group').each(function () {

			var $group = $(this);

			if ($group.hasClass('goldmate-vd-enhanced')) {
				return;
			}

			var $select = $group.children('select.goldmate-accessory-select');

			if (!$select.length) {
				return;
			}

			var label = $.trim(
				$group.children('.goldmate-accessory-label').first().text()
			);

			$group.addClass('goldmate-vd-enhanced goldmate-vd-clean-row');

			$group.children('.goldmate-accessory-label').attr('hidden', true).attr('aria-hidden', 'true').css('display', 'none');

			if (!$group.children('.goldmate-vd-heading').length) {
				$group.prepend(
					$('<div class="goldmate-vd-heading"></div>').text(
						label ? ('انتخاب ' + label) : I18N.choose
					)
				);
			}

			var $trigger = $(
				'<button type="button" class="goldmate-vd-trigger"></button>'
			);

			var $triggerValue = $(
				'<span class="goldmate-vd-trigger-value"></span>'
			);

			var $triggerPrice = $(
				'<span class="goldmate-vd-trigger-price"></span>'
			);

			$trigger.append($triggerValue, $triggerPrice);
			$group.children('.goldmate-vd-heading').after($trigger);

			function syncTrigger() {

				var $opt = $select.find('option:selected');
				var text = $.trim($opt.text() || I18N.choose);
				var price = parseFloat($opt.data('price')) || 0;
				var shortLabel = text.split(' — ')[0] || text;

				$triggerValue.text(shortLabel || I18N.choose);

				if (price > 0) {
					$triggerPrice.text($opt.text().split(' — ').slice(-1)[0] || '');
				} else {
					$triggerPrice.text('');
				}
			}

			$trigger.on('click', function (event) {

				event.preventDefault();

				var options = [];

				$select.find('option').each(function () {

					var $opt = $(this);
					var value = $opt.attr('value');
					var price = parseFloat($opt.data('price')) || 0;
					var raw = $.trim($opt.text());
					var optionLabel = raw.split(' — ')[0] || raw;
					var priceBit = raw.indexOf(' — ') !== -1
						? raw.split(' — ').slice(1).join(' — ')
						: '';

					options.push({
						value: value,
						label: optionLabel,
						available: true,
						variation: priceBit
							? { price_html: $('<div>').text(priceBit).html() }
							: null
					});
				});

				openDialog(
					label ? ('انتخاب ' + label) : I18N.choose,
					options,
					$select.val(),
					function (value) {
						$select.val(value).trigger('change');
						syncTrigger();
					},
					false
				);
			});

			$select.on('change.goldmateVdAccessory', syncTrigger);
			syncTrigger();
		});
	}

	function setupForm($form) {

		if (
			$form.data(
				'goldmateVdInit'
			)
		) {

			return;

		}

		$form.data(
			'goldmateVdInit',
			true
		);

		/*
		 * Enhance ONLY real WC attribute rows.
		 *
		 * Weight is intentionally excluded because
		 * it is now custom GoldMate data.
		 */
		$form
			.find(
				'.variations > tbody > tr'
			)
			.each(function () {

				var $row =
					$(this);

				var label =
					getRowLabel($row);

				if (
					!isTargetLabel(label)
				) {

					return;

				}

				if (
					isWeightAttribute(label)
				) {

					/*
					 * Native WC "وزن" row is replaced by
					 * setupWeightRow(). Hide it so shoppers
					 * don't see a bare "وزن" label with no UI.
					 */
					$row
						.attr('hidden', true)
						.attr('aria-hidden', 'true')
						.addClass('goldmate-vd-native-weight-hidden')
						/* Woodmart's label-top layout sets the rows to flex, which beats [hidden]. */
						.css('display', 'none');

					/* Keep the <select> enabled so WC matching /
					 * applyVariationAttributes can still set it. */

					return;

				}

				setupNormalRow(
					$form,
					$row
				);

			});

		/*
		 * Create custom Weight selector.
		 */
		setupWeightRow($form);

		/*
		 * Move Clear into a dedicated row under selectors.
		 */
		setupClearButton($form);

		setupAccessoryGroups($form);

		setupSummaryPrice($form);

		setupFinalPrice($form);

		/*
		 * Automatically select first Size.
		 */
		setTimeout(function () {

			autoSelectFirstController(
				$form
			);

			styleWeightRow($form);

		}, 100);

	}

	/*
	|--------------------------------------------------------------------------
	| SCAN
	|--------------------------------------------------------------------------
	*/

	function scan() {

		$('.variations_form').each(
			function () {

				setupForm(
					$(this)
				);

			}
		);

		/* Simple products: accessories without a variations form. */
		$('form.cart').not('.variations_form').each(function () {
			setupAccessoryGroups($(this));
			setupFinalPrice($(this));
		});

	}

	/*
	|--------------------------------------------------------------------------
	| INIT
	|--------------------------------------------------------------------------
	*/

	/* Cart icon on the add-to-cart button (styled/hidden from CSS). */
	function addCartIcons() {

		$('.single_add_to_cart_button').each(function () {

			if (!this.querySelector('.goldmate-atc-icon')) {
				$(this).prepend('<span class="goldmate-atc-icon" aria-hidden="true"></span>');
			}

		});

	}

	/* Icon-only wishlist/compare still need an accessible name and tooltip. */
	function labelActionButtons() {

		/* One flex row holds both buttons so the admin can split or size them. */
		$('.summary').each(function () {

			var $btns = $(this).find('.wd-compare-btn, .wd-wishlist-btn').filter(function () {
				return !$(this).closest('form, .related, .upsells').length && !$(this).parent().hasClass('goldmate-action-row');
			});

			if ($btns.length) {
				var $row = $('<div class="goldmate-action-row"></div>');
				$btns.first().before($row);
				$row.append($btns);
			}

		});

		$('.summary .wd-wishlist-btn a, .summary .wd-compare-btn a').each(function () {

			var label = $.trim($(this).find('.wd-action-text').text());

			if (label && !this.getAttribute('title')) {
				this.setAttribute('title', label);
				this.setAttribute('aria-label', label);
			}

		});

	}

	$(function () {

		scan();
		addCartIcons();
		labelActionButtons();

	});

	/*
	|--------------------------------------------------------------------------
	| WOODMART QUICK VIEW / QUICK SHOP
	|--------------------------------------------------------------------------
	*/

	$(document).on(
		'wdQuickViewOpen wdQuickShopSuccess',
		function () {

			setTimeout(
				function () {
					scan();
					addCartIcons();
					labelActionButtons();
				},
				100
			);

		}
	);

})(window.jQuery);