<?php

// Run BEFORE replacing code/vendor. Only load configuration, never providers,
// queues, migrations or Telegram. VACUUM INTO includes committed WAL contents.
use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;

try {
    $root = realpath($argv[1] ?? '') ?: throw new RuntimeException('Project directory not found.');
    $destination = realpath($argv[2] ?? '') ?: throw new RuntimeException('Backup directory not found.');
    require $root.'/vendor/autoload.php';

    $app = new Application($root);
    Dotenv\Dotenv::create(Env::getRepository(), $root, '.env')->load();
    $configurations = ['env' => require $root.'/config/database.php'];
    // An old config cache may still point to another SQLite file. Preserve both
    // the current database and the one migrations will use after config:clear.
    if (is_file($app->getCachedConfigPath())) {
        $cached = require $app->getCachedConfigPath();
        $configurations['cached'] = $cached['database'] ?? [];
    }

    $manifest = [];
    $seen = [];
    foreach ($configurations as $kind => $configuration) {
        $connection = $configuration['connections'][$configuration['default'] ?? ''] ?? null;
        if (! is_array($connection)) {
            throw new RuntimeException('Database configuration not found.');
        }
        $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        if (($connection['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException('Automatic backup supports SQLite only. A database-specific backup is required before updating MySQL/PostgreSQL.');
        }
        $configured = $connection['database'] ?? '';
        if (! is_string($configured) || $configured === '' || $configured === ':memory:') {
            throw new RuntimeException('A persistent SQLite database is required.');
        }
        $source = realpath($configured) ?: realpath($root.'/'.$configured);
        if (! $source || ! is_file($source)) {
            throw new RuntimeException('Configured SQLite database not found. Update stopped to avoid creating an empty database.');
        }
        if (isset($seen[$source])) {
            continue;
        }
        $seen[$source] = true;
        $filename = $kind === 'env' ? 'database.sqlite' : 'database-cached.sqlite';
        $target = $destination.'/'.$filename;
        if (file_exists($target)) {
            throw new RuntimeException('Backup file already exists; it will not be overwritten.');
        }

        $database = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA busy_timeout = 5000');
        $database->exec('VACUUM INTO '.$database->quote($target));
        $database = null;
        $snapshot = new PDO('sqlite:'.$target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($snapshot->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('SQLite backup integrity check failed.');
        }
        $snapshot = null;
        $manifest[] = ['configuration' => $kind, 'source' => $source, 'backup' => $filename, 'sha256' => hash_file('sha256', $target)];
    }
    if (file_put_contents($destination.'/databases.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot write the backup manifest.');
    }
    fwrite(STDOUT, 'SQLite backup verified: '.count($manifest)." file(s).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database backup failed: '.$exception->getMessage()."\n");
    exit(1);
}
