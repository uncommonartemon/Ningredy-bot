<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class IsolatedEnvironment
{
    private static ?string $processToken = null;

    public static function prepare(): void
    {
        // Independent PHPUnit processes must not clean each other's fake
        // disks. Laravel Storage::fake uses this token for its own root,
        // regardless of the configured local disk roots below.
        $_SERVER['TEST_TOKEN'] = self::$processToken ??= getmypid().'-'.bin2hex(random_bytes(6));

        // Must run BEFORE providers boot: they read settings and resolve PDO.
        // Changing config afterwards does not replace an already opened PDO.
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null',
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/ningredy-test-config-'.bin2hex(random_bytes(12)).'.php',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public static function assertDatabase(Application $app): void
    {
        $connection = $app['db']->connection();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Tests must use isolated in-memory SQLite. Refusing database operations.');
        }

        // Check the real PDO, not config. Also reject attached file databases.
        foreach ($connection->select('PRAGMA database_list') as $database) {
            if (($database->file ?? '') !== '') {
                throw new RuntimeException('A test PDO is connected to a database file. Refusing database operations.');
            }
        }
    }
}
