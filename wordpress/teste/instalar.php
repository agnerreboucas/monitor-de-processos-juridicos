<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:9201'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = '127.0.0.1';
define('WP_INSTALLING', true);
require getenv('WPDIR') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install('Site de teste', 'admin', 'admin@teste.com', true, '', 'senha-admin-123', 'pt_BR');
