# Exit-intent pop-up voor Chiro-Fysio

Herkent wanneer een bezoeker de site dreigt te verlaten en vraagt dan één korte
vraag: **"Heeft u gevonden wat u zocht?"**

- **Ja** → korte bedankboodschap, met een knop om direct een afspraak te maken.
- **Nee** → het telefoonnummer van de praktijk als grote belknop (en optioneel WhatsApp).

Daarbij hoort een dashboard in WordPress dat laat zien hoe vaak dit gebeurt, op
welke pagina's, en hoeveel van die bezoekers alsnog contact opnemen.

Geen dependencies, geen tracking cookies, geen abonnement op een externe dienst.

![Zo ziet het eruit](docs/screenshot-vraag.png)

---

## Eerst even zelf proberen

Twee manieren, allebei zonder iets te installeren:

- **`demo/demo.html`** — open dit bestand in de browser. Neppagina met uitleg,
  een knop om het "al getoond"-geheugen te wissen en genoeg tekst om te scrollen.
- **`dist/testpagina.html`** — één zelfstandig bestand met een statuspaneel
  (staat de detectie al scherp?), knoppen om de pop-up direct op te roepen, en
  een logboek dat live meeschrijft wat de tool detecteert. Handig om ook op de
  telefoon te bekijken: zet het bestand ergens neer waar je het kunt openen.

Het dashboard bekijken zonder de plugin te installeren kan met
**`demo/dashboard-preview.html`** — dezelfde weergave, gevuld met verzonnen
cijfers.

---

## Snel installeren op WordPress (aanbevolen)

1. Download `dist/chiro-fysio-exit-popup.zip` uit deze repository.
2. Ga in WordPress naar **Plugins → Nieuwe plugin → Plugin uploaden**.
3. Kies het zip-bestand, klik op **Nu installeren** en daarna op **Activeren**.

Klaar. De pop-up is meteen actief voor bezoekers.

> **Let op:** als je zelf ingelogd bent als beheerder zie je de pop-up niet.
> Dat is expres, zodat je rustig kunt werken. Wil je hem toch zien? Zet dan
> `?cf-popup=test` achter de URL, bijvoorbeeld
> `https://www.chiro-fysio.nl/?cf-popup=test`.

### Alternatief: plakken via een snippet-plugin

Gebruik je liever WPCode, "Insert Headers and Footers" of iets vergelijkbaars?
Neem dan de volledige inhoud van `dist/wpcode-snippet.html` over en plak die in
het **footer**-veld. Dat bestand bevat de CSS en JavaScript al bij elkaar.

---

## Voordat je live gaat: even controleren

Deze twee dingen wil je nalopen in `src/exit-intent-popup.js`, bovenin het blok
`CONFIG`:

| Instelling | Nu ingesteld op | Actie |
|---|---|---|
| `phoneDisplay` / `phoneHref` | `024 - 355 88 30` / `+31243558830` | **Controleer of dit klopt.** Ik heb dit nummer van een zoekresultaat gehaald, niet van jullie site zelf (die blokkeerde mijn verzoek). |
| `whatsapp` | leeg | Vul een **mobiel** nummer in als je WhatsApp wilt aanbieden, bijvoorbeeld `31612345678`. Zolang dit leeg is, wordt de WhatsApp-knop netjes verborgen. |

Na het aanpassen van `src/` draai je `./build.sh` om de plugin-zip en het
snippet opnieuw te bouwen.

---

## Alle instellingen

Alles staat bovenaan `src/exit-intent-popup.js`.

| Instelling | Standaard | Wat het doet |
|---|---|---|
| `phoneDisplay` | `024 - 355 88 30` | Nummer zoals de bezoeker het ziet |
| `phoneHref` | `+31243558830` | Nummer voor de belknop (internationaal, geen spaties) |
| `whatsapp` | `''` | WhatsApp-nummer, leeg = knop verbergen |
| `whatsappText` | vraag over de website | Tekst die vast in het WhatsApp-bericht staat |
| `appointmentUrl` | `/uw-afspraak/` | Afspraakknop in het ja-scherm, leeg = verbergen |
| `armAfterMs` | `8000` | Hoe lang iemand op de site moet zijn voor de pop-up scherp staat |
| `cooldownDays` | `7` | Hoeveel dagen iemand met rust wordt gelaten na het zien |
| `enableMobile` | `true` | Pop-up ook op telefoon/tablet |
| `mobileIdleMs` | `45000` | Mobiel: na hoeveel stilte de pop-up verschijnt (`0` = uit) |
| `excludePaths` | contact-, afspraak- en bedanktpagina's | Pagina's waar hij nooit verschijnt |
| `text` | Nederlandse teksten | Alle zinnen in de pop-up |

