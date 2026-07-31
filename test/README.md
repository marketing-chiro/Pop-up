# Testen tegen een echte WordPress

De browsertests in `demo/` draaien tegen losse bestanden. Die zeggen niets over
de WordPress-kant: het meetpunt, het wegschrijven naar de database en de
queries van het dashboard. Daar is deze map voor.

Zo is het opgezet toen dit voor het eerst getest werd:

1. WordPress uitpakken en de SQLite-integratie erin zetten, zodat er geen
   database-server nodig is.
2. `wp-config.php` schrijven en installeren via `wp-admin/install.php?step=2`.
3. De plugin in `wp-content/plugins/` zetten en activeren met
   `activate_plugin()`.
4. Een paar pagina's aanmaken die op de echte site ook bestaan
   (`/kosten-en-vergoedingen/`, `/contact-2/`, `/uw-afspraak/`,
   `/lage-rugpijn/`) en mooie URL's aanzetten.
5. `php -S 127.0.0.1:8099 -t . router.php` draaien.
6. `node e2e-wordpress.js`

## Wat deze test heeft gevonden

De eerste keer dat dit tegen een echte database liep, kwam er meteen een fout
uit die in de browsertests nooit zichtbaar was: `returning` is een gereserveerd
woord in SQLite, waardoor de hele query achter de kerncijfers stukliep. Het
dashboard toonde toen nullen alsof het een rustige week was.

Dat is precies waarom deze test bestaat. Draai hem opnieuw na elke wijziging
aan de queries in `class-cf-exit-popup-storage.php`.

## De losse testbestanden

| Bestand | Wat het controleert |
|---|---|
| `browser-basis.js` | Verschijnen, ja/nee, sluiten, rustperiode, uitgesloten pagina's, mobiel |
| `browser-instellingen.js` | De keuzes uit het Analytics-rapport: wachttijd, interactie-eis, hulplink |
| `browser-menu.js` | Het menu bovenaan mag geen pop-up geven, ook niet bij voorbijschieten |
| `browser-terugkerend.js` | Bezoekteller en de andere vraag voor wie terugkomt |
| `e2e-wordpress.js` | Tegen een echte WordPress: laden, meten, wegschrijven |
| `e2e-app.js` | De installeerbare app: manifest, iconen, offline, toegang |

De eerste vier draaien tegen `dist/testpagina.html` en hebben geen server nodig.
De laatste twee vragen een draaiende WordPress; zie hierboven.
