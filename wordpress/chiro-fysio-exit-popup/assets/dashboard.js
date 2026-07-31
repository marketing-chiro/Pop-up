/*!
 * Grafieken voor het exit-pop-up dashboard.
 *
 * Alles wordt met de hand als SVG getekend, zodat er geen externe
 * grafiekbibliotheek geladen hoeft te worden in het WordPress-beheer.
 */
(function () {
	'use strict';

	// De cijfers komen op twee manieren binnen: in het WordPress-beheer worden
	// ze meegegeven in window.CF_DASH, en in de app worden ze opgehaald en via
	// cfDashboard.render() doorgegeven. De tekencode is voor beide dezelfde.
	var DATA = window.CF_DASH || null;

	var SVG_NS = 'http://www.w3.org/2000/svg';

	/* --- Kleine hulpjes ---------------------------------------------------- */

	function el(name, attrs, parent) {
		var node = document.createElementNS(SVG_NS, name);
		for (var key in attrs) {
			if (Object.prototype.hasOwnProperty.call(attrs, key)) {
				node.setAttribute(key, attrs[key]);
			}
		}
		if (parent) parent.appendChild(node);
		return node;
	}

	function svgRoot(host, width, height) {
		host.innerHTML = '';
		var svg = el('svg', {
			viewBox: '0 0 ' + width + ' ' + height,
			width: '100%',
			height: height,
			role: 'img',
			preserveAspectRatio: 'xMinYMin meet'
		}, host);
		return svg;
	}

	function nl(n) {
		return Number(n).toLocaleString('nl-NL');
	}

	function pct(part, whole) {
		return whole > 0 ? Math.round((part / whole) * 100) : 0;
	}

	// Eén tooltip voor het hele dashboard.
	var tip = document.createElement('div');
	tip.className = 'cf-tip';
	tip.setAttribute('role', 'status');
	tip.hidden = true;
	document.body.appendChild(tip);

	function showTip(html, event) {
		tip.innerHTML = html;
		tip.hidden = false;
		var rect = tip.getBoundingClientRect();
		var x = event.clientX + 14;
		var y = event.clientY - rect.height - 10;

		// Binnen beeld houden.
		if (x + rect.width > window.innerWidth - 8) x = event.clientX - rect.width - 14;
		if (y < 8) y = event.clientY + 18;

		tip.style.left = x + 'px';
		tip.style.top = y + 'px';
	}

	function hideTip() {
		tip.hidden = true;
	}

	function hoverable(node, html) {
		node.addEventListener('mousemove', function (e) { showTip(html, e); });
		node.addEventListener('mouseleave', hideTip);
	}

	function legend(host, items) {
		var wrap = document.createElement('div');
		wrap.className = 'cf-legend';
		items.forEach(function (item) {
			var span = document.createElement('span');
			span.className = 'cf-legend__item';
			span.innerHTML = '<i class="cf-legend__swatch ' + item.cls + '"></i>' + item.label;
			wrap.appendChild(span);
		});
		host.appendChild(wrap);
	}

	/* --- 1. Hoe liep het af? (gestapelde balk) ------------------------------ */

	function drawOutcome() {
		var host = document.getElementById('cf-chart-outcome');
		if (!host) return;

		var t = DATA.totals;
		var total = t.shown;
		if (!total) return;

		var segments = [
			{ key: 'found',     label: 'Gevonden wat ze zochten', value: t.found,     cls: 'cf-fill--found' },
			{ key: 'rescued',   label: 'Niet gevonden, wel contact opgenomen', value: t.rescued, cls: 'cf-fill--rescued' },
			{ key: 'lost',      label: 'Niet gevonden, zonder contact weg', value: t.lost, cls: 'cf-fill--lost' },
			{ key: 'noanswer',  label: 'Geen antwoord gegeven', value: t.no_answer, cls: 'cf-fill--none' }
		].filter(function (s) { return s.value > 0; });

		var W = 720, H = 46, GAP = 2, R = 4;
		var svg = svgRoot(host, W, H);
		var x = 0;

		segments.forEach(function (seg, i) {
			var w = (seg.value / total) * W;
			var drawW = Math.max(1, w - (i < segments.length - 1 ? GAP : 0));

			var rect = el('rect', {
				x: x,
				y: 0,
				width: drawW,
				height: 44,
				rx: R,
				class: 'cf-seg ' + seg.cls
			}, svg);

			hoverable(rect,
				'<strong>' + seg.label + '</strong><br>' +
				nl(seg.value) + ' van ' + nl(total) + ' (' + pct(seg.value, total) + '%)');

			// Percentage in de balk, maar alleen als het past - anders wordt het
			// een onleesbare prop.
			if (drawW > 46) {
				el('text', {
					x: x + drawW / 2,
					y: 27,
					class: 'cf-seg__label',
					'text-anchor': 'middle'
				}, svg).textContent = pct(seg.value, total) + '%';
			}

			x += w;
		});

		legend(host, segments.map(function (s) {
			return { cls: s.cls, label: s.label + ' (' + nl(s.value) + ')' };
		}));
	}

	/* --- 2. Verloop per dag (lijnen) ---------------------------------------- */

	function drawTrend() {
		var host = document.getElementById('cf-chart-trend');
		if (!host) return;

		var rows = DATA.perDay || [];
		if (rows.length < 2) {
			host.innerHTML = '<p class="cf-empty">Nog te weinig dagen om een verloop te tonen.</p>';
			return;
		}

		var W = 720, H = 260;
		var M = { top: 16, right: 16, bottom: 30, left: 38 };
		var iw = W - M.left - M.right;
		var ih = H - M.top - M.bottom;

		var max = Math.max(1, rows.reduce(function (m, r) { return Math.max(m, r.shown); }, 0));
		// Naar boven afronden op een rond getal, zodat de as leesbare stappen krijgt.
		var step = Math.pow(10, Math.floor(Math.log10(max)));
		max = Math.ceil(max / step) * step;

		var svg = svgRoot(host, W, H);
		var xAt = function (i) { return M.left + (rows.length === 1 ? iw / 2 : (i / (rows.length - 1)) * iw); };
		var yAt = function (v) { return M.top + ih - (v / max) * ih; };

		// Rasterlijnen, bewust terughoudend.
		for (var g = 0; g <= 4; g++) {
			var value = (max / 4) * g;
			var y = yAt(value);
			el('line', { x1: M.left, y1: y, x2: W - M.right, y2: y, class: 'cf-grid' }, svg);
			el('text', { x: M.left - 8, y: y + 4, class: 'cf-axis', 'text-anchor': 'end' }, svg)
				.textContent = nl(Math.round(value));
		}

		function path(key) {
			return rows.map(function (r, i) {
				return (i ? 'L' : 'M') + xAt(i).toFixed(1) + ' ' + yAt(r[key]).toFixed(1);
			}).join(' ');
		}

		// Vlak onder "getoond", zodat de lijn een basis heeft om tegen af te lezen.
		el('path', {
			d: path('shown') + ' L' + xAt(rows.length - 1) + ' ' + yAt(0) + ' L' + xAt(0) + ' ' + yAt(0) + ' Z',
			class: 'cf-area--shown'
		}, svg);

		el('path', { d: path('shown'), class: 'cf-line cf-line--shown' }, svg);
		el('path', { d: path('not_found'), class: 'cf-line cf-line--notfound' }, svg);

		// Datumlabels: hooguit een stuk of zes, anders lopen ze in elkaar.
		var every = Math.max(1, Math.ceil(rows.length / 6));
		rows.forEach(function (r, i) {
			if (i % every !== 0 && i !== rows.length - 1) return;
			var d = new Date(r.day + 'T00:00:00');
			el('text', { x: xAt(i), y: H - 8, class: 'cf-axis', 'text-anchor': 'middle' }, svg)
				.textContent = d.getDate() + ' ' + ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'][d.getMonth()];
		});

		// Draadkruis met tooltip.
		var cross = el('line', { x1: 0, y1: M.top, x2: 0, y2: M.top + ih, class: 'cf-cross', opacity: 0 }, svg);
		var dotA = el('circle', { r: 5, class: 'cf-dot--shown', opacity: 0 }, svg);
		var dotB = el('circle', { r: 5, class: 'cf-dot--notfound', opacity: 0 }, svg);

		var overlay = el('rect', {
			x: M.left, y: M.top, width: iw, height: ih, fill: 'transparent'
		}, svg);

		overlay.addEventListener('mousemove', function (e) {
			var box = svg.getBoundingClientRect();
			var rel = ((e.clientX - box.left) / box.width) * W;
			var i = Math.round(((rel - M.left) / iw) * (rows.length - 1));
			i = Math.max(0, Math.min(rows.length - 1, i));

			var r = rows[i];
			var x = xAt(i);

			cross.setAttribute('x1', x);
			cross.setAttribute('x2', x);
			cross.setAttribute('opacity', 1);
			dotA.setAttribute('cx', x); dotA.setAttribute('cy', yAt(r.shown)); dotA.setAttribute('opacity', 1);
			dotB.setAttribute('cx', x); dotB.setAttribute('cy', yAt(r.not_found)); dotB.setAttribute('opacity', 1);

			var d = new Date(r.day + 'T00:00:00');
			showTip(
				'<strong>' + d.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long' }) + '</strong><br>' +
				'Getoond: ' + nl(r.shown) + '<br>' +
				'Niet gevonden: ' + nl(r.not_found) +
				(r.rescued ? '<br>Contact opgenomen: ' + nl(r.rescued) : ''),
				e
			);
		});

		overlay.addEventListener('mouseleave', function () {
			hideTip();
			cross.setAttribute('opacity', 0);
			dotA.setAttribute('opacity', 0);
			dotB.setAttribute('opacity', 0);
		});

		legend(host, [
			{ cls: 'cf-fill--shown', label: 'Pop-up getoond' },
			{ cls: 'cf-fill--notfound', label: 'Niet gevonden' }
		]);
	}

	/* --- 3. Pagina's waar bezoekers vastliepen ------------------------------ */

	function drawPages() {
		var host = document.getElementById('cf-chart-pages');
		if (!host) return;

		var rows = DATA.topPages || [];
		if (!rows.length) {
			host.innerHTML = '<p class="cf-empty">Nog niemand die "niet gevonden" heeft geantwoord. Goed nieuws.</p>';
			return;
		}

		var max = rows.reduce(function (m, r) { return Math.max(m, Number(r.not_found)); }, 0);

		var table = document.createElement('div');
		table.className = 'cf-bars';

		rows.forEach(function (r) {
			var notFound = Number(r.not_found);
			var lost = Number(r.lost);
			var width = (notFound / max) * 100;

			var row = document.createElement('div');
			row.className = 'cf-bars__row';
			row.innerHTML =
				'<span class="cf-bars__label" title="' + r.page + '">' + r.page + '</span>' +
				'<span class="cf-bars__track">' +
					'<span class="cf-bars__fill" style="width:' + width.toFixed(1) + '%"></span>' +
				'</span>' +
				'<span class="cf-bars__value">' + nl(notFound) + '</span>';

			hoverable(row,
				'<strong>' + r.page + '</strong><br>' +
				'Pop-up getoond: ' + nl(r.shown) + '<br>' +
				'Niet gevonden: ' + nl(notFound) + '<br>' +
				'Daarvan zonder contact weg: ' + nl(lost));

			table.appendChild(row);
		});

		host.innerHTML = '';
		host.appendChild(table);
	}

	/* --- 4. Uur van de dag (kolommen) --------------------------------------- */

	function drawHours() {
		var host = document.getElementById('cf-chart-hours');
		if (!host) return;

		var rows = DATA.perHour || [];
		var max = rows.reduce(function (m, r) { return Math.max(m, r.shown); }, 0);
		if (!max) {
			host.innerHTML = '<p class="cf-empty">Nog geen metingen.</p>';
			return;
		}

		var W = 360, H = 190;
		var M = { top: 10, right: 4, bottom: 24, left: 4 };
		var ih = H - M.top - M.bottom;
		var svg = svgRoot(host, W, H);

		var slot = (W - M.left - M.right) / 24;
		var barW = slot - 3;

		rows.forEach(function (r, i) {
			var h = (r.shown / max) * ih;
			var x = M.left + i * slot;
			var y = M.top + ih - h;

			// Achtergrondstaafje, zodat ook lege uren afleesbaar blijven.
			el('rect', { x: x, y: M.top, width: barW, height: ih, rx: 3, class: 'cf-col__bg' }, svg);

			if (h > 0) {
				var bar = el('rect', {
					x: x, y: y, width: barW, height: Math.max(2, h), rx: 3, class: 'cf-col'
				}, svg);
				hoverable(bar,
					'<strong>' + String(r.hour).padStart(2, '0') + ':00 - ' +
					String(r.hour).padStart(2, '0') + ':59</strong><br>' +
					'Getoond: ' + nl(r.shown) + '<br>' +
					'Niet gevonden: ' + nl(r.not_found));
			}
		});

		[0, 6, 12, 18].forEach(function (h) {
			el('text', {
				x: M.left + h * slot + barW / 2,
				y: H - 8,
				class: 'cf-axis',
				'text-anchor': 'middle'
			}, svg).textContent = String(h).padStart(2, '0') + 'u';
		});
	}

	/* --- 5. Apparaat en signaal --------------------------------------------- */

	function drawDevices() {
		var host = document.getElementById('cf-chart-devices');
		if (!host) return;

		var TRIGGER_LABELS = {
			mouseleave: 'muis het venster uit',
			idle: 'lang stilgezeten (mobiel)',
			'scroll-up': 'snel omhoog geveegd',
			test: 'testmodus',
			handmatig: 'handmatig opgeroepen'
		};

		function block(title, rows, mapLabel) {
			if (!rows.length) return '';
			var total = rows.reduce(function (s, r) { return s + Number(r.shown); }, 0);

			var html = '<h3 class="cf-subhead">' + title + '</h3><div class="cf-bars cf-bars--compact">';
			rows.forEach(function (r) {
				var value = Number(r.shown);
				var label = mapLabel ? (mapLabel[r.label] || r.label) : r.label;
				html +=
					'<div class="cf-bars__row">' +
						'<span class="cf-bars__label">' + label + '</span>' +
						'<span class="cf-bars__track">' +
							'<span class="cf-bars__fill" style="width:' + pct(value, total) + '%"></span>' +
						'</span>' +
						'<span class="cf-bars__value">' + pct(value, total) + '%</span>' +
					'</div>';
			});
			return html + '</div>';
		}

		host.innerHTML =
			block('Apparaat', DATA.devices || []) +
			block('Nieuw of eerder geweest', DATA.visitors || []) +
			block('Signaal waarop de pop-up verscheen', DATA.triggers || [], TRIGGER_LABELS);
	}

	/* --- Start --------------------------------------------------------------- */

	function drawAll() {
		if (!DATA) return;
		drawOutcome();
		drawTrend();
		drawPages();
		drawHours();
		drawDevices();
	}

	window.cfDashboard = {
		render: function (data) {
			DATA = data;
			drawAll();
		}
	};

	drawAll();
})();
