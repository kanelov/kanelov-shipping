/* global ksCheckout, jQuery, KSDeliveryForm */
(function ($) {
	'use strict';
	var cfg = window.ksCheckout || {};
	var STORAGE_KEY = 'ks_delivery';
	var root, form, typeInput, typeLabels = {};

	function chosenType() {
		var input = document.querySelector('input[name^="shipping_method"]:checked') || document.querySelector('input[name^="shipping_method"][type="hidden"]');
		if (!input) return null;
		var parts = String(input.value).split(':');
		if (parts[0] !== cfg.methodId) return null;
		var t = parts[parts.length - 1];
		return ['office', 'locker', 'door'].indexOf(t) >= 0 ? t : null;
	}
	function state() {
		var s = {};
		root.querySelectorAll('[name^="ks_"]').forEach(function (i) { s[i.name.replace(/^ks_/, '')] = i.value; });
		return s;
	}
	function remember() { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state())); } catch (e) { /* private mode */ } }
	function recall() {
		try { var s = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); if (s && s.city_id) return s; } catch (e) { /* ignore */ }
		return cfg.saved && cfg.saved.city_id ? cfg.saved : null;
	}
	var HIDDEN = ['billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode'];
	var requiredMemo = {};
	function toggleRequired(econt) {
		HIDDEN.forEach(function (id) {
			var row = document.getElementById(id + '_field'); if (!row) return;
			if (!(id in requiredMemo)) requiredMemo[id] = row.classList.contains('validate-required');
			if (!requiredMemo[id]) return;
			row.classList.toggle('validate-required', !econt);
		});
	}
	function applyType() {
		var type = chosenType();
		document.body.classList.toggle('ks-econt-selected', !!type);
		toggleRequired(!!type);
		root.hidden = !type;
		if (!type) return;
		typeInput.value = type;
		root.querySelector('.ks-delivery__type').textContent = typeLabels[type] ? '(' + typeLabels[type] + ')' : '';
		form.applyType(type);
	}
	function init() {
		root = document.getElementById('ks-delivery');
		if (!root || root.dataset.ksReady) return;
		root.dataset.ksReady = '1';
		typeInput = root.querySelector('.ks-type');
		try { typeLabels = JSON.parse(root.querySelector('.ks-type-labels').textContent); } catch (e) { /* ignore */ }
		var saved = recall();
		if (saved) {
			Object.keys(saved).forEach(function (k) {
				var i = root.querySelector('[name="ks_' + k + '"]'); if (i && saved[k] && !i.value) i.value = saved[k];
			});
			var officeInput = root.querySelector('.ks-office'), sel = root.querySelector('.ks-office-selected');
			if (saved.office_code) { officeInput.value = saved.office_name; sel.hidden = false; sel.textContent = '✓ ' + saved.office_name; }
		}
		form = KSDeliveryForm.mount(root, { rest: cfg.rest, i18n: cfg.i18n, getType: function () { return typeInput.value; }, onChange: remember });
		applyType();
	}
	$(document.body).on('updated_checkout', function () { init(); applyType(); });
	$(document.body).on('change', 'input[name^="shipping_method"]', function () { if (root) applyType(); });
	$(init);
})(jQuery);
