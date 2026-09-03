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
		// Gespreksballon; zie de toelichting in exit-intent-popup.js.
		hulp: '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
			'stroke-linejoin="round" d="M20.5 11.7c0 4.3-3.8 7.8-8.5 7.8-1 0-2-.16-2.9-.45L4 20.5l1.3-4.2' +
			'a7.4 7.4 0 0 1-1.3-4.6C4 7.4 7.8 3.9 12.5 3.9s8 3.5 8 7.8Z"/>' +
			'<circle cx="9.3" cy="11.7" r="1.05" fill="currentColor"/>' +
			'<circle cx="12.5" cy="11.7" r="1.05" fill="currentColor"/>' +
			'<circle cx="15.7" cy="11.7" r="1.05" fill="currentColor"/>'
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

	// Zelfde tussenstap als in de echte pop-up: vier brede knoppen, zodat een
	// reden aantikken bijna niets kost.
	function schermReden() {
		var knop = function (veld) {
			return '<button type="button" class="cf-exit__btn cf-exit__btn--ghost">' +
				ontsnap(tekst(veld)) + '</button>';
		};

		return baken('vraag') +
			'<h2 class="cf-exit__title">' + ontsnap(tekst('txt_reason_title')) + '</h2>' +
			'<p class="cf-exit__body">' + ontsnap(tekst('txt_reason_sub')) + '</p>' +
			'<div class="cf-exit__actions cf-exit__actions--stack">' +
				knop('txt_reason_costs') + knop('txt_reason_compl') +
				knop('txt_reason_appt') + knop('txt_reason_other') +
			'</div>';
	}

	// Zelfde opzet als het echte nee-scherm: één knop die bij het antwoord
	// hoort, het terugbelverzoek als uitweg, en bellen en WhatsApp op één
	// rustige regel eronder. In het voorbeeld tonen we de kostenknop, want dat
	// is het meest voorkomende onderwerp.
	function schermNee() {
		var kosten = (tekst('help_url') && tekst('help_label'))
			? '<span class="cf-exit__btn cf-exit__btn--primary">' +
				ontsnap(tekst('help_label')) + '</span>'
			: (tekst('appointment_url')
				? '<span class="cf-exit__btn cf-exit__btn--primary">' +
					ontsnap(tekst('txt_appointment')) + '</span>'
				: '');

		var terugbel = '<span class="cf-exit__btn cf-exit__btn--outline">' +
			ontsnap(tekst('txt_callback_btn')) + '</span>';

		var wa = tekst('whatsapp') ? '<a href="#" onclick="return false">WhatsApp</a>' : '';

		return baken('hulp') +
			'<h2 class="cf-exit__title">' + ontsnap(tekst('txt_no_title')) + '</h2>' +
			'<p class="cf-exit__body">' + ontsnap(tekst('txt_no_body')) + '</p>' +
			'<div class="cf-exit__actions cf-exit__actions--stack">' +
				kosten + terugbel +
			'</div>' +
			'<p class="cf-exit__direct">' +
				'<span>' + ontsnap(tekst('txt_direct_label')) + '</span>' +
				'<a href="#" onclick="return false">' + ontsnap(tekst('phone_display')) + '</a>' +
				wa +
			'</p>';
	}

	function teken() {
		huisstijl();

		var binnen = 'yes' === stap ? schermJa()
			: 'no' === stap ? schermNee()
			: 'reason' === stap ? schermReden()
			: schermVraag();

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
