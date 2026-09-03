const { chromium } = require('playwright');

// Een testpagina met een menubalk bovenaan, zoals chiro-fysio.nl die heeft.
const PAGE = `<!doctype html><html lang="nl"><head><meta charset="utf-8">
<style>
 body{margin:0;font-family:sans-serif}
 nav{position:fixed;top:0;left:0;right:0;height:56px;background:#1b6a63;color:#fff;
     display:flex;align-items:center;gap:22px;padding:0 20px;z-index:10}
 nav a{color:#fff;text-decoration:none}
 main{padding:90px 24px 800px}
</style></head><body>
<nav>
  <a href="#" id="m1">Klachten</a><a href="#" id="m2">Behandelingen</a>
  <a href="#" id="m3">Tarieven</a><a href="#" id="m4">Contact</a>
</nav>
<main><h1>Lage rugpijn</h1><p>Tekst zodat de pagina scrollbaar is.</p></main>
<script>window.CF_EXIT_POPUP={};</script>
<script src="SCRIPT"></script>
</body></html>`;

(async () => {
  const fs = require('fs');
  const os = require('os');
  const path = require('path');

  const js = fs.readFileSync(path.join(__dirname, '..', 'src', 'exit-intent-popup.js'), 'utf8');
  const css = fs.readFileSync(path.join(__dirname, '..', 'src', 'exit-intent-popup.css'), 'utf8');

  // In een tijdelijke map, niet in de repo: dit bestand wordt bij elke run
  // opnieuw gemaakt met de code van dat moment erin. Stond het in de map van
  // het project, dan meldde git na elke test een wijziging die niemand gemaakt
  // had.
  const pagina = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cf-menu-')), 'menupage.html');
  fs.writeFileSync(pagina, PAGE.replace('<script src="SCRIPT"></script>',
    '<style>'+css+'</style><script>'+js+'</script>'));

  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const fail = [];
  const check = (n,c) => { console.log((c?'  PASS  ':'  FAIL  ')+n); if(!c) fail.push(n); };

  async function fresh() {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
    const p = await ctx.newPage();
    await p.goto('file://' + pagina);
    await p.mouse.move(640, 500);         // echte bezoeker beweegt
    await p.waitForTimeout(6400);          // voorbij armAfterMs
    await p.mouse.move(640, 480);
    await p.waitForTimeout(120);
    return { ctx, p };
  }
  const zichtbaar = p => p.locator('.cf-exit__dialog').isVisible();

  // --- A. Naar het menu bewegen, binnen het venster blijven --------------
  console.log('\n[A] Bezoeker gaat naar het menu (blijft in het venster)');
  let { ctx, p } = await fresh();
  await p.mouse.move(640, 200);
  await p.mouse.move(500, 90);
  await p.mouse.move(420, 28);            // midden op de menubalk
  await p.waitForTimeout(500);
  check('menubalk aanraken geeft geen pop-up', !(await zichtbaar(p)));

  // Menu-item aanklikken
  await p.click('#m3');
  await p.waitForTimeout(400);
  check('op een menu-item klikken geeft geen pop-up', !(await zichtbaar(p)));
  await ctx.close();

  // --- B. Voorbij het menu schieten en meteen terugkomen ----------------
  console.log('\n[B] Bezoeker schiet voorbij het menu en komt terug');
  ({ ctx, p } = await fresh());
  await p.mouse.move(640, 300);
  await p.mouse.move(600, 60);
  // Voorbij de bovenrand: de browser meldt dan een vertrek.
  await p.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await p.waitForTimeout(120);            // even buiten...
  await p.mouse.move(600, 40);            // ...en meteen terug op het menu
  await p.mouse.move(590, 45);
  await p.waitForTimeout(700);
  check('voorbijschieten en terugkomen geeft GEEN pop-up', !(await zichtbaar(p)));
  await ctx.close();

  // --- C. Echt vertrekken --------------------------------------------------
  console.log('\n[C] Bezoeker vertrekt echt (komt niet terug)');
  ({ ctx, p } = await fresh());
  await p.evaluate(() => document.dispatchEvent(
    new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await p.waitForTimeout(900);
  check('echt vertrek geeft WEL een pop-up', await zichtbaar(p));
  await ctx.close();

  await b.close();
  console.log('\n' + (fail.length ? 'MISLUKT: ' + fail.join(' | ') : 'Alle controles geslaagd.'));
  process.exit(fail.length ? 1 : 0);
})();
