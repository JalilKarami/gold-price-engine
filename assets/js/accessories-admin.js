(function ($) {
	'use strict';

	function initProductSearch($context) {
		if (!$context || !$context.length || typeof $context.filterWCSelect2 !== 'function') {
			// WooCommerce enhanced select: initialise via trigger used by product meta boxes.
			$(document.body).trigger('wc-enhanced-select-init');
			return;
		}
	}

	function reindexNames($group, index) {
		$group.find('[name]').each(function () {
			var $el = $(this);
			var name = $el.attr('name');
			if (!name) {
				return;
			}
			$el.attr(
				'name',
				name.replace(/goldmate_accessory_groups\[[^\]]+]/, 'goldmate_accessory_groups[' + index + ']')
			);
		});
	}

	$(function () {
		var $wrap = $('#goldmate-accessory-groups');
		if (!$wrap.length) {
			return;
		}

		$('#goldmate-add-accessory-group').on('click', function (e) {
			e.preventDefault();

			var next = parseInt($wrap.attr('data-next-index'), 10) || 0;
			var $proto = $('#goldmate-accessory-group-prototype .goldmate-accessory-group').first();
			if (!$proto.length) {
				return;
			}

			var $clone = $proto.clone(false, false);
			$clone.find('input, select').prop('disabled', false);
			$clone.find('select.wc-product-search').removeClass('enhanced').show().val(null);
			$clone.find('.select2-container').remove();
			reindexNames($clone, next);
			$wrap.append($clone);
			$wrap.attr('data-next-index', next + 1);
			$(document.body).trigger('wc-enhanced-select-init');
		});

		$wrap.on('click', '.goldmate-remove-accessory-group', function (e) {
			e.preventDefault();
			var $groups = $wrap.find('> .goldmate-accessory-group');
			if ($groups.length <= 1) {
				$groups.find('input[type="text"]').val('');
				$groups.find('select.wc-product-search').val(null).trigger('change');
				return;
			}
			$(this).closest('.goldmate-accessory-group').remove();
		});
	});
})(jQuery);
