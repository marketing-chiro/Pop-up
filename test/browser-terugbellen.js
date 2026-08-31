/*
 * Test het terugbelverzoek in de pop-up.
 *
 * Waarom dit er is: in 30 dagen kregen 37 mensen die iets niet konden vinden
 * een groot telefoonnummer te zien, en belde er niemand. Bellen vraagt veel.
 * Een nummer achterlaten kost drie seconden en kan buiten openingstijden.
 *
 * Draait tegen dist/testpagina.html, dus zonder WordPress. Het versturen zelf
 * vangen we hier af; de WordPress-kant wordt apart getest.
 *
 *   node test/browser-terugbellen.js
 */
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const DIST = path.join(__dirname, '..', 'dist');
const POORT = 8125;
const PAGINA = 'http://127.0.0.1:' + POORT + '/testpagina.html';

let goed = 0, fout = 0;
function check(naam, ok, extra) {
	if (ok) { goed++; console.log('  PASS  ' + naam); }
	else { fout++; console.log('  FAIL  ' + naam + (extra ? '  -> ' + extra : '')); }
}

function wacht(ms) { return new Promise(r => setTimeout(r, ms)); }

(async () => {
	const server = spawn('python3', ['-m', 'http.server', String(POORT), '--bind', '127.0.0.1'],
		{ cwd: DIST, stdio: 'ignore' });
	await wacht(1200);

	const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
	const page = await browser.newPage();
	const jsfouten = [];
	page.on('pageerror', e => jsfouten.push(e.message));

	// De testpagina heeft geen meetpunt. We zetten er zelf een neer en vangen
	// de verstuurde gegevens op, zodat we kunnen zien wat er de deur uit gaat.
	await page.addInitScript(() => {
		window.__verstuurd = [];
		window.CF_EXIT_POPUP = window.CF_EXIT_POPUP || {};
		window.CF_EXIT_POPUP.callbackEndpoint = '/nep-meetpunt';

		const echt = window.fetch;
		window.fetch = function (url, opties) {
			if (String(url).indexOf('/nep-meetpunt') !== -1) {
				window.__verstuurd.push(JSON.parse(opties.body));
				return Promise.resolve({ ok: true, status: 200 });
			}
			return echt.apply(this, arguments);
		};
	});

	async function naarNeeScherm() {
		await page.evaluate(() => { window.cfExitPopup.vergeet(); window.cfExitPopup.toon(); });
		await page.waitForSelector('.cf-exit.is-open');
		await page.click('[data-cf="answer-no"]');
		await page.waitForSelector('[data-step="reason"]:not([hidden])');
		await page.click('[data-reason="kosten"]');
		await page.waitForSelector('[data-step="no"]:not([hidden])');
	}

	try {
		await page.goto(PAGINA, { waitUntil: 'load' });

		console.log('\n[1] De knop staat op het hulpscherm');
		await naarNeeScherm();
		check('terugbelknop aanwezig',
			await page.locator('[data-cf="callback-open"]').isVisible());
		check('het telefoonnummer staat er nog steeds',
			await page.locator('[data-step="no"] [data-cf="call"]').isVisible());

		console.log('\n[2] Het formulier');
		await page.click('[data-cf="callback-open"]');
		await page.waitForSelector('[data-step="callback"]:not([hidden])');
		check('twee zichtbare velden, niet meer',
			await page.locator('[data-step="callback"] .cf-exit__veld input').count() === 2);
		check('naam en telefoon',
			await page.locator('[data-step="callback"] input[name="naam"]').count() === 1 &&
			await page.locator('[data-step="callback"] input[name="telefoon"]').count() === 1);
		// Bewust geen vraagveld: daar zou iemand een klacht in kunnen zetten, en
		// dan staat er een gezondheidsgegeven in een marketingdatabase.
		check('geen veld voor de vraag zelf',
			await page.locator('[data-step="callback"] textarea').count() === 0);
		check('het lokvak voor robots is onzichtbaar', await page.evaluate(() => {
			const v = document.querySelector('.cf-exit__val input');
			if (!v) return false;
			return v.getBoundingClientRect().left < -1000;
		}));

		console.log('\n[3] Leeg versturen doet niets');
		await page.click('[data-step="callback"] button[type="submit"]');
		await wacht(300);
		check('blijft op het formulier staan',
			await page.locator('[data-step="callback"]').isVisible());
		check('foutmelding zichtbaar',
			await page.locator('[data-cf="callback-fout"]').isVisible());
		check('er is niets verstuurd',
			(await page.evaluate(() => window.__verstuurd)).length === 0);

		console.log('\n[4] Ingevuld versturen');
		await page.fill('[data-step="callback"] input[name="naam"]', 'Jan de Vries');
		await page.fill('[data-step="callback"] input[name="telefoon"]', '06 14 79 87 22');
		await page.click('[data-step="callback"] button[type="submit"]');
		await page.waitForSelector('[data-step="callback-ok"]:not([hidden])', { timeout: 5000 });
		check('bevestiging verschijnt',
			await page.locator('[data-step="callback-ok"]').isVisible());

		const verstuurd = (await page.evaluate(() => window.__verstuurd))[0] || {};
		check('naam gaat mee', verstuurd.name === 'Jan de Vries', JSON.stringify(verstuurd));
		check('nummer gaat mee', verstuurd.phone === '06 14 79 87 22');
		check('de reden gaat mee', verstuurd.reason === 'kosten', 'reason=' + verstuurd.reason);
		check('het lokvak is leeg', verstuurd.website === '');
		check('de invultijd gaat mee', typeof verstuurd.elapsed === 'number' && verstuurd.elapsed > 0);

		check('geen JS-fouten', jsfouten.length === 0, jsfouten.join(' | '));
	} finally {
		await browser.close();
		server.kill();
	}

	console.log('\n' + (fout === 0 ? 'Terugbelverzoek in orde.' : fout + ' controle(s) mislukt.'));
	process.exit(fout === 0 ? 0 : 1);
})();