De kleuren staan bovenaan `src/exit-intent-popup.css` als CSS-variabelen
(`--cf-primary`, `--cf-accent`, …). Pas die aan naar de huisstijl en de rest
volgt vanzelf.

---

## Hoe herkent hij dat iemand weggaat?

> Liever visueel? Open **`dist/uitleg.html`** — daar kun je het signaal zelf
> uitlokken in een kader en zie je meteen wanneer het wel en niet afgaat.

**We volgen de muis niet.** Er worden geen muisbewegingen bijgehouden, opgeslagen
of in een kaart verwerkt. De browser geeft zelf één melding door — "de aanwijzer
heeft het venster verlaten" — en daarop stellen we één vraag: gebeurde dat aan de
bovenkant?

**Op desktop** is dat het hele signaal. Verlaat de aanwijzer het venster naar
boven — richting de adresbalk, de tabbladen, de terugknop of het kruisje — dan
staat iemand op het punt te vertrekken maar is die er nog. Gaat de muis er via
links, rechts of onder uit, dan negeren we het: daar liggen meestal een tweede
scherm of de taakbalk, en dat zegt niets over vertrekken.

**Op mobiel en tablet** bestaat er geen muis. Daar gebruiken we twee andere
signalen: 45 seconden lang geen enkele aanraking, of heel snel omhoog swipen
naar de bovenkant van de pagina (meestal een teken dat iemand naar de terugknop
of de adresbalk gaat).

Wil je het rustiger houden? Zet `enableMobile` op `false` of verhoog
`mobileIdleMs`.

### De vijf voorwaarden

Het signaal alleen is niet genoeg. Dit moet allemaal kloppen, in deze volgorde —
valt er één af, dan gebeurt er niets:

1. De bezoeker is niet op een contact-, afspraak- of bedanktpagina
2. De pop-up is deze bezoeker de afgelopen 7 dagen niet getoond
3. Het is geen ingelogde beheerder
4. De bezoeker is minstens 8 seconden op de pagina
5. Dán pas: de muis verlaat het venster aan de bovenkant

Na één vertoning gaat de herkenning uit voor de rest van het bezoek.

---

## Niet vervelend worden

Een pop-up die te vaak of te vroeg komt, kost bezoekers in plaats van dat hij ze
oplevert. Daarom zit er het volgende in:

- Pas actief **na 8 seconden** op de pagina.
- **Maximaal één keer per 7 dagen** per bezoeker.
- **Nooit** op de contact-, afspraak- of bedanktpagina — daar is de bezoeker al
  aan het doen wat we willen.
- Sluiten kan met het kruisje, met Escape, of door naast de pop-up te klikken.
- Beheerders die ingelogd zijn, zien hem niet.

---

## Toegankelijkheid en privacy

- Werkt volledig met het toetsenbord; de focus blijft binnen de pop-up en keert
  daarna terug naar waar de bezoeker was.
- `role="dialog"` en `aria-modal` voor schermlezers.
- Respecteert `prefers-reduced-motion` voor wie bewegende animaties liever niet ziet.
- Knoppen zijn minstens 50px hoog, prettig aan te tikken op een telefoon.
- **AVG:** er worden geen persoonsgegevens verzameld en geen tracking cookies
  geplaatst. Het enige dat wordt opgeslagen is één datum in `localStorage` van de
  bezoeker zelf, om te onthouden dat de pop-up al getoond is. Daar is geen
  cookiemelding voor nodig (functionele opslag).

---

## Het dashboard

Na activatie verschijnt **Exit-pop-up** in het linkermenu van WordPress.

![Het dashboard](docs/screenshot-dashboard.png)

### De vier cijfers bovenaan

| Cijfer | Wat het betekent |
|---|---|
| **Pop-up getoond** | Bezoekers die op het punt stonden te vertrekken |
| **Vond niet wat die zocht** | Daarvan het aantal dat "nee" antwoordde |
| **Nam alsnog contact op** | Die "nee" zeiden en daarna belden, appten of een afspraak maakten |
| **Toch weg, zonder contact** | Die "nee" zeiden en alsnog vertrokken — hier lag werk dat je misliep |

Het percentage bij "vond niet wat die zocht" rekent met de mensen die
daadwerkelijk antwoord gaven. Van wie wegklikt weten we het simpelweg niet, en
die meetellen zou het beeld vertekenen.

