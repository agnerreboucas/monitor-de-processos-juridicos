#!/bin/sh
# Monta o arquivo radar-hostinger.zip para enviar à hospedagem compartilhada.
# Precisa de: composer, zip. Uso:  sh hostinger/montar-pacote.sh
set -e
AQUI=$(cd "$(dirname "$0")" && pwd)
RAIZ=$(dirname "$AQUI")
SAIDA="${1:-$RAIZ/radar-hostinger.zip}"
TMP=$(mktemp -d)
PKG="$TMP/radar"
mkdir -p "$PKG/storage"
for item in bin migrations public src composer.json composer.lock; do
  cp -R "$RAIZ/radar-trabalhista/$item" "$PKG/"
done
touch "$PKG/storage/.gitkeep"
cp "$AQUI/htaccess-raiz" "$PKG/.htaccess"
cp "$AQUI/env-exemplo.txt" "$PKG/env-exemplo.txt"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$PKG" >/dev/null
find "$PKG/vendor" -name .git -prune -exec rm -rf {} + 2>/dev/null || true
# cinto e suspensório: cada pasta interna também se tranca sozinha
for dir in src vendor migrations bin storage; do
  printf 'Require all denied\n' > "$PKG/$dir/.htaccess"
done
rm -f "$SAIDA"
(cd "$TMP" && zip -qr "$SAIDA" radar -x '*/composer.lock' )
rm -rf "$TMP"
echo "Pacote criado: $SAIDA"
