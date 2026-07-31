const { chromium, devices } = require('playwright');
const B = 'http://127.0.0.1:8099';
const fail = [];
const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const errs = [];

  console.log('\n[1] Zonder toegang');
  let ctx = await b.newContext();
  let p = await ctx.newPage();
  let r = await p.goto(B + '/exit-dashboard/');
  check('afgeschermd voor wie er niet bij mag (' + r.status() + ')', r.status() === 403);
  check('geen cijfers zichtbaar', !(await p.content()).includes('cf-tile__value'));
  check('afgeschermd voor zoekmachines', (r.headers()['x-robots-tag'] || '').includes('noindex'));
  await ctx.close();

  console.log('\n[2] Ingelogd als beheerder');
  ctx = await b.newContext({ viewport:{width:1280,height:1000}, deviceScaleFactor:2 });
  p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message));
  p.on('console', m => { if (m.type()==='error') errs.push('console: '+m.text()); });
  await p.goto(B + '/wp-login.php');
  await p.fill('#user_login','admin'); await p.fill('#user_pass','TestWachtwoord123!');
  await p.click('#wp-submit'); await p.waitForLoadState('networkidle');

  r = await p.goto(B + '/exit-dashboard/');
  check('app opent voor beheerder (' + r.status() + ')', r.status() === 200);
  check('eigen balk bovenaan', await p.locator('.cf-app__bar').isVisible());
  check('kerncijfers zichtbaar', (await p.locator('.cf-tile').count()) === 5);
  check('statuskaart zichtbaar', await p.locator('.cf-health').isVisible());
  check('grafiek getekend', (await p.$eval('#cf-chart-outcome', n => n.innerHTML.length)) > 50);
  check('verversknop aanwezig', await p.locator('#cf-app-refresh').isVisible());
  check('geen WordPress-beheerbalk', (await p.locator('#wpadminbar').count()) === 0);
  check('geen horizontale scrollbalk', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
  await p.screenshot({ path: 'shots/app-desktop.png' });

  console.log('\n[3] Het manifest');
  const man = await p.evaluate(async () => {
    const l = document.querySelector('link[rel="manifest"]');
    const r = await fetch(l.href);
    return { status: r.status, type: r.headers.get('content-type'), data: await r.json() };
  });
  check('manifest bereikbaar (' + man.status + ')', man.status === 200);
  check('juiste type', (man.type||'').includes('manifest'));
  check('opent in eigen venster', man.data.display === 'standalone');
  check('drie iconen', man.data.icons.length === 3);
  check('maskable icoon aanwezig', man.data.icons.some(i => i.purpose === 'maskable'));
  console.log('     naam:', man.data.name, '| start:', man.data.start_url);

  // Iconen echt ophalen
  for (const ic of man.data.icons) {
    const st = await p.evaluate(async (u) => (await fetch(u)).status, ic.src);
    check('icoon ' + ic.sizes + (ic.purpose?' (maskable)':'') + ' bereikbaar', st === 200);
  }

  console.log('\n[4] Achtergrondscript en verversen');
  const sw = await p.evaluate(async () => {
    const r = await fetch(window.CF_APP.sw);
    return { status: r.status, type: r.headers.get('content-type'), body: (await r.text()).slice(0, 60) };
  });
  check('achtergrondscript bereikbaar (' + sw.status + ')', sw.status === 200);
  check('geleverd als javascript', (sw.type||'').includes('javascript'));

  // Zoals de app het doet: mét de sleutel die WordPress voor ingelogde
  // gebruikers eist.
  const stats = await p.evaluate(async () => {
    const r = await fetch(window.CF_APP.stats + '?periode=30', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': window.CF_APP.nonce }
    });
    return { status: r.status, data: r.ok ? await r.json() : null };
  });

  // En zonder sleutel hoort het juist geweigerd te worden.
  const zonder = await p.evaluate(async () => {
    const r = await fetch(window.CF_APP.stats + '?periode=30', { credentials: 'omit' });
    return r.status;
  });
  check('cijfers afgeschermd zonder sleutel (' + zonder + ')', zonder === 401 || zonder === 403);
  check('cijfers ophalen werkt (' + stats.status + ')', stats.status === 200);
  check('cijfers bevatten totalen', !!(stats.data && stats.data.totals));
  check('cijfers bevatten status', !!(stats.data && stats.data.health));

  await p.click('#cf-app-refresh');
  await p.waitForTimeout(1200);
  check('verversen meldt zich', ['bijgewerkt',''].includes((await p.textContent('#cf-app-state')).trim()));

  console.log('\n[5] Periode wisselen');
  await p.click('.cf-dash__period:has-text("7 dagen")');
  await p.waitForLoadState('networkidle');
  check('periode 7 dagen actief', (await p.textContent('.cf-dash__period.is-active')).includes('7'));
  await ctx.close();

  console.log('\n[6] Op de telefoon');
  ctx = await b.newContext({ ...devices['iPhone 13'] });
  p = await ctx.newPage();
  p.on('pageerror', e => errs.push('mobiel: '+e.message));
  await p.goto(B + '/wp-login.php');
  await p.fill('#user_login','admin'); await p.fill('#user_pass','TestWachtwoord123!');
  await p.click('#wp-submit'); await p.waitForLoadState('networkidle');
  await p.goto(B + '/exit-dashboard/');
  await p.waitForTimeout(700);
  check('mobiel: app opent', await p.locator('.cf-app__bar').isVisible());
  check('mobiel: geen horizontale scrollbalk', await p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
  await p.screenshot({ path: 'shots/app-mobiel.png' });
  await ctx.close();

  // WordPress probeert in het beheer wordpress.org te bereiken; dat lukt in deze
  // afgesloten omgeving niet en zegt niets over onze code.
  // Twee soorten meldingen tellen niet mee:
  //  - ERR_CONNECTION_RESET: WordPress probeert in het beheer wordpress.org te
  //    bereiken, wat in deze afgesloten omgeving niet lukt.
  //  - 401: die veroorzaakt deze test zelf, met het verzoek zonder sleutel dat
  //    juist geweigerd hoort te worden.
  const echt = errs.filter(e =>
    !e.includes('ERR_CONNECTION_RESET') && !e.includes('401'));
  check('geen JS-fouten', echt.length === 0);
  if (echt.length) console.log(echt);
  await b.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'App in orde.'));
  process.exit(fail.length ? 1 : 0);
})();
