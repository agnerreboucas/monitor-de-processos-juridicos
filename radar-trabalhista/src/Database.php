<?php
declare(strict_types=1);

namespace Radar;

use PDO;
use Radar\Support\PrefixedPdo;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = $config->get('DB_SOCKET') !== ''       // hospedagens que só aceitam conexão por socket (comum no WordPress)
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $config->get('DB_SOCKET'), $config->get('DB_NAME', 'radar'))
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->get('DB_HOST', '127.0.0.1'), $config->int('DB_PORT', 3306), $config->get('DB_NAME', 'radar'));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,   // consulta parametrizada de verdade
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'",
        ];
        $prefix = $config->get('DB_PREFIX');
        if ($prefix !== '') {      // convive com outro sistema no mesmo banco (ex.: WordPress): todas as tabelas do Radar ganham o prefixo
            if (preg_match('/^[A-Za-z0-9_]{1,40}$/', $prefix) !== 1) {
                throw new \InvalidArgumentException('DB_PREFIX inválido: use só letras, números e _.');
            }
            return new PrefixedPdo($dsn, $config->get('DB_USER', 'radar'), $config->get('DB_PASS'), $options, $prefix, PrefixedPdo::tablesFrom(__DIR__ . '/../migrations'));
        }
        return new PDO($dsn, $config->get('DB_USER', 'radar'), $config->get('DB_PASS'), $options);
    }
}
