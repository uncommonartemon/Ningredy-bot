<?php

namespace App\Console\Commands;

use App\Services\Admin\DatabaseTableEditor;
use Illuminate\Console\Command;

class OpenDatabaseTables extends Command
{
    protected $signature = 'bot:tables';

    protected $description = 'Open the database in DB Browser for SQLite (read-only while the bot runs); prints the outcome as JSON for the desktop panel.';

    public function handle(DatabaseTableEditor $editor): int
    {
        $this->line((string) json_encode($editor->open(), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
