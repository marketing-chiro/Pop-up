const { chromium, devices } = require('playwright');
const path = require('path');
const DEMO = 'file://' + path.resolve('/home/user/Pop-up/demo/demo.html');
const SHOTS = __dirname + '/shots';
const fail = [];
const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

function fireExit(page) {
  return page.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const errors = [];

  // --- 1. Anti-bot: zonder interactie mag er niets gebeuren ---------------
  console.log('\n[1] Zonder interactie (het bot-scenario)');
  let ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  let page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type()==='error') errors.push('console: '+m.text()); });
  await page.goto(DEMO);
  await page.waitForTimeout(6600);          // voorbij armAfterMs (6s)
  await fireExit(page);                      // exit-signaal, maar nooit bewogen
  await page.waitForTimeout(400);
  check('geen pop-up zonder enige interactie', await page.locator('.cf-exit').isHidden());

  // Nu wel een echte beweging, daarna het signaal.
  await page.mouse.move(400, 400);
  await page.mouse.move(420, 380);
  await page.waitForTimeout(200);
  await fireExit(page);
  await page.waitForTimeout(400);
  check('wel een pop-up na een echte beweging', await page.locator('.cf-exit__dialog').isVisible());

  // --- 2. Nieuwe wachttijd van 6 seconden --------------------------------
  console.log('\n[2] Wachttijd 6 seconden');
  await ctx.close();
  ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(DEMO);
  await page.mouse.move(300, 300);
  await page.waitForTimeout(4000);
  await page.mouse.move(320, 320);
  await fireExit(page);
  await page.waitForTimeout(300);
  check('na 4s nog niet scherp', await page.locator('.cf-exit').isHidden());
  await page.waitForTimeout(2800);
  await page.mouse.move(340, 340);
  await page.waitForTimeout(150);
  await fireExit(page);
  await page.waitForTimeout(400);
  check('na 6,8s wel scherp', await page.locator('.cf-exit__dialog').isVisible());

  // --- 3. Hulplink naar kosten en vergoedingen ---------------------------
  console.log('\n[3] Hulplink in het nee-scherm');
  await page.click('[data-cf="answer-no"]');
  await page.waitForTimeout(250);
  const help = page.locator('[data-cf="help"]');
  check('hulplink aanwezig', await help.isVisible());
  check('hulplink wijst naar kosten en vergoedingen',
    (await help.getAttribute('href')) === '/kosten-en-vergoedingen/');
  check('hulplink noemt kosten of vergoeding',
    /kosten|vergoeding/i.test(await help.textContent()));
  check('bellen blijft het hoofdaanbod (knop staat boven de link)',
    await page.evaluate(() => {
      const btn = document.querySelector('[data-cf="call"]').getBoundingClientRect();
      const lnk = document.querySelector('[data-cf="help"]').getBoundingClientRect();
      return btn.top < lnk.top;
    }));
  await page.screenshot({ path: SHOTS + '/na-rapport-nee.png' });

  // --- 4. Uitgesloten pagina's uit het rapport ---------------------------
  console.log('\n[4] Uitgesloten pagina\'s');
  const paths = await page.evaluate(() => {
    const ex = ['contact','afspraak','bedankt','screening','vacature','sollicitatie'];
    const test = p => ex.some(x => p.toLowerCase().indexOf(x.toLowerCase()) !== -1);
    return {
      contact2:      test('/contact-2/'),
      afspraakChiro: test('/afspraak-chiro/'),
      eersteAfspr:   test('/je-1e-afspraak/'),
      screening:     test('/screening/'),
      vacatures:     test('/vacatures/'),
      sollicitatie:  test('/sollicitatie/'),
      kosten:        test('/kosten-en-vergoedingen/'),
      ischias:       test('/ischias-pijnverlichting/'),
      home:          test('/')
    };
  });
  check('/contact-2/ uitgesloten', paths.contact2);
  check('/afspraak-chiro/ uitgesloten', paths.afspraakChiro);
  check('/je-1e-afspraak/ uitgesloten', paths.eersteAfspr);
  check('/screening/ uitgesloten', paths.screening);
  check('/vacatures/ uitgesloten', paths.vacatures);
  check('/sollicitatie/ uitgesloten', paths.sollicitatie);
  check('kosten-en-vergoedingen NIET uitgesloten (daar willen we hem juist)', !paths.kosten);
  check('klachtenpagina NIET uitgesloten', !paths.ischias);
  await ctx.close();

  // --- 5. Mobiel: idle-trigger mag niet vuren zonder interactie ----------
  console.log('\n[5] Mobiel zonder interactie');
  ctx = await browser.newContext({ ...devices['iPhone 13'] });
  page = await ctx.newPage();
  page.on('pageerror', e => errors.push('mobiel: '+e.message));
  await page.goto(DEMO);
  await page.waitForTimeout(6600);
  check('mobiel: nog niets scherp zonder aanraking', await page.locator('.cf-exit').isHidden());
  await ctx.close();

  // --- 6. Alles nog heel ------------------------------------------------
  console.log('\n[6] Bestaand gedrag');
  ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(DEMO + '?cf-popup=test');
  await page.waitForTimeout(900);
  check('testmodus werkt nog', await page.locator('.cf-exit__dialog').isVisible());
  await page.click('[data-cf="answer-yes"]');
  await page.waitForTimeout(250);
  check('ja-scherm werkt nog', await page.locator('[data-step="yes"]').isVisible());
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('Escape sluit nog', await page.locator('.cf-exit').isHidden());
  await ctx.close();

  check('geen JS-fouten', errors.length === 0);
  if (errors.length) console.log(errors);
  await browser.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Alle controles geslaagd.'));
  process.exit(fail.length ? 1 : 0);
})();
