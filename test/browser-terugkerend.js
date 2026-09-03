const { chromium } = require('playwright');
const path = require('path');
const DEMO = 'file://' + path.resolve('/home/user/Pop-up/demo/demo.html');
const fail = [];
const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  // Eén context = één browser, zodat localStorage tussen bezoeken blijft staan.
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const errs = [];

  console.log('\n[1] Eerste bezoek');
  let p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(DEMO + '?cf-popup=test');
  await p.waitForTimeout(900);
  const titel1 = await p.textContent('#cf-exit-title');
  check('eerste bezoek: standaardvraag ("' + titel1 + '")', /gevonden wat u zocht/i.test(titel1));
  check('teller staat op 1', (await p.evaluate(() => localStorage.getItem('cf_exit_popup_visits'))) === '1');
  await p.close();

  console.log('\n[2] Doorklikken binnen hetzelfde bezoek (ander tabblad)');
  p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(DEMO + '?cf-popup=test');
  await p.waitForTimeout(900);
  const teller2 = await p.evaluate(() => localStorage.getItem('cf_exit_popup_visits'));
  check('teller blijft 1 binnen dezelfde sessie (nu: ' + teller2 + ')', teller2 === '1');
  const titel2 = await p.textContent('#cf-exit-title');
  check('nog steeds de standaardvraag', /gevonden wat u zocht/i.test(titel2));
  await p.close();
  await ctx.close();

  console.log('\n[3] Nieuw bezoek later (nieuwe sessie, zelfde browser)');
  // Sessieopslag hoort bij het tabblad/de sessie; we bootsen een terugkeer na
  // met dezelfde localStorage maar zonder sessievlag.
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 800 } });
  let p2 = await ctx2.newPage();
  p2.on('pageerror', e => errs.push(e.message));
  await p2.goto(DEMO);
  await p2.evaluate(() => {
    localStorage.setItem('cf_exit_popup_visits', '1');
    // Laatste activiteit 45 minuten terug: dat geldt als een nieuw bezoek.
    localStorage.setItem('cf_exit_popup_last_seen', String(Date.now() - 45 * 60 * 1000));
    localStorage.removeItem('cf_exit_popup_shown_at');
  });
  await p2.goto(DEMO + '?cf-popup=test');
  await p2.waitForTimeout(900);
  const teller3 = await p2.evaluate(() => localStorage.getItem('cf_exit_popup_visits'));
  check('teller loopt naar 2 (nu: ' + teller3 + ')', teller3 === '2');
  const titel3 = await p2.textContent('#cf-exit-title');
  check('tweede bezoek: andere vraag ("' + titel3 + '")', /helpen/i.test(titel3));
  const sub3 = await p2.textContent('.cf-exit__body');
  check('tekst verwijst naar het eerdere bezoek', /eerder geweest/i.test(sub3));

  // Bij "kunnen we u ergens mee helpen?" horen andere antwoorden dan bij
  // "heeft u gevonden wat u zocht?". Ze stonden er eerst nog onder: op de
  // vraag of we konden helpen antwoordde je dan met "ja, gelukt". En omdat
  // "ja" hier juist om hulp vraagt, moet die knop ook naar de hulpstap gaan
  // en niet naar het afscheidsscherm.
  const knoppen = await p2.$$eval('[data-step="ask"] .cf-exit__btn',
    els => els.map(e => [e.textContent.trim(), e.getAttribute('data-cf')]));
  check('eerste antwoord is "Ja graag" (nu: "' + knoppen[0][0] + '")', knoppen[0][0] === 'Ja graag');
  check('tweede antwoord is "Nee, dankjewel" (nu: "' + knoppen[1][0] + '")', knoppen[1][0] === 'Nee, dankjewel');
  check('"Ja graag" is de hoofdknop',
    await p2.$eval('[data-step="ask"] .cf-exit__btn--primary', e => e.textContent.trim()) === 'Ja graag');

  await p2.click('[data-step="ask"] .cf-exit__btn--primary');
  await p2.waitForTimeout(400);
  check('"Ja graag" leidt naar de hulpstap, niet naar het afscheid',
    await p2.isVisible('[data-step="reason"]') && !(await p2.isVisible('[data-step="yes"]')));
  await ctx2.close();

  check('geen JS-fouten', errs.length === 0);
  if (errs.length) console.log(errs);
  await b.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Alle controles geslaagd.'));
  process.exit(fail.length ? 1 : 0);
})();
