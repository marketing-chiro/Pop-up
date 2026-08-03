/*
 * Test de losse campagneplugin, en of hij de pop-upplugin met rust laat.
 *
 * Het punt van de splitsing is dat de twee niets van elkaar nodig hebben.
 * Daarom controleren we hier niet alleen dat het campagnescherm werkt, maar
 * ook dat het pop-upscherm er niets van gemerkt heeft.
 */
const { chromium } = require('playwright');

const BASIS = 'http://127.0.0.1:8099';
const CAMP  = BASIS + '/wp-admin/admin.php?page=cf-campagnes';
const POPUP = BASIS + '/wp-admin/admin.php?page=cf-exit-popup-instellingen';

let goed = 0, fout = 0;
function check(naam, ok, extra) {
	if (ok) { goed++; console.log('  PASS  ' + naam); }
	else { fout++; console.log('  FAIL  ' + naam + (extra ? '  -> ' + extra : '')); }
}

(async () => {
	const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
	const page = await browser.newPage();
	const jsfouten = [];
	page.on('pageerror', e => jsfouten.push(e.message));

	await page.goto(BASIS + '/wp-login.php', { waitUntil: 'load' });
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'TestWachtwoord123!');
	await page.click('#wp-submit');
	await page.waitForLoadState('load');

	console.log('\n[1] Eigen menu, los van de pop-up');
	await page.goto(BASIS + '/wp-admin/index.php', { waitUntil: 'load' });
	check('eigen menu-item "E-mailcampagnes"',
		await page.locator('#adminmenu a', { hasText: 'E-mailcampagnes' }).count() >= 1);
	check('pop-upmenu staat er nog steeds apart',
		await page.locator('#adminmenu a', { hasText: 'Exit-pop-up' }).count() >= 1);

	console.log('\n[2] Campagnescherm zonder koppeling');
	await page.goto(CAMP, { waitUntil: 'load' });
	check('pagina opent', await page.locator('h1.cf-camp__title').count() === 1);
	check('koppelingsblok aanwezig',
		await page.locator('h2.cf-camp__cardtitle', { hasText: 'Laposta' }).count() === 1);
	const veld = page.locator('input[name="sleutel"]');
	check('sleutelveld is van het type password', await veld.getAttribute('type') === 'password');
	check('eigen stylesheet geladen',
		await page.locator('link[href*="chiro-fysio-campagnes/assets/admin.css"]').count() === 1);

	console.log('\n[3] Foute sleutel');
	const NEP = 'cf-nep-sleutel-abcdefghij';
	await veld.fill(NEP);
	await page.click('button.button-primary[type="submit"]');
	await page.waitForLoadState('load');
	check('de sleutel staat NIET in de HTML', !(await page.content()).includes(NEP));
	check('nette melding bij een storing',
		await page.locator('.cf-camp__note--warn').count() >= 1);

	console.log('\n[4] Loskoppelen');
	page.once('dialog', d => d.accept());
	await page.locator('button[name="loskoppelen"]').click();
	await page.waitForLoadState('load');
	check('melding na loskoppelen',
		(await page.content()).includes('koppeling met Laposta is verbroken'));

	console.log('\n[5] De pop-upplugin is niet aangeraakt');
	await page.goto(POPUP, { waitUntil: 'load' });
	const popupHtml = await page.content();
	check('pop-upinstellingen openen nog', await page.locator('h1.cf-dash__title').count() === 1);
	check('GEEN Laposta meer op het pop-upscherm', !popupHtml.includes('Laposta'));
	check('deel-sectie nog aanwezig',
		await page.locator('h2.cf-card__title', { hasText: 'Delen zonder inloggen' }).count() === 1);

	check('geen JS-fouten', jsfouten.length === 0, jsfouten.join(' | '));

	await browser.close();
	console.log('\n' + (fout === 0 ? 'Splitsing in orde.' : fout + ' controle(s) mislukt.'));
	process.exit(fout === 0 ? 0 : 1);
})();