### Wat er verder in staat

- **Hoe liep het af** — alle vertoningen verdeeld over de vier uitkomsten.
- **Verloop per dag** — getoond tegenover "niet gevonden", met details bij hover.
- **Op welke pagina's liepen bezoekers vast** — het meest bruikbare lijstje van
  het hele dashboard. Hier stelden bezoekers een vraag die de pagina niet
  beantwoordde, dus dit is meteen je verbeterlijstje.
- **Wanneer op de dag** — verschijnt de pop-up vooral buiten openingstijden, dan
  is bellen op dat moment geen bruikbaar aanbod en is WhatsApp of een
  terugbelverzoek waardevoller.
- **Apparaat en signaal** — desktop tegenover mobiel, en waardoor de pop-up
  verscheen.
- **Laatste vertoningen** — de 50 meest recente regels, plus een knop om alles
  als CSV te downloaden voor Excel.

Kies bovenaan de periode: 7 dagen, 30 dagen, 90 dagen of 12 maanden.

### Wat er wel en niet wordt vastgelegd

Per vertoning wordt één regel bewaard: tijdstip, het pad van de pagina, of het
een desktop of telefoon was, welk signaal de pop-up opriep, het antwoord, en of
er daarna op een knop is geklikt.

Wat er **niet** in staat: geen IP-adres, geen naam, geen e-mailadres, en geen
cookie waarmee iemand over meerdere bezoeken te volgen is. Een zoekopdracht in
de URL (`?s=...`) wordt afgeknipt, omdat daar zomaar iets persoonlijks in kan
staan. Metingen ouder dan een jaar worden automatisch verwijderd.

Dat betekent dat je hiervoor geen cookiemelding of toestemming nodig hebt. Ga je
zelf uitbreiden met gegevens die wél naar een persoon te herleiden zijn, dan
verandert dat — laat dat dan even toetsen.

De metingen blijven staan als je de plugin deactiveert, dus je bent ze niet
kwijt als hij een keer uit gaat.

---

## Meten via Google Analytics

Naast het eigen dashboard worden dezelfde gebeurtenissen doorgegeven aan Google
Analytics of Tag Manager, als die op de site staan, onder de naam
`cf_exit_popup`:

| Actie | Wanneer |
|---|---|
| `open` | pop-up verschijnt (met de trigger: `mouseleave`, `idle` of `scroll-up`) |
| `answer` | bezoeker klikt ja of nee |
| `call` | bezoeker klikt op de belknop |
| `whatsapp` | bezoeker klikt op WhatsApp |
| `appointment` | bezoeker klikt op afspraak maken |
| `close` | pop-up wordt gesloten |

De verhouding tussen `open` en `answer: nee` vertelt je iets waardevols: hoeveel
bezoekers vertrekken zonder gevonden te hebben wat ze zochten. Dat is meteen een
lijstje met wat er op de site beter kan.

Wil je er zelf iets aan hangen, dan kan dat ook via een event op `window`:

```js
window.addEventListener('cf-exit-popup', function (e) {
  console.log(e.detail.action, e.detail.data);
});
```

Je kunt de pop-up ook zelf oproepen, bijvoorbeeld vanaf een eigen knop:

```js
cfExitPopup.toon();     // toon hem meteen
cfExitPopup.vergeet();  // wis het "al getoond"-geheugen en zet de detectie weer scherp
```

---

## Wat staat waar

```
src/                            de bronbestanden - hier pas je dingen aan
  exit-intent-popup.js          gedrag + alle instellingen van de pop-up
  exit-intent-popup.css         vormgeving + kleuren van de pop-up
  dashboard.js / dashboard.css  het dashboard in WordPress
wordpress/chiro-fysio-exit-popup/
  chiro-fysio-exit-popup.php    de plugin zelf
  includes/                     opslag, meetpunt en dashboardpagina
dist/                           resultaat van ./build.sh
  chiro-fysio-exit-popup.zip    de plugin, klaar om te uploaden
  wpcode-snippet.html           plakversie voor een snippet-plugin
  testpagina.html               zelfstandige testpagina
  uitleg.html                   visuele uitleg van de herkenning
demo/                           lokaal uitproberen
  demo.html                     testpagina met de pop-up
  dashboard-preview.html        dashboard met verzonnen cijfers
  uitleg.template.html          bron van de uitlegpagina
build.sh                        bouwt dist/ opnieuw na een wijziging
```

Pas je iets aan in `src/`? Draai daarna `./build.sh`, anders blijft de plugin op
de oude versie staan.
