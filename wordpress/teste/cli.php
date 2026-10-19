<?php
// Uso: php cli.php owner email@exemplo.com  (torna a conta administradora do Radar) | php cli.php cron (deixa o robô vencido para o WP-Cron)
$_SERVER['HTTP_HOST'] = '127.0.0.1:9201'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
require getenv('WPDIR') . '/wp-load.php';
if (($argv[1] ?? '') === 'cron') { wp_clear_scheduled_hook('radar_wp_tick'); wp_schedule_event(time() - 5, 'radar_5min', 'radar_wp_tick'); echo "robô agendado para agora\n"; }
if (($argv[1] ?? '') === 'owner') { echo Radar_WP_Plugin::makeOwner($argv[2]) ? "dono ok\n" : "falhou\n"; }
