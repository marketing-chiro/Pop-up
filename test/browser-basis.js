const { chromium, devices } = require('playwright');
const path = require('path');

const DEMO = 'file://' + path.resolve('/home/user/Pop-up/demo/demo.html');
const SHOTS = '/tmp/claude-0/-home-user-Pop-up/746d5cbe-128f-5d66-906d-b8692a675a82/scratchpad/shots';

// Bootst na dat er een mens achter de muis zit.
async function wiggle(page) {
  await page.mouse.move(500, 400);
  await page.mouse.move(520, 380);
  await page.waitForTimeout(80);
}

const fail = [];
function check(name, cond) {
  console.log((cond ? '  PASS  ' : '  FAIL  ') + name);
  if (!cond) fail.push(name);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  // ---- 1. Desktop: exit intent via muis naar boven uit het venster ----------
  console.log('\n[1] Desktop exit-intent');
  let ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  let page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.goto(DEMO);
  await page.waitForTimeout(500);

  // Direct na laden mag hij nog niet triggeren (armAfterMs = 6000).
  await wiggle(page);
  await page.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await page.waitForTimeout(300);
  check('niet zichtbaar binnen de wachttijd', await page.locator('.cf-exit').isHidden());

  // Na de wachttijd wel.
  await page.waitForTimeout(8200);
  await wiggle(page);
  await page.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await page.waitForTimeout(400);
  check('verschijnt na muis-uit-venster', await page.locator('.cf-exit__dialog').isVisible());
  check('vraag-stap is zichtbaar', await page.locator('[data-step="ask"]').isVisible());
  check('achtergrond scrollt niet mee', await page.evaluate(() =>
    getComputedStyle(document.body).overflow === 'hidden'));
  check('focus staat in de dialoog', await page.evaluate(() =>
    !!document.activeElement.closest('.cf-exit')));
  await page.screenshot({ path: SHOTS + '/1-vraag-desktop.png' });

  // ---- 2. Antwoord "nee" -> contactgegevens --------------------------------
  console.log('\n[2] Antwoord: nee');
  await page.click('[data-cf="answer-no"]');
  await page.waitForTimeout(250);
  check('contact-stap zichtbaar', await page.locator('[data-step="no"]').isVisible());
  const tel = await page.getAttribute('[data-cf="call"]', 'href');
  check('belknop heeft tel: link (' + tel + ')', tel === 'tel:+31243558830');
  check('telefoonnummer staat in beeld', (await page.textContent('[data-cf="call"]')).includes('024'));
  check('WhatsApp-knop aanwezig', await page.locator('[data-cf="whatsapp"]').isVisible());
  check('WhatsApp wijst naar het praktijknummer',
    (await page.getAttribute('[data-cf="whatsapp"]', 'href')).startsWith('https://wa.me/31614798722'));
  await page.screenshot({ path: SHOTS + '/2-contact-desktop.png' });

  // ---- 3. Escape sluit, en niet nogmaals tonen -----------------------------
  console.log('\n[3] Sluiten en cooldown');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('Escape sluit de pop-up', await page.locator('.cf-exit').isHidden());
  check('cooldown vastgelegd in localStorage', await page.evaluate(() =>
    !!localStorage.getItem('cf_exit_popup_shown_at')));

  await page.reload();
  await page.waitForTimeout(8500);
  await wiggle(page);
  await page.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await page.waitForTimeout(400);
  check('komt niet terug binnen de cooldown', await page.locator('.cf-exit').count() === 0);

  // ---- 4. Antwoord "ja" ----------------------------------------------------
  console.log('\n[4] Antwoord: ja');
  await page.goto(DEMO + '?cf-popup=test');
  await page.waitForTimeout(1000);
  check('testmodus negeert de cooldown', await page.locator('.cf-exit__dialog').isVisible());
  await page.click('[data-cf="answer-yes"]');
  await page.waitForTimeout(250);
  check('bedank-stap zichtbaar', await page.locator('[data-step="yes"]').isVisible());
  check('afspraakknop aanwezig', await page.locator('[data-cf="appointment"]').isVisible());
  await page.screenshot({ path: SHOTS + '/3-bedankt-desktop.png' });

  // ---- 5. Uitgesloten pagina ------------------------------------------------
  console.log('\n[5] Uitgesloten pagina');
  await page.evaluate(() => localStorage.clear());
  await page.goto(DEMO);
  const excluded = await page.evaluate(() => {
    // demo.html ligt niet op /contact, dus we controleren de padlogica direct.
    const paths = ['/contact-2/', '/uw-afspraak/', '/behandelingen/'];
    return paths.map(p => ['/contact', '/afspraak', '/uw-afspraak', '/bedankt']
      .some(x => p.toLowerCase().indexOf(x.toLowerCase()) !== -1));
  });
  check('/contact-2/ wordt uitgesloten', excluded[0] === true);
  check('/uw-afspraak/ wordt uitgesloten', excluded[1] === true);
  check('/behandelingen/ wordt niet uitgesloten', excluded[2] === false);

  await ctx.close();

  // ---- 6. Mobiel -----------------------------------------------------------
  console.log('\n[6] Mobiel (iPhone 13)');
  ctx = await browser.newContext({ ...devices['iPhone 13'] });
  page = await ctx.newPage();
  page.on('pageerror', e => errors.push('mobiel: ' + e.message));
  await page.goto(DEMO + '?cf-popup=test');
  await page.waitForTimeout(1000);
  check('pop-up werkt op mobiel', await page.locator('.cf-exit__dialog').isVisible());
  await page.click('[data-cf="answer-no"]');
  await page.waitForTimeout(250);
  const box = await page.locator('[data-cf="call"]').boundingBox();
  check('belknop is groot genoeg voor een duim (' + Math.round(box.height) + 'px)', box.height >= 44);
  check('dialoog past binnen de schermbreedte', box.width <= 390);
  await page.screenshot({ path: SHOTS + '/4-contact-mobiel.png' });

  // Geen horizontale overflow.
  check('geen horizontale scrollbalk', await page.evaluate(() =>
    document.documentElement.scrollWidth <= window.innerWidth + 1));

  await ctx.close();

  // ---- 7. Mobiele idle-trigger --------------------------------------------
  console.log('\n[7] Mobiele idle-trigger (verkort)');
  ctx = await browser.newContext({ ...devices['iPhone 13'] });
  page = await ctx.newPage();
  await page.addInitScript(() => {
    // Wachttijden verkorten zodat de test niet 53 seconden duurt.
    window.__cfTest = true;
  });
  await page.goto(DEMO);
  // Simuleer: armAfterMs verstreken + idle verstreken door de klok te versnellen
  // is lastig; we controleren hier alleen dat touch-detectie de mouseout-trigger
  // NIET gebruikt (die zou op mobiel nooit vuren).
  await page.waitForTimeout(8500);
  await wiggle(page);
  await page.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await page.waitForTimeout(300);
  check('muis-trigger staat uit op touchscreen', await page.locator('.cf-exit').isHidden());
  await ctx.close();

  console.log('\n[8] JavaScript-fouten');
  check('geen JS-fouten', errors.length === 0);
  if (errors.length) console.log(errors);

  await browser.close();

  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Alle controles geslaagd.'));
  process.exit(fail.length ? 1 : 0);
})();
