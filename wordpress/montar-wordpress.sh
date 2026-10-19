#!/bin/sh
# Monta os dois pacotes para o WordPress: radar-processual-plugin.zip e radar-processual-tema.zip.
# Precisa de: zip. Uso:  sh wordpress/montar-wordpress.sh [pasta-de-saida]
set -e
AQUI=$(cd "$(dirname "$0")" && pwd)
RAIZ=$(dirname "$AQUI")
SAIDA="${1:-$RAIZ}"
TMP=$(mktemp -d)
NUCLEO="$RAIZ/radar-trabalhista"

# plugin = invólucro do WordPress + núcleo do Radar (src, migrations e telas)
cp -R "$AQUI/radar-processual" "$TMP/radar-processual"
mkdir -p "$TMP/radar-processual/radar/app"
cp -R "$NUCLEO/src" "$NUCLEO/migrations" "$TMP/radar-processual/radar/"
for f in index.html app.css app.js analise.js termos.html privacidade.html api-docs.html legal.css; do
  [ -f "$NUCLEO/public/app/$f" ] && cp "$NUCLEO/public/app/$f" "$TMP/radar-processual/radar/app/$f"
done
for dir in radar radar/src radar/migrations includes; do
  printf '<?php\n// Silence is golden.\n' > "$TMP/radar-processual/$dir/index.php"
done
for dir in radar/src radar/migrations; do
  printf 'Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n' > "$TMP/radar-processual/$dir/.htaccess"
done
(cd "$TMP" && rm -f "$SAIDA/radar-processual-plugin.zip" && zip -qr "$SAIDA/radar-processual-plugin.zip" radar-processual)

# tema
cp -R "$AQUI/radar-processual-tema" "$TMP/radar-processual-tema"
(cd "$TMP" && rm -f "$SAIDA/radar-processual-tema.zip" && zip -qr "$SAIDA/radar-processual-tema.zip" radar-processual-tema)
rm -rf "$TMP"
echo "Pacotes criados em $SAIDA: radar-processual-plugin.zip e radar-processual-tema.zip"
