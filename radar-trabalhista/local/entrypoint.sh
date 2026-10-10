#!/bin/sh
# Sobe o Radar: espera o banco, cria as tabelas, liga o robô (a cada 5 min) e a tela (porta 8080).
set -e
cd /app
until php bin/migrate.php >/tmp/migrate.log 2>&1; do
  echo "Aguardando o banco de dados..."; sleep 3
done
cat /tmp/migrate.log
( while true; do php bin/worker.php; sleep 300; done ) &
echo "Radar no ar em http://localhost:8080"
exec php -S 0.0.0.0:8080 -t public public/index.php
