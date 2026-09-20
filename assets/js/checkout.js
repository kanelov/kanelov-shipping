/* global ksCheckout, jQuery, KSDeliveryForm */
/* Kanelov Shipping – класически чекаут. Блок „Доставка“: куриер (карти) → вид доставка (икони) → населено място → офис/Еконтомат или адрес.
 * Картата на куриера избира ставката му в WooCommerce. Видът се праща като ks_type при всяко обновяване на прегледа;
 * сървърът го пази в сесията и сменя цената на ставката. */
(function ($) {
	'use strict';
	var cfg = window.ksCheckout || {};
	var STORAGE_KEY = 'ks_delivery';
	var TYPES = ['office', 'locker', 'door'];
	var root, form, cityId, carrierForm, options = {};

	function rateInputs() { return Array.prototype.slice.call(document.querySelectorAll('input[name^="shipping_method"]')); }
	function rateFor(methodId) {
		return rateInputs().filter(function (i) { return String(i.value).split(':')[0] === methodId; })[0] || null;
	}
	function chosenRate() {
		return document.querySelector('input[name^="shipping_method"]:checked') || document.querySelector('input[name^="shipping_method"][type="hidden"]');
	}
	function econtAvailable() { return !!rateFor(cfg.methodId); }
	function econtChosen() {
		var input = chosenRate();
		return !!input && String(input.value).split(':')[0] === cfg.methodId;
	}
	/* Всички ставки са на наши куриери (ks_*) → изборът е само в блока, в прегледа остава избраният ред. */
	function onlyOurRates() {
		var inputs = rateInputs();
		return inputs.length > 0 && inputs.every(function (i) { return String(i.value).indexOf('ks_') === 0; });
	}

	function getType() {
		var r = root.querySelector('input.ks-type:checked');
		return r ? r.value : '';
	}
	function setType(type) {
		var r = root.querySelector('input.ks-type[value="' + type + '"]');
		if (r && !r.disabled) { r.checked = true; return true; }
		return false;
	}
	function state() {
		var s = {};
		root.querySelectorAll('[name^="ks_"]').forEach(function (i) {
			if (i.type === 'radio' && !i.checked) return;
			s[i.name.replace(/^ks_/, '')] = i.value;
		});
		return s;
	}
	function remember() { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state())); } catch (e) { /* private mode */ } }
	function recall() {
		try { var s = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); if (s && (s.city_id || s.type)) return s; } catch (e) { /* ignore */ }
		return cfg.saved && (cfg.saved.city_id || cfg.saved.type) ? cfg.saved : null;
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

	/* Наличните видове и цените идват от ставката (фрагмент #ks-type-options при всяко обновяване). */
	function readOptions() {
		var el = document.getElementById('ks-type-options');
		try { options = el ? (JSON.parse(el.textContent) || {}) : {}; } catch (e) { options = {}; }
		var min = null;
		TYPES.forEach(function (t) {
			var opt = root.querySelector('.ks-type-option[data-type="' + t + '"]'); if (!opt) return;
			var available = !!options[t];
			opt.hidden = !available;
			opt.querySelector('input').disabled = !available;
			opt.querySelector('.ks-type-option__price').textContent = available ? options[t].price : '';
			if (available && (min === null || options[t].cost < min.cost)) min = options[t];
		});
		var sub = root.querySelector('.ks-carrier-option[data-method="' + cfg.methodId + '"] .ks-carrier-option__sub');
		if (sub) sub.textContent = min ? (min.cost > 0 ? (cfg.i18n.from || 'от') + ' ' + min.price : min.price) : '';
	}

	/* Куриерите: коя карта е избрана, коя ставка е налична. */
	function refreshCarriers() {
		var chosen = chosenRate();
		var chosenMethod = chosen ? String(chosen.value).split(':')[0] : '';
		root.querySelectorAll('.ks-carrier-option[data-method]').forEach(function (o) {
			var input = rateFor(o.dataset.method);
			o.hidden = !input;
			o.classList.toggle('is-selected', !!input && o.dataset.method === chosenMethod);
			o.setAttribute('aria-pressed', o.dataset.method === chosenMethod ? 'true' : 'false');
		});
		var hide = onlyOurRates();
		document.body.classList.toggle('ks-hide-rates', hide);
		if (hide) {
			rateInputs().forEach(function (i) {
				var li = i.closest('li'); if (li) li.classList.toggle('ks-current', i === chosen);
			});
		}
	}

	/* Кои стъпки се виждат: населено място след избран вид, офис/адрес след избрано населено място. */
	function refreshSteps() {
		var type = getType();
		var hasCity = !!cityId.value;
		root.querySelectorAll('.ks-type-option').forEach(function (o) { o.classList.toggle('is-selected', o.dataset.type === type); });
		if (type) form.applyType(type);
		root.querySelector('.ks-step--city').hidden = !type;
		root.querySelectorAll('.ks-step--place').forEach(function (s) {
			var forOffice = s.classList.contains('ks-section--office');
			s.hidden = !type || !hasCity || (forOffice ? type === 'door' : type !== 'door');
		});
	}

	function apply() {
		var available = econtAvailable();
		var econt = available && econtChosen();
		root.hidden = !available;
		document.body.classList.toggle('ks-econt-selected', econt);
		toggleRequired(econt);
		if (!available) { document.body.classList.remove('ks-hide-rates'); return; }
		refreshCarriers();
		carrierForm.hidden = !econt;
		if (!econt) return;
		readOptions();
		if (!getType()) {
			var saved = recall();
			setType((saved && saved.type) || cfg.defaultType || '');
		}
		refreshSteps();
	}

	function init() {
		root = document.getElementById('ks-delivery');
		if (!root || root.dataset.ksReady) return;
		root.dataset.ksReady = '1';
		cityId = root.querySelector('.ks-city-id');
		carrierForm = root.querySelector('.ks-carrier-form');

		var saved = recall();
		if (saved) {
			Object.keys(saved).forEach(function (k) {
				if (k === 'type' || !saved[k]) return;
				var i = root.querySelector('[name="ks_' + k + '"]'); if (!i) return;
				if (i.type === 'radio') { var r = root.querySelector('[name="ks_' + k + '"][value="' + saved[k] + '"]'); if (r) r.checked = true; return; }
				if (!i.value) i.value = saved[k];
			});
			var officeInput = root.querySelector('.ks-office'), sel = root.querySelector('.ks-office-selected');
			if (saved.office_code) { officeInput.value = saved.office_name; sel.hidden = false; sel.textContent = '✓ ' + saved.office_name; }
		}
		form = KSDeliveryForm.mount(root, { rest: cfg.rest, i18n: cfg.i18n, map: cfg.map, getType: getType, onChange: function () { remember(); refreshSteps(); } });
		root.addEventListener('input', refreshSteps);

		/* Карта на куриер: избира ставката му; WooCommerce обновява прегледа при change. */
		root.addEventListener('click', function (e) {
			var card = e.target.closest('.ks-carrier-option[data-method]'); if (!card) return;
			var input = rateFor(card.dataset.method); if (!input || input.checked) return;
			input.checked = true;
			$(input).trigger('change');
			apply();
		});

		/* Смяна на вида: нова цена на ставката и следваща стъпка. */
		root.addEventListener('change', function (e) {
			if (!e.target.classList.contains('ks-type')) return;
			remember();
			refreshSteps();
			$(document.body).trigger('update_checkout');
		});

		apply();
		/* Запомнен избор (localStorage) различен от този на сървъра → преизчисли ставката. */
		if (econtChosen() && getType() && getType() !== (root.dataset.type || '')) {
			$(document.body).trigger('update_checkout');
		}
	}

	$(document.body).on('updated_checkout', function () { init(); apply(); });
	$(document.body).on('change', 'input[name^="shipping_method"]', function () { if (root) apply(); });
	$(init);
})(jQuery);
