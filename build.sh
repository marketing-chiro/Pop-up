#!/usr/bin/env bash
#
# Bouwt de installeerbare varianten vanuit src/.
#
#   1. wordpress/chiro-fysio-exit-popup/assets/  - de bronbestanden voor de plugin
#   2. dist/chiro-fysio-exit-popup.zip           - de plugin, klaar om te uploaden
#   3. dist/wpcode-snippet.html                  - CSS + JS in een blok, om te plakken
#
# Gebruik:  ./build.sh
#
set -euo pipefail

cd "$(dirname "$0")"

SRC="src"
PLUGIN_DIR="wordpress/chiro-fysio-exit-popup"
ASSETS="$PLUGIN_DIR/assets"
DIST="dist"

echo "==> Assets kopiëren naar de plugin"
mkdir -p "$ASSETS"
cp "$SRC/exit-intent-popup.css" "$ASSETS/"
cp "$SRC/exit-intent-popup.js" "$ASSETS/"
cp "$SRC/dashboard.css" "$ASSETS/"
cp "$SRC/dashboard.js" "$ASSETS/"
cp "$SRC/app.css" "$ASSETS/"
cp "$SRC/app.js" "$ASSETS/"
cp "$SRC/editor.css" "$ASSETS/"
cp "$SRC/editor.js" "$ASSETS/"

echo "==> Plugin-zips maken"
mkdir -p "$DIST"

# Twee losse plugins, bewust. De pop-up en de e-mailcampagnes hebben elkaar
# niet nodig: zet je de een uit, dan hoort de ander door te draaien.
plugin_versie() {
	sed -n 's/^ \* Version: *\(.*\)$/\1/p' "wordpress/$1/$1.php" | head -1 | tr -d ' '
}

for PLUGIN in chiro-fysio-exit-popup chiro-fysio-campagnes; do
	VERSIE="$(plugin_versie "$PLUGIN")"

	if [ -z "$VERSIE" ]; then
		echo "    FOUT: geen versie gevonden in $PLUGIN" >&2
		exit 1
	fi

	rm -f "$DIST/$PLUGIN.zip"
	( cd wordpress && zip -qr "../$DIST/$PLUGIN.zip" "$PLUGIN" -x '*.DS_Store' )

	# Ook onder een naam met het versienummer erin. Dat is wat de automatische
	# update ophaalt, en het is geen overbodige kopie:
	#
	# GitHub serveert bestanden via een CDN die een pad minutenlang vasthoudt.
	# Duwde je een nieuwe zip onder dezelfde naam, dan kreeg de site nog de
	# oude - inclusief het oude versienummer. WordPress denkt dan bijgewerkt te
	# hebben, ziet daarna weer dezelfde update staan, en je zit in een rondje
	# zonder dat er iets misgaat wat je opvalt. Dit is nagemeten: vlak na een
	# push kwam de vorige versie terug, ook met een cache-buster erachter.
	#
	# Een pad dat nog nooit bestond, kan niet uit een cache komen.
	#
	# En zo'n bestand schrijven we maar één keer. Zou je hem bij elke build
	# overschrijven, dan krijgt iemand die 1.9.0 downloadt op maandag iets
	# anders dan op dinsdag - terwijl het nummer zegt dat het hetzelfde is.
	# Wie code wijzigt, hoogt het versienummer op; dat is de bedoeling.
	if [ -e "$DIST/$PLUGIN-$VERSIE.zip" ]; then
		echo "    $PLUGIN.zip  ($PLUGIN-$VERSIE.zip bestaat al, blijft ongemoeid)"
	else
		cp "$DIST/$PLUGIN.zip" "$DIST/$PLUGIN-$VERSIE.zip"
		echo "    $PLUGIN.zip  +  $PLUGIN-$VERSIE.zip"
	fi
done

echo "==> Versiebestanden voor automatisch bijwerken"
# Deze bestanden vertellen de plugins op de site welke versie de laatste is.
# Het versienummer wordt uit de plugin zelf gelezen, zodat de twee nooit uit
# de pas kunnen lopen - dat zou stille mislukte updates opleveren.
RAW="https://raw.githubusercontent.com/marketing-chiro/pop-up/claude/chiro-fysio-exit-popup-eahq6b/dist"

schrijf_manifest() {
	local map="$1" naam="$2" bestand="$3"
	local versie
	versie="$(plugin_versie "$map")"

	if [ -z "$versie" ]; then
		echo "    FOUT: geen versie gevonden in $map" >&2
		exit 1
	fi

	cat > "$DIST/$bestand" <<JSON
{
  "name": "$naam",
  "slug": "$map",
  "version": "$versie",
  "author": "Chiro-Fysio",
  "requires": "5.5",
  "requires_php": "7.0",
  "tested": "7.1",
  "last_updated": "$(date -u '+%Y-%m-%d %H:%M:%S')",
  "download_url": "$RAW/$map-$versie.zip"
}
JSON
	echo "    $bestand (versie $versie)"
}

schrijf_manifest chiro-fysio-exit-popup "Chiro-Fysio exit-intent pop-up" update-exit-popup.json
schrijf_manifest chiro-fysio-campagnes "Chiro-Fysio e-mailcampagnes" update-campagnes.json

echo "==> Plak-snippet maken"
{
	echo "<!--"
	echo "  Chiro-Fysio exit-intent pop-up"
	echo "  Plak dit blok in de footer van de site (bijvoorbeeld via WPCode of"
	echo "  Instellingen -> Aangepaste code). Aanpassingen doe je in src/ en"
	echo "  daarna draai je ./build.sh opnieuw."
	echo "-->"
	echo "<style>"
	cat "$SRC/exit-intent-popup.css"
	echo "</style>"
	echo "<script>"
	cat "$SRC/exit-intent-popup.js"
	echo "</script>"
} > "$DIST/wpcode-snippet.html"

echo "==> Testpagina maken"
# De testpagina krijgt exact dezelfde pop-upcode als de site, maar met een
# afspraaklink die ook buiten de site werkt.
TMP_JS="$(mktemp)"
sed "s|appointmentUrl: '/uw-afspraak/'|appointmentUrl: 'https://www.chiro-fysio.nl/uw-afspraak/'|" \
	"$SRC/exit-intent-popup.js" > "$TMP_JS"

{
	echo '<!DOCTYPE html>'
	echo '<html lang="nl">'
	echo '<head>'
	echo '<meta charset="utf-8">'
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">'
	echo '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0}</style>'
	echo '</head>'
	echo '<body>'
	awk -v cssfile="$SRC/exit-intent-popup.css" -v jsfile="$TMP_JS" '
		/\/\*INJECT_CSS\*\// { while ((getline line < cssfile) > 0) print line; close(cssfile); next }
		/\/\*INJECT_JS\*\//  { while ((getline line < jsfile) > 0) print line; close(jsfile); next }
		{ print }
	' demo/testpagina.template.html
	echo '</body>'
	echo '</html>'
} > "$DIST/testpagina.html"

rm -f "$TMP_JS"

echo "==> Uitlegpagina maken"
{
	echo '<!DOCTYPE html>'
	echo '<html lang="nl">'
	echo '<head>'
	echo '<meta charset="utf-8">'
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">'
	echo '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0}</style>'
	echo '</head>'
	echo '<body>'
	cat demo/uitleg.template.html
	echo '</body>'
	echo '</html>'
} > "$DIST/uitleg.html"

echo "==> Klaar"
ls -lh "$DIST"
