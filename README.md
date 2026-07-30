# Exit-intent pop-up voor Chiro-Fysio

Herkent wanneer een bezoeker de site dreigt te verlaten en vraagt dan één korte
vraag: **"Heeft u gevonden wat u zocht?"**

- **Ja** → korte bedankboodschap, met een knop om direct een afspraak te maken.
- **Nee** → het telefoonnummer van de praktijk als grote belknop (en optioneel WhatsApp).

Geen dependencies, geen tracking cookies, geen abonnement op een externe dienst.

![Zo ziet het eruit](docs/screenshot-vraag.png)

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

**Op desktop** houden we de muis in de gaten. Zodra die de bovenkant van het
venster verlaat — richting de adresbalk, de tabbladen of het kruisje — is dat
het signaal. Dat is precies het moment waarop iemand op het punt staat te
vertrekken, en nog niet weg is.

**Op mobiel en tablet** bestaat er geen muis. Daar gebruiken we twee andere
signalen: 45 seconden lang geen enkele aanraking, of heel snel omhoog swipen
naar de bovenkant van de pagina (meestal een teken dat iemand naar de terugknop
of de adresbalk gaat).

Wil je het rustiger houden? Zet `enableMobile` op `false` of verhoog
`mobileIdleMs`.

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

## Meten of het werkt

Als Google Analytics of Google Tag Manager op de site staat, worden deze
gebeurtenissen automatisch doorgegeven onder de naam `cf_exit_popup`:

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

---

## Zelf testen

Open `demo/demo.html` in de browser. Dat is een neppagina met uitleg erin, een
knop om het "al getoond"-geheugen te wissen, en genoeg tekst om het
scrollgedrag na te bootsen.

---

## Wat staat waar

```
src/                          de bronbestanden - hier pas je dingen aan
  exit-intent-popup.js        gedrag + alle instellingen
  exit-intent-popup.css       vormgeving + kleuren
wordpress/                    de plugin
dist/                         het resultaat van ./build.sh (zip + snippet)
demo/demo.html                testpagina
build.sh                      bouwt dist/ opnieuw na een wijziging
```
