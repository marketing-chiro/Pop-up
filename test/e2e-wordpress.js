const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099';
const fail = [];
const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };

async function wiggle(p) {
  await p.mouse.move(600, 500);
  await p.mouse.move(620, 480);
  await p.waitForTimeout(80);
}
async function exit(p) {
  await p.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await p.waitForTimeout(700);   // voorbij exitGraceMs
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const errs = [];

  console.log('\n[1] Pop-up op een gewone pagina');
  let ctx = await b.newContext({ viewport:{width:1280,height:800} });
  let p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type()==='error') errs.push('console: '+m.text()); });

  // Meetverzoeken meelezen.
  const posts = [];
  p.on('request', r => { if (r.url().includes('cf-exit-popup/v1/event')) posts.push(r.method()); });
  p.on('response', async r => {
    if (r.url().includes('cf-exit-popup/v1/event')) posts.push('status:' + r.status());
  });

  await p.goto(BASE + '/lage-rugpijn/');
  check('script geladen op de pagina', await p.evaluate(() => typeof window.cfExitPopup === 'object'));
  check('meetpunt doorgegeven', await p.evaluate(() => !!(window.CF_EXIT_POPUP && window.CF_EXIT_POPUP.endpoint)));

  await wiggle(p);
  await p.waitForTimeout(6300);
  await wiggle(p);
  await exit(p);
  check('pop-up verschijnt', await p.locator('.cf-exit__dialog').isVisible());

  await p.click('[data-cf="answer-no"]');
  await p.waitForTimeout(400);
  check('nee-scherm met belknop', await p.locator('[data-cf="call"]').isVisible());
  check('hulplink naar kosten', await p.locator('[data-cf="help"]').isVisible());

  // Klik op de hulplink registreren zonder echt weg te navigeren.
  await p.evaluate(() => {
    const a = document.querySelector('[data-cf="help"]');
    a.setAttribute('href', '#');
    a.click();
  });
  await p.waitForTimeout(900);
  console.log('     meetverzoeken:', posts.join(' | '));
  await ctx.close();

  console.log('\n[2] Uitgesloten pagina\'s');
  for (const [pad, mag] of [['/contact-2/', false], ['/uw-afspraak/', false], ['/kosten-en-vergoedingen/', true]]) {
    ctx = await b.newContext({ viewport:{width:1280,height:800} });
    p = await ctx.newPage();
    p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + pad);
    await wiggle(p);
    await p.waitForTimeout(6300);
    await wiggle(p);
    await exit(p);
    const zichtbaar = await p.locator('.cf-exit__dialog').isVisible().catch(() => false);
    check(pad + (mag ? ' toont de pop-up' : ' toont GEEN pop-up'), zichtbaar === mag);
    await ctx.close();
  }

  console.log('\n[3] JavaScript');
  check('geen JS-fouten', errs.length === 0);
  if (errs.length) console.log(errs);

  await b.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Browserkant in orde.'));
})();
