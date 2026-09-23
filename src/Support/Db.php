<?php

declare(strict_types=1);

namespace Hvm\Support;

use PDO;

/**
 * PDO-Fabrik. Ausschließlich echte Prepared Statements (EMULATE_PREPARES false), utf8mb4, Exceptions.
 */
final class Db
{
    /**
     * @param array{host?: string|null, port?: int|string|null, name?: string|null, user?: string|null, password?: string|null, socket?: string|null} $settings
     */
    public static function connect(array $settings): PDO
    {
        $name = (string) ($settings['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Datenbankname fehlt (DB_NAME).');
        }

        $socket = (string) ($settings['socket'] ?? '');
        if ($socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($settings['host'] ?? '127.0.0.1'),
                (int) ($settings['port'] ?? 3306),
                $name
            );
        }

        $pdo = new PDO($dsn, (string) ($settings['user'] ?? ''), (string) ($settings['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $pdo;
    }

    public static function fromConfig(Config $config, string $connection = 'db'): PDO
    {
        /** @var array{host?: string|null, port?: int|string|null, name?: string|null, user?: string|null, password?: string|null, socket?: string|null} $settings */
        $settings = $config->array('app.' . $connection);

        return self::connect($settings);
    }
}
