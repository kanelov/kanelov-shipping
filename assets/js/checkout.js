/* global ksCheckout, jQuery, KSDeliveryForm */
/* Kanelov Shipping – класически чекаут. Блок „Доставка“: куриер (карти) → за Еконт вид доставка (икони) → населено място
 * → офис/Еконтомат/автомат или адрес. Картата на куриера избира ставката му в WooCommerce. Видът на Еконт се праща като
 * ks_type при всяко обновяване на прегледа; сървърът го пази в сесията и сменя цената на ставката. */
(function ($) {
	'use strict';
	var cfg = window.ksCheckout || {};
	var TYPES = ['office', 'locker', 'door'];
	var carriers = cfg.carriers || { econt: { method: cfg.methodId, types: TYPES } };
	var root, options = {}, forms = {};

	function rateInputs() { return Array.prototype.slice.call(document.querySelectorAll('input[name^="shipping_method"]')); }
	function rateFor(methodId) {
		return rateInputs().filter(function (i) { return String(i.value).split(':')[0] === methodId; })[0] || null;
	}
	function chosenRate() {
		return document.querySelector('input[name^="shipping_method"]:checked') || document.querySelector('input[name^="shipping_method"][type="hidden"]');
	}
	function carrierForMethod(methodId) {
		return Object.keys(carriers).filter(function (id) { return carriers[id].method === methodId; })[0] || '';
	}
	function anyAvailable() {
		return Object.keys(carriers).some(function (id) { return !!rateFor(carriers[id].method); });
	}
	function chosenCarrier() {
		var input = chosenRate();
		return input ? carrierForMethod(String(input.value).split(':')[0]) : '';
	}
	/* Всички ставки са на наши куриери (ks_*) → изборът е само в блока, в прегледа остава избраният ред. */
	function onlyOurRates() {
		var inputs = rateInputs();
		return inputs.length > 0 && inputs.every(function (i) { return String(i.value).indexOf('ks_') === 0; });
	}

	/* Форма на куриер: root елемент, префикс на полетата, вид доставка. */
	function Form(el, carrier) {
		this.el = el; this.carrier = carrier;
		this.prefix = el.dataset.prefix || 'ks_';
		this.storageKey = carrier === 'econt' ? 'ks_delivery' : 'ks_delivery_' + carrier;
		this.cityId = el.querySelector('.ks-city-id');
		this.fixedType = carrier === 'econt' ? '' : 'locker';
	}
	Form.prototype.getType = function () {
		if (this.fixedType) return this.fixedType;
		var r = this.el.querySelector('input.ks-type:checked');
		return r ? r.value : '';
	};
	Form.prototype.setType = function (type) {
		var r = this.el.querySelector('input.ks-type[value="' + type + '"]');
		if (r && !r.disabled) { r.checked = true; return true; }
		return false;
	};
	Form.prototype.state = function () {
		var s = {}, p = this.prefix;
		this.el.querySelectorAll('[name^="' + p + '"]').forEach(function (i) {
			if (i.type === 'radio' && !i.checked) return;
			s[i.name.slice(p.length)] = i.value;
		});
		return s;
	};
	Form.prototype.remember = function () { try { localStorage.setItem(this.storageKey, JSON.stringify(this.state())); } catch (e) { /* private mode */ } };
	Form.prototype.recall = function () {
		try { var s = JSON.parse(localStorage.getItem(this.storageKey) || 'null'); if (s && (s.city_id || s.type || s.office_code)) return s; } catch (e) { /* ignore */ }
		var saved = this.carrier === 'econt' ? cfg.saved : cfg.savedBoxNow;
		return saved && (saved.city_id || saved.type || saved.office_code) ? saved : null;
	};
	Form.prototype.restore = function () {
		var saved = this.recall(), el = this.el, p = this.prefix;
		if (!saved) return;
		Object.keys(saved).forEach(function (k) {
			if (k === 'type' || !saved[k]) return;
			var i = el.querySelector('[name="' + p + k + '"]'); if (!i) return;
			if (i.type === 'radio') { var r = el.querySelector('[name="' + p + k + '"][value="' + saved[k] + '"]'); if (r) r.checked = true; return; }
			if (!i.value) i.value = saved[k];
		});
		var officeInput = el.querySelector('.ks-office'), sel = el.querySelector('.ks-office-selected');
		if (saved.office_code && officeInput && sel) { officeInput.value = saved.office_name; sel.hidden = false; sel.textContent = '✓ ' + saved.office_name; }
	};
	/* Кои стъпки се виждат: населено място след избран вид, офис/адрес след избрано населено място. */
	Form.prototype.refreshSteps = function () {
		var type = this.getType(), hasCity = !!(this.cityId && this.cityId.value), el = this.el;
		el.querySelectorAll('.ks-type-option').forEach(function (o) { o.classList.toggle('is-selected', o.dataset.type === type); });
		if (type && this.mounted) this.mounted.applyType(type);
		var city = el.querySelector('.ks-step--city'); if (city) city.hidden = !type;
		el.querySelectorAll('.ks-step--place').forEach(function (s) {
			var forOffice = s.classList.contains('ks-section--office');
			s.hidden = !type || !hasCity || (forOffice ? type === 'door' : type !== 'door');
		});
	};
	Form.prototype.mount = function () {
		var self = this, c = this.carrier === 'econt' ? cfg : (cfg.boxnow || {});
		this.restore();
		this.mounted = KSDeliveryForm.mount(this.el, {
			rest: c.rest, sync: c.sync, i18n: c.i18n || cfg.i18n, map: c.map, searchAll: !!this.fixedType,
			getType: function () { return self.getType(); },
			onChange: function () { self.remember(); self.refreshSteps(); }
		});
		this.el.addEventListener('input', function () { self.refreshSteps(); });
		/* Смяна на вида (Еконт): нова цена на ставката и следваща стъпка. */
		this.el.addEventListener('change', function (e) {
			if (!e.target.classList.contains('ks-type')) return;
			self.remember();
			self.refreshSteps();
			$(document.body).trigger('update_checkout');
		});
	};

	var HIDDEN = ['billing_company', 'billing_country', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode'];
	var requiredMemo = {};
	function toggleRequired(ours) {
		HIDDEN.forEach(function (id) {
			var row = document.getElementById(id + '_field'); if (!row) return;
			if (!(id in requiredMemo)) requiredMemo[id] = row.classList.contains('validate-required');
			if (!requiredMemo[id]) return;
			row.classList.toggle('validate-required', !ours);
		});
	}

	/* Наличните видове и цените идват от ставките (фрагмент #ks-type-options при всяко обновяване), по куриер. */
	function readOptions() {
		var el = document.getElementById('ks-type-options');
		try { options = el ? (JSON.parse(el.textContent) || {}) : {}; } catch (e) { options = {}; }
		Object.keys(carriers).forEach(function (carrier) {
			var opts = options[carrier] || {}, min = null, form = forms[carrier];
			Object.keys(opts).forEach(function (t) {
				if (min === null || opts[t].cost < min.cost) min = opts[t];
				if (!form) return;
				var opt = form.el.querySelector('.ks-type-option[data-type="' + t + '"]');
				if (opt) opt.querySelector('.ks-type-option__price').textContent = opts[t].price;
			});
			if (form) {
				TYPES.forEach(function (t) {
					var opt = form.el.querySelector('.ks-type-option[data-type="' + t + '"]'); if (!opt) return;
					var available = !!opts[t];
					opt.hidden = !available;
					opt.querySelector('input').disabled = !available;
					if (!available) opt.querySelector('.ks-type-option__price').textContent = '';
				});
			}
			var sub = root.querySelector('.ks-carrier-option[data-carrier="' + carrier + '"] .ks-carrier-option__sub');
			if (sub) sub.textContent = min ? (min.cost > 0 ? (cfg.i18n.from || 'от') + ' ' + min.price : min.price) : '';
		});
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

	function apply() {
		var available = anyAvailable();
		var carrier = available ? chosenCarrier() : '';
		root.hidden = !available;
		document.body.classList.toggle('ks-carrier-selected', !!carrier);
		toggleRequired(!!carrier);
		if (!available) { document.body.classList.remove('ks-hide-rates'); return; }
		refreshCarriers();
		readOptions();
		Object.keys(forms).forEach(function (id) { forms[id].el.hidden = id !== carrier; });
		var form = forms[carrier];
		if (!form) return;
		if (carrier === 'econt' && !form.getType()) {
			var saved = form.recall();
			form.setType((saved && saved.type) || cfg.defaultType || '');
		}
		form.refreshSteps();
	}

	function init() {
		root = document.getElementById('ks-delivery');
		if (!root || root.dataset.ksReady) return;
		root.dataset.ksReady = '1';
		root.querySelectorAll('.ks-carrier-form[data-carrier]').forEach(function (el) {
			var f = new Form(el, el.dataset.carrier);
			forms[f.carrier] = f;
			f.mount();
		});

		/* Карта на куриер: избира ставката му; WooCommerce обновява прегледа при change. */
		root.addEventListener('click', function (e) {
			var card = e.target.closest('.ks-carrier-option[data-method]'); if (!card) return;
			var input = rateFor(card.dataset.method); if (!input || input.checked) return;
			input.checked = true;
			$(input).trigger('change');
			apply();
		});

		apply();
		/* Запомнен избор на вид (localStorage) различен от този на сървъра → преизчисли ставката. */
		var econt = forms.econt;
		if (econt && chosenCarrier() === 'econt' && econt.getType() && econt.getType() !== (root.dataset.type || '')) {
			$(document.body).trigger('update_checkout');
		}
	}

	/* Общи условия: отметката е задължителна и за браузъра (ако темата не изключва проверката му), редът се маркира
	 * в червено, докато не се сложи. Съобщението е едно: това на WooCommerce под отметката, ако го има, иначе нашето.
	 * При грешка от сървъра страницата скролва до сгрешеното поле (отметката или първото невалидно), а не най-горе. */
	var TERMS_MSG = (cfg.i18n && cfg.i18n.terms) || 'Приемете Общите условия, за да продължите.';
	function termsBox() { return document.getElementById('terms'); }
	function termsRow() { var t = termsBox(); return t ? (t.closest('.form-row') || t.parentNode) : null; }
	function markTerms(missing) {
		var row = termsRow(); if (!row) return;
		row.classList.toggle('ks-terms-missing', missing);
		var msg = row.querySelector('.ks-terms-msg'), wcMsg = row.querySelector('.checkout-inline-error-message');
		if (missing && !msg && !wcMsg) { msg = document.createElement('span'); msg.className = 'ks-terms-msg'; msg.textContent = TERMS_MSG; row.appendChild(msg); }
		if ((!missing || wcMsg) && msg) msg.remove();
	}
	function prepareTerms() {
		var t = termsBox(); if (!t || t.dataset.ksTerms) return;
		t.dataset.ksTerms = '1';
		t.required = true;
		t.addEventListener('change', function () { markTerms(!t.checked); });
	}
	function scrollToField(row) {
		$('html, body').stop(true); /* спира скролването на WooCommerce към горното съобщение */
		setTimeout(function () {
			$('html, body').stop(true);
			row.scrollIntoView({ block: 'center', behavior: 'smooth' });
			var input = row.querySelector('input, select, textarea'); if (input) input.focus({ preventScroll: true });
		}, 60);
	}
	$(document.body).on('click', '#place_order', function () {
		var t = termsBox(); if (!t) return;
		markTerms(!t.checked);
		if (!t.checked) { t.focus(); }
	});
	$(document.body).on('checkout_error', function () {
		var target = null;
		if (document.querySelector('.woocommerce-error [data-id="terms"]')) { markTerms(true); target = termsRow(); }
		if (!target) {
			var bad = document.querySelector('form.checkout .form-row.woocommerce-invalid, form.checkout .checkout-inline-error-message');
			target = bad ? (bad.closest('.form-row') || bad) : null;
		}
		if (target) scrollToField(target);
	});

	$(document.body).on('updated_checkout', function () { init(); apply(); prepareTerms(); });
	$(document.body).on('change', 'input[name^="shipping_method"]', function () { if (root) apply(); });
	$(function () { init(); prepareTerms(); });
})(jQuery);
