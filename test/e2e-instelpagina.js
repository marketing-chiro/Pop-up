const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8099';
const fail = [];
const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport:{width:1500,height:1100}, deviceScaleFactor:2 });
  const p = await ctx.newPage();
  const errs=[]; p.on('pageerror',e=>errs.push(e.message));
  // De reset vraagt om bevestiging; een echte gebruiker klikt daar OK.
  p.on('dialog', d => d.accept());
  p.on('console',m=>{ if(m.type()==='error') errs.push('console: '+m.text()); });

  await p.goto(B+'/wp-login.php');
  await p.fill('#user_login','admin'); await p.fill('#user_pass','TestWachtwoord123!');
  await p.click('#wp-submit'); await p.waitForLoadState('networkidle');

  console.log('\n[1] De instelpagina');
  // Schoon beginnen: een eerdere run kan instellingen hebben achtergelaten.
  await p.goto(B+'/wp-admin/admin.php?page=cf-exit-popup-popup');
  await p.waitForTimeout(500);
  await p.click('button:has-text("Terug naar standaard")');
  await p.waitForLoadState('networkidle');
  await p.goto(B+'/wp-admin/admin.php?page=cf-exit-popup-popup');
  await p.waitForTimeout(900);
  check('pagina opent', await p.locator('.cf-editor').isVisible());
  check('vier tabbladen', (await p.locator('.cf-tab').count()) === 4);
  check('voorbeeld getekend', (await p.$eval('#cf-preview-popup', n => n.innerHTML.length)) > 200);
  check('voorbeeld toont de vraag', (await p.textContent('#cf-preview-popup')).includes('Heeft u gevonden'));
  await p.screenshot({path:'shots/editor-teksten.png'});

  console.log('\n[2] Voorbeeld beweegt mee');
  await p.fill('#cf-txt_question', 'Kunnen we u nog ergens mee helpen?');
  await p.waitForTimeout(250);
  check('gewijzigde vraag verschijnt meteen',
    (await p.textContent('#cf-preview-popup')).includes('Kunnen we u nog ergens mee helpen?'));

  await p.click('.cf-preview__stap[data-stap="no"]');
  await p.waitForTimeout(250);
  check('scherm "niet gevonden" toont de belknop',
    (await p.textContent('#cf-preview-popup')).includes('024 - 355 88 30'));
  check('scherm "niet gevonden" toont WhatsApp',
    (await p.textContent('#cf-preview-popup')).includes('Stuur een WhatsApp'));

  console.log('\n[3] Huisstijl');
  await p.click('.cf-tab[data-tab="huisstijl"]');
  await p.waitForTimeout(200);
  check('kleurvelden zichtbaar', await p.locator('#cf-brand_primary').isVisible());
  await p.fill('#cf-brand_primary', '#7a1f3d');
  await p.dispatchEvent('#cf-brand_primary', 'input');
  await p.waitForTimeout(300);
  const kleur = await p.evaluate(()=> getComputedStyle(document.getElementById('cf-preview-popup')).getPropertyValue('--cf-primary').trim());
  check('nieuwe hoofdkleur in het voorbeeld (' + kleur + ')', kleur === '#7a1f3d');
  const knopKleur = await p.evaluate(()=> {
    const k = document.querySelector('#cf-preview-popup .cf-exit__btn--call');
    return k ? getComputedStyle(k).backgroundColor : '';
  });
  check('belknop kleurt mee (' + knopKleur + ')', knopKleur === 'rgb(122, 31, 61)');
  await p.screenshot({path:'shots/editor-huisstijl.png'});

  console.log('\n[4] Opslaan en doorwerken naar de site');
  await p.fill('#cf-brand_primary', '#1b6a63');
  await p.dispatchEvent('#cf-brand_primary', 'input');
  await p.click('.cf-tab[data-tab="inhoud"]');
  await p.fill('#cf-txt_question', 'Heeft u kunnen vinden wat u zocht?');
  await p.click('button:has-text("Opslaan")');
  await p.waitForLoadState('networkidle');
  check('bevestiging na opslaan', (await p.textContent('.notice')).includes('Opgeslagen'));
  check('waarde blijft staan', (await p.inputValue('#cf-txt_question')) === 'Heeft u kunnen vinden wat u zocht?');

  // Nu op de site zelf kijken.
  const pg = await ctx.newPage();
  await pg.goto(B + '/lage-rugpijn/?cf-popup=test');
  await pg.waitForTimeout(1200);
  check('site toont de nieuwe vraag',
    (await pg.textContent('#cf-exit-title')) === 'Heeft u kunnen vinden wat u zocht?');
  check('huisstijl-CSS staat op de pagina',
    (await pg.content()).includes('--cf-primary:#1b6a63'));
  await pg.close();

  console.log('\n[5] Uitzetten via de schakelaar');
  await p.click('.cf-tab[data-tab="gedrag"]');
  await p.uncheck('input[name="enabled"]');
  await p.click('button:has-text("Opslaan")');
  await p.waitForLoadState('networkidle');
  const pg2 = await ctx.newPage();
  await pg2.goto(B + '/lage-rugpijn/?cf-popup=test');
  await pg2.waitForTimeout(900);
  check('pop-up laadt niet meer', (await pg2.locator('.cf-exit').count()) === 0);
  check('script wordt niet meer ingeladen', !(await pg2.content()).includes('exit-intent-popup.js'));
  await pg2.close();

  console.log('\n[6] Terug naar standaard');
  await p.click('button:has-text("Terug naar standaard")');
  await p.waitForLoadState('networkidle');
  check('melding hersteld', (await p.textContent('.notice')).includes('standaardwaarden'));
  check('vraag weer op de oorspronkelijke tekst',
    (await p.inputValue('#cf-txt_question')) === 'Heeft u gevonden wat u zocht?');
  const pg3 = await ctx.newPage();
  await pg3.goto(B + '/lage-rugpijn/?cf-popup=test');
  await pg3.waitForTimeout(1000);
  check('pop-up weer actief', await pg3.locator('.cf-exit__dialog').isVisible());
  await pg3.close();

  const echt = errs.filter(e => !e.includes('ERR_CONNECTION_RESET'));
  check('geen JS-fouten', echt.length===0); if(echt.length) console.log(echt);
  await b.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Instelpagina in orde.'));
  process.exit(fail.length ? 1 : 0);
})();
