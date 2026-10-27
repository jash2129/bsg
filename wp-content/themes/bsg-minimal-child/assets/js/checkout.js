/* Checkout: choose a saved address or enter a new one. */
(function ($) {
	'use strict';

	var saved = window.bsgAddresses || [];
	var $form = $('form.checkout');
	if (!$form.length) return;

	var map = {
		first: '#billing_first_name',
		last: '#billing_last_name',
		phone: '#billing_phone',
		line1: '#billing_address_1',
		line2: '#billing_address_2',
		city: '#billing_city',
		state: '#billing_state',
		pincode: '#billing_postcode'
	};

	function setField(sel, val) {
		var $el = $(sel);
		if (!$el.length) return;
		$el.val(val).trigger('change');
	}

	function apply(id) {
		var isNew = id === 'new';
		$form.toggleClass('bsg-saved', !isNew);
		$('#bsg-save-addr').toggle(isNew);
		if (!isNew) {
			var a = saved.filter(function (x) { return x.id === id; })[0];
			if (!a) return;
			setField('#billing_country', 'IN');
			Object.keys(map).forEach(function (k) { setField(map[k], a[k] || ''); });
		} else {
			Object.keys(map).forEach(function (k) { setField(map[k], ''); });
			$('#billing_first_name').trigger('focus');
		}
		$(document.body).trigger('update_checkout');
	}

	$(document).on('change', 'input[name="bsg_pick"]', function () {
		apply(this.value);
	});

	// Highlight the chosen card.
	$(document).on('change', 'input[name="bsg_pick"]', function () {
		$('.bsg-pick-card').removeClass('is-on');
		$(this).closest('.bsg-pick-card').addClass('is-on');
	});

	$(function () {
		var $on = $('input[name="bsg_pick"]:checked');
		if (!$on.length && saved.length) $on = $('input[name="bsg_pick"]').first().prop('checked', true);
		if ($on.length) {
			$on.closest('.bsg-pick-card').addClass('is-on');
			apply($on.val());
		} else {
			$('#bsg-save-addr').show();
		}
	});

	// Mobile number: digits only.
	$(document).on('input', '#billing_phone', function () {
		var v = this.value.replace(/[^\d+\s]/g, '');
		if (v !== this.value) this.value = v;
	});
})(jQuery);
