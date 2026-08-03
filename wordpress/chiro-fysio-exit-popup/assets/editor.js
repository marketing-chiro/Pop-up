/*!
 * De instelpagina van de pop-up.
 *
 * Twee taken: de tabbladen, en het voorbeeld rechts dat bij elke toetsaanslag
 * meebeweegt. Het voorbeeld bouwt dezelfde opmaak als de echte pop-up, zodat
 * je ziet wat je krijgt in plaats van dat je het moet raden.
 */
(function () {
	'use strict';

	var form = document.getElementById('cf-editor-form');
	var popup = document.getElementById('cf-preview-popup');
	if (!form || !popup) return;

	var stap = 'ask';

	/* --- Tabbladen --------------------------------------------------------- */

	var tabs = Array.prototype.slice.call(document.querySelectorAll('.cf-tab'));
	var panelen = Array.prototype.slice.call(document.querySelectorAll('.cf-paneel'));

	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			var doel = tab.getAttribute('data-tab');

			tabs.forEach(function (t) {
				var actief = t === tab;
				t.classList.toggle('is-active', actief);
				t.setAttribute('aria-selected', actief ? 'true' : 'false');
			});

			panelen.forEach(function (p) {
				p.classList.toggle('is-active', p.getAttribute('data-paneel') === doel);
			});
		});
	});

	/* --- Waarden uitlezen --------------------------------------------------- */

	function waarde(naam) {
		var el = form.querySelector('[name="' + naam + '"]');
		if (!el) return '';
		if (el.type === 'checkbox') return el.checked;
		return el.value;
	}

	function tekst(naam) {
		// Als een veld leeg is, laten we het ook in het voorbeeld leeg: dan zie
		// je meteen dat er iets mist.
		return String(waarde(naam) || '');
	}

	function ontsnap(s) {
		return String(s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/* --- De huisstijl op het voorbeeld zetten -------------------------------- */

	function huisstijl() {
		popup.style.setProperty('--cf-primary', waarde('brand_primary'));
		popup.style.setProperty('--cf-primary-dark', waarde('brand_primary_dark'));
		popup.style.setProperty('--cf-accent', waarde('brand_accent'));
		popup.style.setProperty('--cf-text', waarde('brand_text'));
		popup.style.setProperty('--cf-muted', waarde('brand_muted'));
		popup.style.setProperty('--cf-radius', waarde('brand_radius') + 'px');
		popup.style.setProperty('--cf-btn-radius', waarde('brand_btn_radius') + 'px');

		var letter = String(waarde('brand_font') || '').trim();
		popup.style.setProperty('--cf-font', letter || 'inherit');
	}

	/* --- Het voorbeeld tekenen ---------------------------------------------- */

	var ICONEN = {
		vraag: '<path fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" ' +
			'stroke-linejoin="round" d="M9.1 9a3 3 0 1 1 4.2 2.75c-.8.37-1.3 1.16-1.3 2.04v.46M12 17.5h.01"/>' +
			'<circle cx="12" cy="12" r="9.2" fill="none" stroke="currentColor" stroke-width="2.1"/>',
		goed: '<path fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" ' +
			'stroke-linejoin="round" d="M20 6.5 9.4 17 4 11.7"/>',
		hulp: '<path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 ' +
			'1.1.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 ' +
			'1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.3 2.2Z"/>'
	};

	function baken(soort) {
		return '<div class="cf-exit__mark' + ('goed' === soort ? ' cf-exit__mark--goed' : '') +
			'" aria-hidden="true"><svg viewBox="0 0 24 24">' + ICONEN[soort] + '</svg></div>';
	}

	function logoTag() {
		var img = document.querySelector('#cf-logo-voorbeeld img');
		if (!img) return '';

		return '<img class="cf-exit__logo" src="' + ontsnap(img.src) + '" alt="" style="height:' +
			(parseInt(waarde('brand_logo_height'), 10) || 34) + 'px">';
	}

	function schermVraag() {
		return baken('vraag') +
			'<h2 class="cf-exit__title">' + ontsnap(tekst('txt_question')) + '</h2>' +
			'<p class="cf-exit__body">' + ontsnap(tekst('txt_question_sub')) + '</p>' +
			'<div class="cf-exit__actions">' +
				'<button type="button" class="cf-exit__btn cf-exit__btn--ghost">' + ontsnap(tekst('txt_yes')) + '</button>' +
				'<button type="button" class="cf-exit__btn cf-exit__btn--primary">' + ontsnap(tekst('txt_no')) + '</button>' +
			'</div>';
	}

	function schermJa() {
		var knop = tekst('appointment_url')
			? '<div class="cf-exit__actions"><span class="cf-exit__btn cf-exit__btn--primary">' +
				ontsnap(tekst('txt_appointment')) + '</span></div>'
			: '';

		return baken('goed') +
			'<h2 class="cf-exit__title">' + ontsnap(tekst('txt_yes_title')) + '</h2>' +
			'<p class="cf-exit__body">' + ontsnap(tekst('txt_yes_body')) + '</p>' + knop;
	}

	function schermNee() {
		var wa = tekst('whatsapp')
			? '<span class="cf-exit__btn cf-exit__btn--whatsapp">' +
				'<svg class="cf-exit__icon" viewBox="0 0 24 24" aria-hidden="true">' +
				'<path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Z"/>' +
				'</svg><span>' + ontsnap(tekst('txt_whatsapp')) + '</span></span>'
			: '';

		var hulp = (tekst('help_url') && tekst('help_label'))
			? '<span class="cf-exit__help">' + ontsnap(tekst('help_label')) + '</span>'
			: '';

		return baken('hulp') +
			'<h2 class="cf-exit__title">' + ontsnap(tekst('txt_no_title')) + '</h2>' +
			'<p class="cf-exit__body">' + ontsnap(tekst('txt_no_body')) + '</p>' +
			'<div class="cf-exit__actions cf-exit__actions--stack">' +
				'<span class="cf-exit__btn cf-exit__btn--call">' +
					'<svg class="cf-exit__icon" viewBox="0 0 24 24" aria-hidden="true">' +
					'<path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.3 2.2Z"/>' +
					'</svg><span>' + ontsnap(tekst('txt_call') + ' ' + tekst('phone_display')) + '</span></span>' +
				wa +
			'</div>' + hulp +
			'<p class="cf-exit__note">' + ontsnap(tekst('txt_hours')) + '</p>';
	}

	function teken() {
		huisstijl();

		var binnen = stap === 'yes' ? schermJa() : (stap === 'no' ? schermNee() : schermVraag());

		popup.innerHTML =
			'<div class="cf-exit__dialog">' +
				'<button type="button" class="cf-exit__close" aria-label="' + ontsnap(tekst('txt_close')) + '">' +
					'<svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20">' +
					'<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>' +
				'</button>' +
				logoTag() +
				'<div class="cf-exit__step">' + binnen + '</div>' +
			'</div>';
	}

	/* --- Meebewegen ---------------------------------------------------------- */

	form.addEventListener('input', teken);
	form.addEventListener('change', teken);

	Array.prototype.forEach.call(document.querySelectorAll('.cf-preview__stap'), function (knop) {
		knop.addEventListener('click', function () {
			stap = knop.getAttribute('data-stap');
			Array.prototype.forEach.call(document.querySelectorAll('.cf-preview__stap'), function (k) {
				k.classList.toggle('is-active', k === knop);
			});
			teken();
		});
	});

	/* --- Kleurcodes naast de kiezers ------------------------------------------ */

	Array.prototype.forEach.call(document.querySelectorAll('.cf-kleur__code'), function (veld) {
		var kiezer = document.getElementById(veld.getAttribute('data-voor'));
		if (!kiezer) return;

		kiezer.addEventListener('input', function () {
			veld.value = kiezer.value;
		});

		veld.addEventListener('input', function () {
			var v = veld.value.trim();
			if (/^#[0-9a-fA-F]{6}$/.test(v)) {
				kiezer.value = v;
				teken();
			}
		});
	});

	/* --- Logo kiezen ---------------------------------------------------------- */

	var kiesKnop = document.getElementById('cf-logo-kies');
	var wegKnop = document.getElementById('cf-logo-weg');
	var logoVak = document.getElementById('cf-logo-voorbeeld');
	var logoVeld = document.getElementById('cf-brand_logo');
	var kiezer = null;

	if (kiesKnop && window.wp && window.wp.media) {
		kiesKnop.addEventListener('click', function () {
			if (!kiezer) {
				kiezer = window.wp.media({
					title: 'Logo kiezen',
					library: { type: 'image' },
					button: { text: 'Gebruik dit logo' },
					multiple: false
				});

				kiezer.on('select', function () {
					var keuze = kiezer.state().get('selection').first().toJSON();
					var src = (keuze.sizes && keuze.sizes.medium) ? keuze.sizes.medium.url : keuze.url;

					logoVeld.value = keuze.id;
					logoVak.innerHTML = '<img src="' + ontsnap(src) + '" alt="">';
					if (wegKnop) wegKnop.hidden = false;
					teken();
				});
			}
			kiezer.open();
		});
	}

	if (wegKnop) {
		wegKnop.addEventListener('click', function () {
			logoVeld.value = '';
			logoVak.innerHTML = '<span class="cf-hulp">Nog geen logo gekozen</span>';
			wegKnop.hidden = true;
			teken();
		});
	}

	teken();
})();
