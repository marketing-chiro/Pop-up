/*
 * Test de tussenstap "waar ging uw vraag over?".
 *
 * Achtergrond: in de eerste 30 dagen zeiden 37 mensen dat ze niet konden
 * vinden wat ze zochten, en vertrokken er 35 zonder ergens op te klikken. Van
 * die 35 wisten we niets. Deze stap legt de reden vast op het moment van
 * aantikken, dus ook als iemand daarna alsnog weggaat.
 *
 * Draait tegen dist/testpagina.html, dus zonder WordPress.
 *
 *   node test/browser-reden.js
 */
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const DIST = path.join(__dirname, '..', 'dist');
const POORT = 8123;
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
	const gemeld = [];
	page.on('pageerror', e => jsfouten.push(e.message));

	// De testpagina heeft geen meetpunt, dus we luisteren aan de voorkant mee.
	await page.addInitScript(() => {
		window.__cf = [];
		window.addEventListener('cf-exit-popup', e => window.__cf.push(e.detail));
	});

	try {
		await page.goto(PAGINA, { waitUntil: 'load' });
		await page.evaluate(() => window.cfExitPopup.toon());
		await page.waitForSelector('.cf-exit.is-open');

		console.log('\n[1] Na "nee" komt eerst de vraag waar het over ging');
		await page.click('[data-cf="answer-no"]');
		await page.waitForSelector('[data-step="reason"]:not([hidden])');
		check('redenscherm verschijnt', await page.locator('[data-step="reason"]').isVisible());
		check('het hulpscherm staat nog niet open',
			!(await page.locator('[data-step="no"]').isVisible()));

		const knoppen = await page.locator('[data-step="reason"] [data-cf="reason"]').all();
		const redenen = [];
		for (const k of knoppen) redenen.push(await k.getAttribute('data-reason'));
		check('vier redenen om uit te kiezen', knoppen.length === 4, 'nu ' + knoppen.length);
		check('de vier verwachte waarden',
			JSON.stringify(redenen) === JSON.stringify(['kosten', 'klacht', 'afspraak', 'anders']),
			JSON.stringify(redenen));

		console.log('\n[2] Een reden aantikken wordt gemeld');
		await page.click('[data-reason="kosten"]');
		await page.waitForSelector('[data-step="no"]:not([hidden])');

		const meldingen = await page.evaluate(() => window.__cf);
		const reden = meldingen.filter(m => m.action === 'reason');
		check('er gaat een reason-melding uit', reden.length === 1);
		check('met de aangetikte reden erin',
			reden.length > 0 && reden[0].data.reason === 'kosten',
			reden.length ? JSON.stringify(reden[0].data) : 'geen melding');

		console.log('\n[3] Alleen de bijpassende knop, niet allebei');
		// Dit ging eerder mis zonder dat een test het zag: allebei de knoppen
		// bleven staan omdat de eigen display-regel het [hidden] van de browser
		// overschreef. Vandaar dat hier op zichtbaarheid getest wordt.
		check('de kostenknop is zichtbaar',
			await page.locator('[data-step="no"] [data-cf="help"]').isVisible());
		check('de afspraakknop is verborgen',
			!(await page.locator('[data-step="no"] [data-cf="appointment"]').isVisible()));
		check('precies één hoofdknop op het scherm',
			await page.locator('[data-step="no"] .cf-exit__btn--primary:visible').count() === 1);
		check('en dat is de kostenknop',
			await page.locator('[data-step="no"] .cf-exit__btn--primary')
				.first().getAttribute('data-cf') === 'help');

		console.log('\n[4] Bij "klacht" hoort de afspraakknop bovenaan');
		await page.goto(PAGINA, { waitUntil: 'load' });
		await page.evaluate(() => { window.cfExitPopup.vergeet(); window.cfExitPopup.toon(); });
		await page.waitForSelector('.cf-exit.is-open');
		await page.click('[data-cf="answer-no"]');
		await page.waitForSelector('[data-step="reason"]:not([hidden])');
		await page.click('[data-reason="klacht"]');
		await page.waitForSelector('[data-step="no"]:not([hidden])');
		check('na "klacht" is de afspraakknop zichtbaar',
			await page.locator('[data-step="no"] [data-cf="appointment"]').isVisible());
		check('en de kostenknop verborgen',
			!(await page.locator('[data-step="no"] [data-cf="help"]').isVisible()));

		console.log('\n[5] "Iets anders" laat de standaardvolgorde staan');
		await page.goto(PAGINA, { waitUntil: 'load' });
		await page.evaluate(() => { window.cfExitPopup.vergeet(); window.cfExitPopup.toon(); });
		await page.waitForSelector('.cf-exit.is-open');
		await page.click('[data-cf="answer-no"]');
		await page.click('[data-reason="anders"]');
		await page.waitForSelector('[data-step="no"]:not([hidden])');
		check('afspraak blijft de hoofdknop',
			await page.locator('[data-step="no"] [data-cf="appointment"]').isVisible());
		check('nog steeds maar één hoofdknop',
			await page.locator('[data-step="no"] .cf-exit__btn--primary:visible').count() === 1);

		console.log('\n[6] Wegklikken op het redenscherm');
		await page.goto(PAGINA, { waitUntil: 'load' });
		await page.evaluate(() => { window.cfExitPopup.vergeet(); window.cfExitPopup.toon(); });
		await page.waitForSelector('.cf-exit.is-open');
		await page.click('[data-cf="answer-no"]');
		await page.waitForSelector('[data-step="reason"]:not([hidden])');
		await page.click('.cf-exit__close');
		await wacht(400);
		const sluit = (await page.evaluate(() => window.__cf)).filter(m => m.action === 'close');
		check('sluiten meldt het redenscherm',
			sluit.length > 0 && sluit[sluit.length - 1].data.step === 'reason',
			sluit.length ? JSON.stringify(sluit[sluit.length - 1].data) : 'geen melding');

		check('geen JS-fouten', jsfouten.length === 0, jsfouten.join(' | '));
	} finally {
		await browser.close();
		server.kill();
	}

	console.log('\n' + (fout === 0 ? 'Redenstap in orde.' : fout + ' controle(s) mislukt.'));
	process.exit(fout === 0 ? 0 : 1);
})();
