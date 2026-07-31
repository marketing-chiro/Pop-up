/*!
 * De installeerbare app.
 *
 * Drie taken: het achtergrondscript aanmelden zodat de app ook zonder
 * verbinding werkt, de installatieknop tonen wanneer de browser dat toestaat,
 * en de cijfers verversen zonder de pagina opnieuw te laden.
 */
(function () {
	'use strict';

	var CFG = window.CF_APP;
	if (!CFG) return;

	var knopInstalleren = document.getElementById('cf-app-install');
	var knopVerversen = document.getElementById('cf-app-refresh');
	var status = document.getElementById('cf-app-state');

	/* --- Statusregel ------------------------------------------------------ */

	function meld(tekst, soort) {
		if (!status) return;

		if (!tekst) {
			status.hidden = true;
			status.textContent = '';
			status.className = 'cf-app__state';
			return;
		}

		status.textContent = tekst;
		status.className = 'cf-app__state' + (soort ? ' cf-app__state--' + soort : '');
		status.hidden = false;
	}

	/* --- Werken zonder verbinding ----------------------------------------- */

	if ('serviceWorker' in navigator) {
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(CFG.sw, { scope: CFG.scope }).catch(function () {
				// Lukt niet? Dan werkt de app gewoon zonder offline-functie.
			});
		});
	}

	function toonVerbinding() {
		if (navigator.onLine) {
			meld('');
		} else {
			meld('geen verbinding - laatst opgehaalde cijfers', 'offline');
		}
	}

	window.addEventListener('online', toonVerbinding);
	window.addEventListener('offline', toonVerbinding);
	toonVerbinding();

	/* --- Installeren ------------------------------------------------------- */

	var installatie = null;

	window.addEventListener('beforeinstallprompt', function (e) {
		// De browser biedt aan de app te installeren. We bewaren dat aanbod en
		// tonen onze eigen knop, zodat het moment aan de gebruiker is.
		e.preventDefault();
		installatie = e;
		if (knopInstalleren) knopInstalleren.hidden = false;
	});

	if (knopInstalleren) {
		knopInstalleren.addEventListener('click', function () {
			if (!installatie) return;
			installatie.prompt();
			installatie.userChoice.then(function () {
				installatie = null;
				knopInstalleren.hidden = true;
			});
		});
	}

	window.addEventListener('appinstalled', function () {
		installatie = null;
		if (knopInstalleren) knopInstalleren.hidden = true;
	});

	/* --- Cijfers verversen -------------------------------------------------- */

	function nl(n) {
		return Number(n).toLocaleString('nl-NL');
	}

	// Werkt de kerncijfers bij zonder de pagina opnieuw te laden.
	function vulTegels(t) {
		var waarden = document.querySelectorAll('.cf-tile__value');
		if (waarden.length < 5) return;

		var beantwoord = t.found + t.not_found;
		var rij = [t.shown, t.not_found, t.rescued, t.lost, t.repeat_shown];

		for (var i = 0; i < 5; i++) {
			waarden[i].textContent = nl(rij[i]);
		}

		var noten = document.querySelectorAll('.cf-tile__note');
		if (noten.length >= 2) {
			noten[1].textContent = (beantwoord > 0 ? Math.round(t.not_found / beantwoord * 100) : 0) +
				'% van wie antwoord gaf';
		}
	}

	function ververs() {
		if (!knopVerversen) return;

		knopVerversen.classList.add('is-busy');
		knopVerversen.disabled = true;

		var url = CFG.stats + (CFG.stats.indexOf('?') === -1 ? '?' : '&') + 'periode=' + CFG.dagen;
		if (CFG.sleutel) url += '&sleutel=' + encodeURIComponent(CFG.sleutel);

		var kop = { 'Cache-Control': 'no-store' };
		// Ingelogde gebruikers hebben deze sleutel nodig; wie via een gedeelde
		// link kijkt niet, die stuurt de sleutel in het adres mee.
		if (CFG.nonce) kop['X-WP-Nonce'] = CFG.nonce;

		window.fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: kop })
			.then(function (r) {
				if (!r.ok) throw new Error(r.status);
				return r.json();
			})
			.then(function (data) {
				if (window.cfDashboard) window.cfDashboard.render(data);
				if (data.totals) vulTegels(data.totals);
				meld('bijgewerkt');
				window.setTimeout(function () {
					if (navigator.onLine) meld('');
				}, 2500);
			})
			.catch(function () {
				meld(navigator.onLine ? 'verversen lukte niet' : 'geen verbinding - laatst opgehaalde cijfers', 'offline');
			})
			.then(function () {
				knopVerversen.classList.remove('is-busy');
				knopVerversen.disabled = false;
			});
	}

	if (knopVerversen) {
		knopVerversen.addEventListener('click', ververs);
	}

	// Terug in beeld na een tijdje weg: even bijwerken, zodat je niet naar
	// cijfers van gisteren zit te kijken.
	var laatst = Date.now();
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState !== 'visible') return;
		if (Date.now() - laatst < 60000) return;
		laatst = Date.now();
		if (navigator.onLine) ververs();
	});
})();
