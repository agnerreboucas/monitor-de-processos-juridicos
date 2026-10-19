#!/bin/sh
# Teste de integração: instala um WordPress de verdade (via Composer), ativa o tema e o plugin, e percorre
# cadastro, painel, API por chave, robô (WP-Cron) e e-mails. Precisa de: php 8.2+, composer, zip, mysql/mariadb (usuário root local).
# Uso:  sh wordpress/teste/rodar.sh [pasta-de-trabalho]
set -e
AQUI=$(cd "$(dirname "$0")" && pwd)
RAIZ=$(dirname "$(dirname "$AQUI")")
W="${1:-/tmp/radar-wp-teste}"
PORTA=9201
export SITE="http://127.0.0.1:$PORTA"
export WPDIR="$W/site"
mkdir -p "$W"
[ -d "$W/vendor/johnpbloch/wordpress-core" ] || {
  printf '{"require":{"johnpbloch/wordpress-core":"^6.8"},"config":{"allow-plugins":{"johnpbloch/wordpress-core-installer":true}}}' > "$W/composer.json"
  (cd "$W" && composer install --no-interaction --no-progress >/dev/null 2>&1)
}
rm -rf "$WPDIR" && cp -R "$W/vendor/johnpbloch/wordpress-core" "$WPDIR"
mysql -e "DROP DATABASE IF EXISTS wptest; CREATE DATABASE wptest CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'wpu'@'localhost' IDENTIFIED BY 'wpu_pass'; GRANT ALL ON wptest.* TO 'wpu'@'localhost'; FLUSH PRIVILEGES;"
cat > "$WPDIR/wp-config.php" <<PHP
<?php
define('DB_NAME','wptest'); define('DB_USER','wpu'); define('DB_PASSWORD','wpu_pass'); define('DB_HOST','localhost'); define('DB_CHARSET','utf8mb4'); define('DB_COLLATE','');
define('AUTH_KEY','a1');define('SECURE_AUTH_KEY','a2');define('LOGGED_IN_KEY','a3');define('NONCE_KEY','a4');define('AUTH_SALT','a5');define('SECURE_AUTH_SALT','a6');define('LOGGED_IN_SALT','a7');define('NONCE_SALT','a8');
\$table_prefix = 'wp_';
define('WP_DEBUG', true); define('WP_DEBUG_LOG', '$W/debug.log'); define('WP_DEBUG_DISPLAY', false);
define('WP_HOME','$SITE'); define('WP_SITEURL','$SITE'); define('DISABLE_WP_CRON', true);
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
PHP
sh "$RAIZ/wordpress/montar-wordpress.sh" "$W" >/dev/null
mkdir -p "$WPDIR/wp-content/mu-plugins" "$WPDIR/wp-content/plugins" "$WPDIR/wp-content/themes"
(cd "$WPDIR/wp-content/plugins" && unzip -qo "$W/radar-processual-plugin.zip")
(cd "$WPDIR/wp-content/themes" && unzip -qo "$W/radar-processual-tema.zip")
cat > "$WPDIR/wp-content/mu-plugins/mailcatch.php" <<PHP
<?php
add_filter('pre_wp_mail', function (\$n, \$a) { file_put_contents('$W/mail.log', json_encode(\$a, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND); return true; }, 10, 2);
PHP
cp "$AQUI/cli.php" "$W/cli.php"
php "$AQUI/instalar.php" >/dev/null 2>&1
php "$AQUI/ativar.php"
php -S "127.0.0.1:$PORTA" -t "$WPDIR" "$AQUI/router.php" >"$W/servidor.log" 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null' EXIT
sleep 2
python3 "$AQUI/e2e.py"
php "$W/cli.php" cron >/dev/null
curl -s -o /dev/null --max-time 90 "$SITE/wp-cron.php?doing_wp_cron"         # o robô, pelo WP-Cron
mysql wptest -N -e "SELECT summary FROM wp_radar_worker_runs ORDER BY id DESC LIMIT 1" | grep -q checked && echo "OK   robô rodou pelo WP-Cron" || { echo "FALHA robô não rodou"; exit 1; }
grep -q "Bem-vindo ao Radar Processual" "$W/mail.log" && echo "OK   e-mail de boas-vindas saiu pelo wp_mail" || { echo "FALHA e-mail"; exit 1; }
echo "Integração concluída."
