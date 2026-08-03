/*
 * Test de Laposta-instelsectie op de instelpagina.
 *
 * Wat hier vooral toe doet: de sleutel mag nooit terug te lezen zijn in de
 * HTML, ook niet als beheerder. En een foute sleutel moet een nette melding
 * geven in plaats van een witte pagina.
 */
const { chromium } = require('playwright');

const BASIS = 'http://127.0.0.1:8099';
const INSTEL = BASIS + '/wp-admin/admin.php?page=cf-exit-popup-instellingen';

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

	// Inloggen
	await page.goto(BASIS + '/wp-login.php', { waitUntil: 'load' });
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'TestWachtwoord123!');
	await page.click('#wp-submit');
	await page.waitForLoadState('load');

	console.log('\n[1] Sectie zonder koppeling');
	await page.goto(INSTEL, { waitUntil: 'load' });

	const kop = await page.locator('h2.cf-card__title', { hasText: 'Laposta' }).count();
	check('Laposta-sectie staat op de pagina', kop === 1);

	const veld = page.locator('input[name="laposta_sleutel"]');
	check('sleutelveld aanwezig', await veld.count() === 1);
	check('sleutelveld is van het type password', await veld.getAttribute('type') === 'password');
	check('geen lijstkiezer zonder koppeling', await page.locator('select[name="laposta_lijst"]').count() === 0);

	console.log('\n[2] Foute sleutel invullen');
	const NEP = 'cf-test-nepsleutel-1234567890';
	await veld.fill(NEP);
	await page.click('button.button-primary[type="submit"]');
	await page.waitForLoadState('load');

	const html = await page.content();
	check('de sleutel staat NIET in de HTML', !html.includes(NEP),
		'de sleutel lekt naar de pagina');

	const waarschuwing = await page.locator('.cf-note--warn').filter({ hasText: 'verbinding' }).count();
	check('nette melding dat de verbinding niet lukt', waarschuwing >= 1);
	check('pagina blijft werken (titel aanwezig)',
		await page.locator('h1.cf-dash__title').count() === 1);

	console.log('\n[3] Loskoppelen');
	// De knop vraagt eerst om bevestiging; zonder antwoord verstuurt het
	// formulier niet.
	page.once('dialog', d => d.accept());
	await page.locator('button[name="laposta_los"]').click();
	await page.waitForLoadState('load');
	check('melding na loskoppelen',
		(await page.content()).includes('koppeling met Laposta is verbroken'));
	check('terug naar de staat zonder koppeling',
		await page.locator('button[name="laposta_los"]').count() === 0);

	console.log('\n[4] De rest van de pagina doet het nog');
	check('deel-sectie nog aanwezig',
		await page.locator('h2.cf-card__title', { hasText: 'Delen zonder inloggen' }).count() === 1);
	check('meldingen-sectie nog aanwezig',
		await page.locator('h2.cf-card__title', { hasText: 'Meldingen' }).count() === 1);
	check('geen JS-fouten', jsfouten.length === 0, jsfouten.join(' | '));

	await browser.close();
	console.log('\n' + (fout === 0 ? 'Koppeling in orde.' : fout + ' controle(s) mislukt.'));
	process.exit(fout === 0 ? 0 : 1);
})();
