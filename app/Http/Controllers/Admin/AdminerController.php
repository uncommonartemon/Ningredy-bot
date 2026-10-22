<?php

namespace App\Http\Controllers\Admin;

/**
 * The table editor: Adminer (resources/adminer), signed in to the bot's own
 * SQLite database only, where rows may change but the structure may not
 * (App\Services\Admin\SqliteDataOnlyGuard). Reached only through
 * App\Http\Middleware\AdminerAccess.
 */
final class AdminerController
{
    public function __invoke(): never
    {
        $database = (string) config('database.connections.sqlite.database');

        // Adminer reads the request from the superglobals. Whatever arrives, it
        // can reach only this database file, through the SQLite3 driver the guard
        // attaches to: no other driver, server, file or PHP extension.
        foreach (['server', 'pgsql', 'oracle', 'mssql', 'mongo', 'elastic', 'ext'] as $key) {
            unset($_GET[$key]);
        }
        $_GET['sqlite'] = '';
        if (array_key_exists('username', $_GET)) {
            $_GET['username'] = '';
            $_GET['db'] = $database;
        }
        if (isset($_POST['auth']) || ! array_key_exists('username', $_GET)) {
            // The first visit signs in here instead of Adminer's own login form.
            $_POST['auth'] = ['driver' => 'sqlite', 'server' => '', 'username' => '', 'password' => 'local', 'db' => $database];
        }

        require resource_path('adminer/ningredy.php');
        require resource_path('adminer/adminer-6.1.1-en.php');

        exit;
    }
}
