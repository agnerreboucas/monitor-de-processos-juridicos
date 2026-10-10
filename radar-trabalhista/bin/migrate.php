<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Radar\Config;
use Radar\Database;
use Radar\Migrator;

$config = Config::fromEnvironment(__DIR__ . '/../.env');
$applied = (new Migrator(Database::connect($config), __DIR__ . '/../migrations'))->migrate();
echo $applied === [] ? "Banco já está atualizado.\n" : 'Aplicadas: ' . implode(', ', $applied) . "\n";
