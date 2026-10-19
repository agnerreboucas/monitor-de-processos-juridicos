<?php
// Roteador de teste: o que existe de verdade é servido como arquivo; o resto vai para o WordPress (como o .htaccess faz no Apache).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = getenv('WPDIR') . $path;
if ($path !== '/' && is_file($file)) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = getenv('WPDIR') . '/index.php';
chdir(getenv('WPDIR'));
require getenv('WPDIR') . '/index.php';
