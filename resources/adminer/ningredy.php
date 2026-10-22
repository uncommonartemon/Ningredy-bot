<?php

// Adminer's customization hook (it looks for a global adminer_object()).
// Loaded only by App\Http\Controllers\Admin\AdminerController, behind
// App\Http\Middleware\AdminerAccess.

use App\Services\Admin\SqliteDataOnlyGuard;

function adminer_object(): object
{
    return new class extends Adminer\Adminer
    {
        public function name()
        {
            return 'Ningredy';
        }

        public function credentials()
        {
            return ['', '', ''];
        }

        // AdminerAccess has already let in only the panel's admin on this computer.
        public function login($login, $password)
        {
            return true;
        }

        public function verifyLoginToken()
        {
            return false;
        }

        public function databases($flush = true)
        {
            return [(string) config('database.connections.sqlite.database')];
        }

        public function afterConnect()
        {
            $link = (new ReflectionProperty(Adminer\SqliteDb::class, 'link'))->getValue(Adminer\Db::$instance);
            if (! $link instanceof SQLite3) {
                throw new RuntimeException('The table editor opens only through SQLite3, where its structure guard applies.');
            }
            // Adminer waits half a second for a lock; the bot's own writes can take longer.
            $link->busyTimeout(5000);
            app(SqliteDataOnlyGuard::class)->install($link);
        }
    };
}
