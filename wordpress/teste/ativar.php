<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:9201'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
require getenv('WPDIR') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
update_option('permalink_structure', '/%postname%/');
switch_theme('radar-processual-tema');
$r = activate_plugin('radar-processual/radar-processual.php');
if (is_wp_error($r)) { fwrite(STDERR, 'ERRO ao ativar: ' . $r->get_error_message() . "\n"); exit(1); }
echo "tema e plugin ativos\n";
