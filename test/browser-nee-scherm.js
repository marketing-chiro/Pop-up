/*
 * Test het herziene nee-scherm en het meten van wegklikken.
 *
 * Aanleiding: in 30 dagen kregen 37 bezoekers die zeiden dat ze iets niet
 * konden vinden een grote belknop te zien, en klikte niemand erop. De
 * afspraakknop stond op het verkeerde scherm. Deze test legt de nieuwe
 * volgorde vast zodat hij niet ongemerkt terugdraait.
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');

const WP = '/tmp/claude-0/-home-user-Pop-up/746d5cbe-128f-5d66-906d-b8692a675a82/scratchpad/wp/wordpress';

// Meten doen we in de database, niet aan de browserkant. sendBeacon stuurt een
// Blob, en daar geeft Playwright geen leesbare postData voor terug - je zou dan
// concluderen dat er niets verstuurd wordt terwijl het wel aankomt. Zo testen we
// bovendien de hele keten: script, REST-punt en opslag.
function closeTellingen() {
	try {
		return JSON.parse(execSync('php ' + WP + '/tel-close.php', { encoding: 'utf8' }));
	} catch (e) {
		return {};
	}
}

const BASIS = 'http://127.0.0.1:8099';
const PAGINA = BASIS + '/lage-rugpijn/?cf-popup=test';

let goed = 0, fout = 0;
function check(naam, ok, extra) {
	if (ok) { goed++; console.log('  PASS  ' + naam); }
	else { fout++; console.log('  FAIL  ' + naam + (extra ? '  -> ' + extra : '')); }
}

(async () => {
	const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
	const page = await browser.newPage();
	const jsfouten = [];
	const verzoeken = [];
	page.on('pageerror', e => jsfouten.push(e.message));
	page.on('request', r => {
		if (r.url().includes('cf-exit-popup/v1/event')) {
			verzoeken.push(r.postData() || '');
		}
	});

	await page.goto(PAGINA, { waitUntil: 'load' });
	await page.evaluate(() => window.cfExitPopup && window.cfExitPopup.toon());
	await page.waitForSelector('.cf-exit.is-open', { timeout: 5000 });

	console.log('\n[1] Naar het nee-scherm');
	await page.click('[data-cf="answer-no"]');
	await page.waitForSelector('[data-step="no"]:not([hidden])');

	const knoppen = await page.locator('[data-step="no"] .cf-exit__actions .cf-exit__btn').all();
	const labels = [];
	for (const k of knoppen) labels.push((await k.innerText()).trim());
	console.log('     knopvolgorde: ' + JSON.stringify(labels));

	check('afspraakknop staat er', labels.length >= 1);
	check('afspraak is de EERSTE knop',
		await knoppen[0].getAttribute('data-cf') === 'appointment', labels[0]);
	check('kostenknop is nu een knop, geen tekstlink',
		await page.locator('[data-step="no"] .cf-exit__btn[data-cf="help"]').count() === 1);
	check('oude tekstlink is weg',
		await page.locator('[data-step="no"] .cf-exit__help').count() === 0);

	console.log('\n[2] Het telefoonnummer');
	check('telefoon is een regel, geen knop',
		await page.locator('[data-step="no"] .cf-exit__phone a[data-cf="call"]').count() === 1);
	check('telefoon zit NIET meer in de knoppenrij',
		await page.locator('[data-step="no"] .cf-exit__actions [data-cf="call"]').count() === 0);
	check('nummer blijft aanklikbaar om te bellen',
		(await page.locator('[data-step="no"] .cf-exit__phone a').getAttribute('href') || '').startsWith('tel:'));

	console.log('\n[3] Wegklikken wordt vastgelegd, met het scherm erbij');
	const voor = closeTellingen();
	await page.click('.cf-exit__close');
	await page.waitForTimeout(800);
	const naNo = closeTellingen();
	check('wegklikken op het nee-scherm komt binnen als step "no"',
		(naNo.no || 0) === (voor.no || 0) + 1,
		'voor=' + (voor.no || 0) + ' na=' + (naNo.no || 0));

	console.log('\n[4] Wegklikken bij de vraag zelf');
	await page.goto(PAGINA, { waitUntil: 'load' });
	await page.evaluate(() => window.cfExitPopup && window.cfExitPopup.vergeet());
	await page.evaluate(() => window.cfExitPopup && window.cfExitPopup.toon());
	await page.waitForSelector('.cf-exit.is-open');
	await page.click('.cf-exit__close');
	await page.waitForTimeout(800);
	const naAsk = closeTellingen();
	check('wegklikken bij de vraag komt binnen als step "ask"',
		(naAsk.ask || 0) === (naNo.ask || 0) + 1,
		'voor=' + (naNo.ask || 0) + ' na=' + (naAsk.ask || 0));

	check('geen JS-fouten', jsfouten.length === 0, jsfouten.join(' | '));

	await browser.close();
	console.log('\n' + (fout === 0 ? 'Nee-scherm in orde.' : fout + ' controle(s) mislukt.'));
	process.exit(fout === 0 ? 0 : 1);
})();
