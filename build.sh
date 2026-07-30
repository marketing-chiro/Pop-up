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

echo "==> Klaar"
ls -lh "$DIST"
