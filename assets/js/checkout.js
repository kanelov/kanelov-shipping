/* global ksCheckout, jQuery */
(function ($) {
	'use strict';

	var cfg = window.ksCheckout || {};
	var i18n = cfg.i18n || {};
	var root, els, typeLabels = {};
	var STORAGE_KEY = 'ks_delivery';

	function api(path, params) {
		var url = new URL(cfg.rest + path);
		Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
		return fetch(url.toString(), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : []; });
	}

	function debounce(fn, ms) {
		var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
	}

	/* Малък combobox: input + списък с резултати. */
	function Combo(input, opts) {
		this.input = input; this.opts = opts; this.items = [];
		var wrap = document.createElement('div'); wrap.className = 'ks-combo';
		input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
		this.list = document.createElement('ul'); this.list.className = 'ks-combo__list'; this.list.hidden = true;
		wrap.appendChild(this.list);
		var self = this;
		input.addEventListener('input', debounce(function () { self.query(input.value); }, opts.delay || 150));
		input.addEventListener('focus', function () { self.query(input.value); });
		input.addEventListener('keydown', function (e) { self.key(e); });
		document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) self.close(); });
	}
	Combo.prototype.query = function (q) {
		var self = this, token = ++this.token || (this.token = 1);
		this.render([{ label: i18n.loading, muted: true }]);
		Promise.resolve(this.opts.source(q)).then(function (items) {
			if (token !== self.token) return;
			self.items = items || [];
			self.render(self.items.length ? self.items : [{ label: self.opts.empty || i18n.noResults, muted: true }]);
		});
	};
	Combo.prototype.render = function (items) {
		var self = this; this.list.innerHTML = ''; this.active = -1;
		items.forEach(function (it, i) {
			if (it.group) { var g = document.createElement('li'); g.className = 'ks-combo__group'; g.textContent = it.group; self.list.appendChild(g); return; }
			var li = document.createElement('li'); li.className = 'ks-combo__item' + (it.muted ? ' ks-combo__item--muted' : '');
			li.setAttribute('role', 'option');
			li.innerHTML = '<span></span>' + (it.sub ? '<small></small>' : '');
			li.firstChild.textContent = it.label; if (it.sub) li.lastChild.textContent = it.sub;
			if (!it.muted) li.addEventListener('mousedown', function (e) { e.preventDefault(); self.pick(it); });
			self.list.appendChild(li);
		});
		this.list.hidden = false;
	};
	Combo.prototype.key = function (e) {
		var opts = this.list.querySelectorAll('.ks-combo__item:not(.ks-combo__item--muted)');
		if (this.list.hidden || !opts.length) return;
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			this.active = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
			opts.forEach(function (o, i) { o.setAttribute('aria-selected', i === this.active ? 'true' : 'false'); }, this);
			opts[this.active].scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'Enter' && this.active >= 0) {
			e.preventDefault(); this.pick(this.items.filter(function (i) { return !i.muted && !i.group; })[this.active]);
		} else if (e.key === 'Escape') { this.close(); }
	};
	Combo.prototype.pick = function (item) { this.input.value = item.label; this.close(); this.opts.onPick(item); };
	Combo.prototype.close = function () { this.list.hidden = true; };

	/* Състояние */
	function state() {
		return {
			type: els.type.value, city_id: els.cityId.value, city_name: els.city.value, post_code: els.postCode.value,
			office_code: els.officeCode.value, office_name: els.officeName.value,
			street: val('street'), street_num: val('street_num'), quarter: val('quarter'), block: val('block'),
			entrance: val('entrance'), floor: val('floor'), apartment: val('apartment'), note: val('note')
		};
	}
	function val(n) { var i = root.querySelector('[name="ks_' + n + '"]'); return i ? i.value : ''; }
	function remember() { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state())); } catch (e) { /* private mode */ } }
	function recall() {
		try { var s = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); if (s && s.city_id) return s; } catch (e) { /* ignore */ }
		return cfg.saved && cfg.saved.city_id ? cfg.saved : null;
	}

	function chosenType() {
		var input = document.querySelector('input[name^="shipping_method"]:checked') || document.querySelector('input[name^="shipping_method"][type="hidden"]');
		if (!input) return null;
		var parts = String(input.value).split(':');
		if (parts[0] !== cfg.methodId) return null;
		var t = parts[parts.length - 1];
		return ['office', 'locker', 'door'].indexOf(t) >= 0 ? t : null;
	}

	function setCity(city) {
		els.cityId.value = city ? city.id : ''; els.postCode.value = city ? city.post_code : '';
		if (city) els.city.value = city.name;
		setOffice(null);
		remember();
	}
	function setOffice(office) {
		els.officeCode.value = office ? office.code : ''; els.officeName.value = office ? office.label : '';
		els.office.value = office ? office.label : '';
		els.officeSelected.hidden = !office;
		els.officeSelected.textContent = office ? '✓ ' + office.label + (office.hours ? ' · ' + office.hours : '') : '';
		remember();
	}

	function applyType() {
		var type = chosenType();
		document.body.classList.toggle('ks-econt-selected', !!type);
		root.hidden = !type;
		if (!type) return;
		els.type.value = type;
		root.querySelector('.ks-delivery__type').textContent = typeLabels[type] ? '(' + typeLabels[type] + ')' : '';
		var toOffice = type !== 'door';
		els.secOffice.hidden = !toOffice; els.secDoor.hidden = toOffice;
		els.office.placeholder = type === 'locker' ? 'Търсете Еконтомат…' : 'Търсете офис по име или адрес…';
		els.nearest.hidden = !toOffice;
		// При смяна офис <-> Еконтомат изборът на офис не е валиден за другия тип.
		if (els.officeCode.value && els.officeType !== type) setOffice(null);
		els.officeType = type;
	}

	function init() {
		root = document.getElementById('ks-delivery');
		if (!root || root.dataset.ksReady) return;
		root.dataset.ksReady = '1';
		try { typeLabels = JSON.parse(root.querySelector('.ks-type-labels').textContent); } catch (e) { /* ignore */ }
		els = {
			type: root.querySelector('.ks-type'), cityId: root.querySelector('.ks-city-id'), postCode: root.querySelector('.ks-post-code'),
			officeCode: root.querySelector('.ks-office-code'), officeName: root.querySelector('.ks-office-name'),
			city: root.querySelector('.ks-city'), office: root.querySelector('.ks-office'), officeSelected: root.querySelector('.ks-office-selected'),
			secOffice: root.querySelector('.ks-section--office'), secDoor: root.querySelector('.ks-section--door'),
			nearest: root.querySelector('.ks-nearest'), street: root.querySelector('.ks-street'), quarter: root.querySelector('.ks-quarter')
		};

		var saved = recall();
		if (saved) {
			els.cityId.value = saved.city_id || ''; els.postCode.value = saved.post_code || ''; els.city.value = saved.city_name || '';
			els.officeCode.value = saved.office_code || ''; els.officeName.value = saved.office_name || ''; els.office.value = saved.office_name || '';
			['street', 'street_num', 'quarter', 'block', 'entrance', 'floor', 'apartment', 'note'].forEach(function (n) {
				var i = root.querySelector('[name="ks_' + n + '"]'); if (i && saved[n] && !i.value) i.value = saved[n];
			});
			if (saved.office_code) { els.officeSelected.hidden = false; els.officeSelected.textContent = '✓ ' + saved.office_name; }
			els.officeType = saved.type;
		}

		new Combo(els.city, {
			source: function (q) {
				return api('cities', { q: q }).then(function (cities) {
					return cities.map(function (c) { return { id: c.id, name: c.name, label: c.label, post_code: c.post_code, sub: c.post_code }; });
				});
			},
			onPick: setCity
		});
		els.city.addEventListener('input', function () { els.cityId.value = ''; els.postCode.value = ''; setOffice(null); });

		root.ksOfficeCombo = new Combo(els.office, {
			delay: 0,
			source: function (q) {
				if (!els.cityId.value) return [{ label: i18n.chooseCity, muted: true }];
				var type = els.type.value === 'locker' ? 'locker' : 'office';
				return api('offices', { city_id: els.cityId.value, type: type }).then(function (list) {
					q = (q || '').toLowerCase();
					if (q && list.some(function (o) { return o.label === els.office.value; })) q = '';
					return list.filter(function (o) { return !q || (o.name + ' ' + o.address).toLowerCase().indexOf(q) >= 0; })
						.map(function (o) { return { code: o.code, label: o.label, sub: o.hours, hours: o.hours }; });
				});
			},
			empty: null,
			onPick: function (o) { if (o.city) { els.cityId.value = o.city.id; els.postCode.value = o.city.post_code; els.city.value = o.city.name; } setOffice(o); }
		});
		els.office.addEventListener('input', function () { if (els.office.value !== els.officeName.value) { els.officeCode.value = ''; els.officeSelected.hidden = true; } });

		new Combo(els.street, {
			source: function (q) { return els.cityId.value ? api('streets', { city_id: els.cityId.value, q: q }).then(function (l) { return l.map(function (s) { return { label: s.name }; }); }) : [{ label: i18n.chooseCity, muted: true }]; },
			onPick: remember
		});
		new Combo(els.quarter, {
			source: function (q) { return els.cityId.value ? api('quarters', { city_id: els.cityId.value, q: q }).then(function (l) { return l.map(function (s) { return { label: s.name }; }); }) : [{ label: i18n.chooseCity, muted: true }]; },
			onPick: remember
		});
		root.addEventListener('change', remember);

		var officeCombo = root.ksOfficeCombo;
		els.nearest.addEventListener('click', function () {
			if (!navigator.geolocation) { alert(i18n.geoError); return; }
			els.nearest.disabled = true;
			navigator.geolocation.getCurrentPosition(function (pos) {
				var type = els.type.value === 'locker' ? 'locker' : 'office';
				api('nearest', { lat: pos.coords.latitude, lng: pos.coords.longitude, type: type }).then(function (list) {
					els.nearest.disabled = false;
					if (!list.length || !list[0].city) return;
					var first = list[0];
					els.cityId.value = first.city.id; els.postCode.value = first.city.post_code; els.city.value = first.city.name;
					setOffice(first);
					// Показваме и останалите най-близки като избор.
					officeCombo.items = list.map(function (o) { return { code: o.code, label: o.label, sub: (o.km !== null ? o.km + ' ' + i18n.km + ' · ' : '') + (o.hours || ''), hours: o.hours, city: o.city }; });
					officeCombo.render([{ group: i18n.nearest }].concat(officeCombo.items));
				}).catch(function () { els.nearest.disabled = false; });
			}, function () { els.nearest.disabled = false; alert(i18n.geoError); });
		});

		applyType();
	}

	$(document.body).on('updated_checkout', function () { init(); applyType(); });
	$(document.body).on('change', 'input[name^="shipping_method"]', applyType);
	$(function () { init(); });
})(jQuery);
