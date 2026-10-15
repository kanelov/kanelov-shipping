/* global ksPrint, PDFLib */
/* Kanelov Shipping – масов печат: зарежда етикетите един по един през admin-ajax и ги събира в един PDF (pdf-lib).
 * Подредба „label“: всеки етикет на своя страница; „a4“: по 4 етикета на A4 (2×2), центрирани и смалени при нужда. */
(function () {
	'use strict';
	var cfg = window.ksPrint || {};
	if (!cfg.orders || !cfg.orders.length || !window.PDFLib) return;

	var LAYOUT_KEY = 'ks_print_layout';
	var layoutSel = document.getElementById('ks-print-layout');
	var openBtn = document.getElementById('ks-print-open');
	var download = document.getElementById('ks-print-download');
	var status = document.getElementById('ks-print-status');
	var frame = document.getElementById('ks-print-frame');
	var labels = [];
	var url = null;

	try { var saved = localStorage.getItem(LAYOUT_KEY); if (saved) layoutSel.value = saved; } catch (e) { /* без localStorage */ }

	function row(id) { return document.querySelector('.ks-print__list li[data-order="' + id + '"] .ks-print__state'); }
	function setState(id, text, cls) { var el = row(id); if (el) { el.textContent = text; el.className = 'ks-print__state ' + (cls || ''); } }

	function base64ToBytes(b64) {
		var bin = atob(b64), bytes = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
		return bytes;
	}

	function fetchLabel(id) {
		var body = new FormData();
		body.append('action', cfg.action); body.append('nonce', cfg.nonce); body.append('order_id', id);
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) throw new Error((res && res.data && res.data.message) || cfg.i18n.failed);
				return base64ToBytes(res.data.pdf);
			});
	}

	async function build() {
		var PDFDocument = PDFLib.PDFDocument;
		var out = await PDFDocument.create();
		if (layoutSel.value === 'a4') {
			var W = 595.28, H = 841.89, cellW = W / 2, cellH = H / 2, n = 0, page = null;
			for (var i = 0; i < labels.length; i++) {
				var src = await PDFDocument.load(labels[i], { ignoreEncryption: true });
				var embedded = await out.embedPages(src.getPages());
				for (var j = 0; j < embedded.length; j++) {
					if (n % 4 === 0) page = out.addPage([W, H]);
					var e = embedded[j], scale = Math.min(cellW / e.width, cellH / e.height, 1);
					var w = e.width * scale, h = e.height * scale;
					var col = n % 2, rowIdx = Math.floor((n % 4) / 2);
					page.drawPage(e, { x: col * cellW + (cellW - w) / 2, y: H - (rowIdx + 1) * cellH + (cellH - h) / 2, width: w, height: h });
					n++;
				}
			}
		} else {
			for (var k = 0; k < labels.length; k++) {
				var doc = await PDFDocument.load(labels[k], { ignoreEncryption: true });
				var pages = await out.copyPages(doc, doc.getPageIndices());
				pages.forEach(function (p) { out.addPage(p); });
			}
		}
		var bytes = await out.save();
		if (url) URL.revokeObjectURL(url);
		url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
		frame.src = url;
		frame.hidden = false;
		openBtn.disabled = false;
		download.href = url;
		download.download = cfg.i18n.file + '-' + new Date().toISOString().slice(0, 10) + '.pdf';
		download.removeAttribute('aria-disabled');
		status.textContent = cfg.i18n.ready.replace('%d', labels.length);
	}

	async function run() {
		for (var i = 0; i < cfg.orders.length; i++) {
			var id = cfg.orders[i];
			setState(id, cfg.i18n.loading, 'is-loading');
			try {
				labels.push(await fetchLabel(id));
				setState(id, cfg.i18n.ok, 'is-ok');
			} catch (e) {
				setState(id, cfg.i18n.failed + ': ' + e.message, 'is-error');
			}
		}
		if (!labels.length) { status.textContent = cfg.i18n.none; return; }
		await build();
	}

	openBtn.addEventListener('click', function () { if (url) window.open(url, '_blank'); });
	layoutSel.addEventListener('change', function () {
		try { localStorage.setItem(LAYOUT_KEY, layoutSel.value); } catch (e) { /* без localStorage */ }
		if (labels.length) build();
	});
	run().catch(function (e) { status.textContent = cfg.i18n.failed + ': ' + e.message; });
})();
