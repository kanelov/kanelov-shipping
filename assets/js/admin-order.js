/* global ksAdmin, jQuery, KSDeliveryForm */
(function ($) {
	'use strict';
	var cfg = window.ksAdmin || {};
	var box;

	function notice(type, text) {
		var n = box.querySelector('.ks-order__notices');
		n.innerHTML = '';
		if (!text) return;
		var d = document.createElement('div'); d.className = 'notice notice-' + type + ' inline'; var p = document.createElement('p'); p.textContent = text; d.appendChild(p); n.appendChild(d);
	}

	function mount() {
		var root = box.querySelector('#ks-delivery');
		if (!root) return;
		var typeSel = root.querySelector('.ks-type');
		var form = KSDeliveryForm.mount(root, { rest: cfg.rest, sync: cfg.sync, i18n: cfg.i18n, map: cfg.map, getType: function () { return typeSel.value; } });
		form.applyType(typeSel.value);
		typeSel.addEventListener('change', function () { form.applyType(typeSel.value); });
	}

	function run(action) {
		var fields = $(box).find('input, select, textarea').serialize();
		box.classList.add('ks-busy');
		$.post(cfg.ajax, { action: cfg.action, nonce: cfg.nonce, order_id: box.dataset.order, do: action, fields: fields })
			.done(function (res) {
				var d = res.data || {};
				if (d.html) { box.innerHTML = d.html; mount(); }
				notice(res.success ? 'success' : 'error', d.message || '');
				if (action === 'calculate' && res.success) { var c = box.querySelector('.ks-calc-result'); if (c) c.textContent = d.message; }
				if (d.events) renderEvents(d.events);
				if (action === 'create' && res.success) { $(document.body).trigger('ks_label_created'); }
			})
			.fail(function () { notice('error', 'Грешка при заявката.'); })
			.always(function () { box.classList.remove('ks-busy'); });
	}

	function renderEvents(events) {
		var wrap = box.querySelector('.ks-tracking'); if (!wrap) return;
		if (!events.length) { wrap.innerHTML = ''; return; }
		var t = document.createElement('table'); t.className = 'widefat striped';
		events.forEach(function (e) {
			var tr = t.insertRow(); [e.time, e.event, e.place].forEach(function (v) { tr.insertCell().textContent = v || ''; });
		});
		wrap.innerHTML = ''; wrap.appendChild(t);
	}

	$(function () {
		box = document.getElementById('ks-order');
		if (!box) return;
		mount();
		$(box).on('click', '.ks-do', function () {
			var action = this.dataset.do;
			if (action === 'delete' && !window.confirm(cfg.i18nAdmin.confirmDelete)) return;
			if (action === 'forget' && !window.confirm(cfg.i18nAdmin.confirmForget)) return;
			run(action);
		});
	});
})(jQuery);
