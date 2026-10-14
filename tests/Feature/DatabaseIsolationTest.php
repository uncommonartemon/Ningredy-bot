<?php

namespace Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedEnvironment;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_an_independent_php_process_cannot_clean_this_process_fake_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('must-survive.txt', 'parent photo');
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$test = new class('diagnostic') extends \Tests\TestCase {};
$test->createApplication();
\Illuminate\Support\Facades\Storage::fake('public');
echo \Illuminate\Support\Facades\Storage::disk('public')->path('must-survive.txt');
PHP], base_path());
        $process->setTimeout(60)->mustRun();
        $this->assertNotSame(Storage::disk('public')->path('must-survive.txt'), trim($process->getOutput()));
        $this->assertSame('parent photo', Storage::disk('public')->get('must-survive.txt'));
    }

    public function test_real_connection_is_in_memory_before_database_traits_can_run(): void
    {
        IsolatedEnvironment::assertDatabase($this->app);
        $this->assertSame(':memory:', $this->app['db']->connection()->getDatabaseName());
        foreach ($this->app['db']->connection()->select('PRAGMA database_list') as $database) {
            $this->assertSame('', $database->file);
        }
    }

    public function test_guard_rejects_a_file_pdo_even_when_connection_metadata_says_memory(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ningredy-isolation-');
        try {
            $connection = new SQLiteConnection(new PDO('sqlite:'.$path), ':memory:');
            $manager = \Mockery::mock(DatabaseManager::class);
            $manager->shouldReceive('connection')->once()->andReturn($connection);
            $this->app->instance('db', $manager);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('test PDO is connected to a database file');
            IsolatedEnvironment::assertDatabase($this->app);
        } finally {
            unset($connection);
            @unlink($path);
        }
    }

    public function test_direct_testcase_boot_overrides_production_environment_before_opening_pdo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ningredy-sentinel-');
        try {
            $sentinel = new PDO('sqlite:'.$path);
            $sentinel->exec('CREATE TABLE must_survive (value TEXT)');
            $sentinel->exec("INSERT INTO must_survive VALUES ('untouched')");
            $sentinel = null;
            $checksum = hash_file('sha256', $path);
            $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
foreach (['APP_ENV' => 'production', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $argv[1]] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$test = new class('diagnostic') extends \Tests\TestCase {};
$app = $test->createApplication();
\Tests\Support\IsolatedEnvironment::assertDatabase($app);
$app['db']->connection()->statement('CREATE TABLE isolated_only (id INTEGER)');
$app['db']->connection()->getSchemaBuilder()->dropAllTables();
echo 'isolated';
PHP, $path], base_path());
            $process->setTimeout(60)->mustRun();
            $this->assertSame('isolated', trim($process->getOutput()));
            $this->assertSame($checksum, hash_file('sha256', $path));
            $sentinel = new PDO('sqlite:'.$path);
            $this->assertSame('untouched', $sentinel->query('SELECT value FROM must_survive')->fetchColumn());
        } finally {
            $sentinel = null;
            @unlink($path);
        }
    }
}
