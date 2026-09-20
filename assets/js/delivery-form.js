/* Kanelov Shipping – общ модул: combobox за търсене и форма за избор на офис/Еконтомат/адрес. */
(function () {
	'use strict';

	function debounce(fn, ms) {
		var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
	}

	function Combo(input, opts) {
		this.input = input; this.opts = opts; this.items = []; this.token = 0;
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
		var self = this, token = ++this.token;
		this.render([{ label: this.opts.i18n.loading, muted: true }]);
		Promise.resolve(this.opts.source(q)).then(function (items) {
			if (token !== self.token) return;
			self.items = items || [];
			self.render(self.items.length ? self.items : [{ label: self.opts.empty || self.opts.i18n.noResults, muted: true }]);
		}).catch(function () { self.render([{ label: self.opts.i18n.noResults, muted: true }]); });
	};
	Combo.prototype.render = function (items) {
		var self = this; this.list.innerHTML = ''; this.active = -1; this.visible = [];
		items.forEach(function (it) {
			var li = document.createElement('li');
			if (it.group) { li.className = 'ks-combo__group'; li.textContent = it.group; self.list.appendChild(li); return; }
			li.className = 'ks-combo__item' + (it.muted ? ' ks-combo__item--muted' : '');
			li.setAttribute('role', 'option');
			var s = document.createElement('span'); s.textContent = it.label; li.appendChild(s);
			if (it.sub) { var sm = document.createElement('small'); sm.textContent = it.sub; li.appendChild(sm); }
			if (!it.muted) { li.addEventListener('mousedown', function (e) { e.preventDefault(); self.pick(it); }); self.visible.push({ el: li, item: it }); }
			self.list.appendChild(li);
		});
		this.list.hidden = false;
	};
	Combo.prototype.key = function (e) {
		if (this.list.hidden || !this.visible.length) return;
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			e.preventDefault();
			this.active = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + this.visible.length) % this.visible.length;
			this.visible.forEach(function (v, i) { v.el.setAttribute('aria-selected', i === this.active ? 'true' : 'false'); }, this);
			this.visible[this.active].el.scrollIntoView({ block: 'nearest' });
		} else if (e.key === 'Enter' && this.active >= 0) { e.preventDefault(); this.pick(this.visible[this.active].item); }
		else if (e.key === 'Escape') { this.close(); }
	};
	Combo.prototype.pick = function (item) { this.input.value = item.label; this.close(); this.opts.onPick(item); };
	Combo.prototype.close = function () { this.list.hidden = true; };

	/**
	 * Свързва полетата в root (елементи с класове ks-*) с REST търсенето.
	 * opts: { rest, i18n, getType(): 'office'|'locker'|'door', onChange() }
	 */
	function mount(root, opts) {
		var i18n = opts.i18n || {};
		function api(path, params) {
			var url = new URL(opts.rest + path);
			Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
			return fetch(url.toString(), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : []; });
		}
		var els = {
			cityId: root.querySelector('.ks-city-id'), postCode: root.querySelector('.ks-post-code'), city: root.querySelector('.ks-city'),
			officeCode: root.querySelector('.ks-office-code'), officeName: root.querySelector('.ks-office-name'), office: root.querySelector('.ks-office'),
			officeSelected: root.querySelector('.ks-office-selected'), secOffice: root.querySelector('.ks-section--office'), secDoor: root.querySelector('.ks-section--door'),
			nearest: root.querySelector('.ks-nearest'), street: root.querySelector('.ks-street'), quarter: root.querySelector('.ks-quarter')
		};
		var officeType = els.officeCode.value ? opts.getType() : null;
		function changed() { if (opts.onChange) opts.onChange(); }
		function setCity(city) {
			els.cityId.value = city ? city.id : ''; els.postCode.value = city ? city.post_code : ''; if (city) els.city.value = city.name;
			setOffice(null);
		}
		function setOffice(office) {
			els.officeCode.value = office ? office.code : ''; els.officeName.value = office ? office.label : ''; els.office.value = office ? office.label : '';
			els.officeSelected.hidden = !office;
			els.officeSelected.textContent = office ? '✓ ' + office.label + (office.hours ? ' · ' + office.hours : '') : '';
			changed();
		}
		function officeSource(q) {
			if (!els.cityId.value) return [{ label: i18n.chooseCity, muted: true }];
			var type = opts.getType() === 'locker' ? 'locker' : 'office';
			return api('offices', { city_id: els.cityId.value, type: type }).then(function (list) {
				q = (q || '').toLowerCase();
				if (q && q === (els.officeName.value || '').toLowerCase()) q = '';
				var out = list.filter(function (o) { return !q || (o.name + ' ' + o.address).toLowerCase().indexOf(q) >= 0; })
					.map(function (o) { return { code: o.code, label: o.label, sub: o.hours, hours: o.hours }; });
				return out.length ? out : [{ label: type === 'locker' ? i18n.noLockers : i18n.noOffices, muted: true }];
			});
		}
		new Combo(els.city, {
			i18n: i18n,
			source: function (q) { return api('cities', { q: q }).then(function (cs) { return cs.map(function (c) { return { id: c.id, name: c.name, label: c.label, post_code: c.post_code, sub: c.post_code }; }); }); },
			onPick: setCity
		});
		els.city.addEventListener('input', function () { els.cityId.value = ''; els.postCode.value = ''; setOffice(null); });

		var officeCombo = new Combo(els.office, {
			i18n: i18n, delay: 0, source: officeSource,
			onPick: function (o) { if (o.city) { els.cityId.value = o.city.id; els.postCode.value = o.city.post_code; els.city.value = o.city.name; } setOffice(o); }
		});
		els.office.addEventListener('input', function () { if (els.office.value !== els.officeName.value) { els.officeCode.value = ''; els.officeSelected.hidden = true; } });

		function named(kind) {
			return function (q) { return els.cityId.value ? api(kind, { city_id: els.cityId.value, q: q }).then(function (l) { return l.map(function (s) { return { label: s.name }; }); }) : [{ label: i18n.chooseCity, muted: true }]; };
		}
		if (els.street) new Combo(els.street, { i18n: i18n, source: named('streets'), onPick: changed });
		if (els.quarter) new Combo(els.quarter, { i18n: i18n, source: named('quarters'), onPick: changed });
		root.addEventListener('change', changed);

		if (els.nearest) {
			els.nearest.addEventListener('click', function () {
				if (!navigator.geolocation) { window.alert(i18n.geoError); return; }
				els.nearest.disabled = true;
				navigator.geolocation.getCurrentPosition(function (pos) {
					var type = opts.getType() === 'locker' ? 'locker' : 'office';
					api('nearest', { lat: pos.coords.latitude, lng: pos.coords.longitude, type: type }).then(function (list) {
						els.nearest.disabled = false;
						if (!list.length || !list[0].city) return;
						var first = list[0];
						els.cityId.value = first.city.id; els.postCode.value = first.city.post_code; els.city.value = first.city.name;
						setOffice(first);
						officeCombo.items = list.map(function (o) { return { code: o.code, label: o.label, sub: (o.km !== null ? o.km + ' ' + i18n.km + ' · ' : '') + (o.hours || ''), hours: o.hours, city: o.city }; });
						officeCombo.render([{ group: i18n.nearest }].concat(officeCombo.items));
						els.office.focus();
					}).catch(function () { els.nearest.disabled = false; });
				}, function () { els.nearest.disabled = false; window.alert(i18n.geoError); });
			});
		}

		/* Карта в <dialog>: зарежда Leaflet при първо отваряне, маркери за офисите в избрания град. */
		var mapBtn = root.querySelector('.ks-map-open');
		if (mapBtn && opts.map) {
			var dialog = null, map = null, layer = null;
			function loadLeaflet() {
				if (window.L) return Promise.resolve();
				return new Promise(function (resolve, reject) {
					var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = opts.map.css; document.head.appendChild(css);
					var js = document.createElement('script'); js.src = opts.map.js; js.onload = resolve; js.onerror = reject; document.head.appendChild(js);
				});
			}
			function ensureDialog() {
				if (dialog) return dialog;
				dialog = document.createElement('dialog'); dialog.className = 'ks-map-dialog';
				dialog.innerHTML = '<div class="ks-map-dialog__head"><span></span><button type="button" class="ks-map-dialog__close" aria-label="' + (i18n.close || '') + '">×</button></div><div class="ks-map-dialog__map"></div>';
				dialog.querySelector('.ks-map-dialog__head span').textContent = i18n.mapTitle || '';
				dialog.querySelector('.ks-map-dialog__close').addEventListener('click', function () { dialog.close(); });
				dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
				document.body.appendChild(dialog);
				return dialog;
			}
			mapBtn.addEventListener('click', function () {
				if (!els.cityId.value) { window.alert(i18n.chooseCity); return; }
				var type = opts.getType() === 'locker' ? 'locker' : 'office';
				mapBtn.disabled = true;
				Promise.all([loadLeaflet(), api('offices', { city_id: els.cityId.value, type: type })]).then(function (r) {
					mapBtn.disabled = false;
					var offices = r[1].filter(function (o) { return o.lat && o.lng; });
					if (!offices.length) { window.alert(i18n.noCoords); return; }
					var d = ensureDialog(); d.showModal();
					var el = d.querySelector('.ks-map-dialog__map');
					if (!map) {
						map = window.L.map(el, { scrollWheelZoom: true });
						window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
					}
					if (layer) layer.remove();
					layer = window.L.featureGroup().addTo(map);
					offices.forEach(function (o) {
						var m = window.L.marker([o.lat, o.lng]).addTo(layer);
						var popup = document.createElement('div'); popup.className = 'ks-map-popup';
						var b = document.createElement('b'); b.textContent = o.name; popup.appendChild(b);
						popup.appendChild(document.createTextNode(o.address + (o.hours ? ' · ' + o.hours : '')));
						var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'button'; btn.textContent = i18n.choose || 'OK';
						btn.addEventListener('click', function () { setOffice({ code: o.code, label: o.label, hours: o.hours }); d.close(); });
						popup.appendChild(btn);
						m.bindPopup(popup);
					});
					setTimeout(function () { map.invalidateSize(); map.fitBounds(layer.getBounds().pad(0.2)); }, 50);
				}).catch(function () { mapBtn.disabled = false; });
			});
		}

		return {
			applyType: function (type) {
				var toOffice = type !== 'door';
				els.secOffice.hidden = !toOffice; els.secDoor.hidden = toOffice;
				if (els.nearest) els.nearest.hidden = !toOffice;
				if (mapBtn) mapBtn.hidden = !toOffice;
				els.office.placeholder = type === 'locker' ? i18n.searchLocker : i18n.searchOffice;
				var label = root.querySelector('.ks-field-office label');
				if (label && label.firstChild && label.firstChild.nodeType === 3 && i18n.labelOffice) {
					label.firstChild.nodeValue = (type === 'locker' ? i18n.labelLocker : i18n.labelOffice) + '\u00a0';
				}
				if (els.officeCode.value && officeType && officeType !== type) setOffice(null);
				officeType = type;
			},
			els: els
		};
	}

	window.KSDeliveryForm = { Combo: Combo, mount: mount };
})();
