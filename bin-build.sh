#!/usr/bin/env bash
# Създава release zip на плъгина (без dev файлове). Употреба: bash bin-build.sh [изходна_папка]
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-$DIR/../dist}"
VER=$(grep -m1 "Version:" "$DIR/kanelov-shipping.php" | sed 's/.*Version: *//' | tr -d ' \r')
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"; TMP=$(mktemp -d); mkdir -p "$TMP/kanelov-shipping"
( cd "$DIR" && tar --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./phpunit.xml --exclude=./composer.lock --exclude=./.gitignore --exclude='./.phpunit*' --exclude=./bin-build.sh -cf - . ) | ( cd "$TMP/kanelov-shipping" && tar -xf - )
( cd "$TMP" && zip -qr "$OUT/kanelov-shipping-$VER.zip" kanelov-shipping )
rm -rf "$TMP"; echo "$OUT/kanelov-shipping-$VER.zip"
