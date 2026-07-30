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

echo "==> Plugin-zip maken"
mkdir -p "$DIST"
rm -f "$DIST/chiro-fysio-exit-popup.zip"
( cd wordpress && zip -qr "../$DIST/chiro-fysio-exit-popup.zip" chiro-fysio-exit-popup -x '*.DS_Store' )

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
