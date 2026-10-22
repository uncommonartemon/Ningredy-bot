<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Process;

/**
 * Opens the bot's database in DB Browser for SQLite, a spreadsheet-like
 * editor on this computer - from the desktop panel and from the admin.
 *
 * That editor holds the database locked from the first edit until "Write
 * Changes", which a running bot would meet as "database is locked", so while
 * any queue worker runs it opens the database read-only.
 */
final class DatabaseTableEditor
{
    public const PACKAGE = 'DBBrowserForSQLite.DBBrowserForSQLite';

    public function executable(): ?string
    {
        foreach (['ProgramFiles', 'ProgramW6432', 'LOCALAPPDATA'] as $variable) {
            $base = getenv($variable);
            $file = $base ? $base.'\\DB Browser for SQLite\\DB Browser for SQLite.exe' : null;
            if ($file && is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    public function botIsRunning(): bool
    {
        $count = Process::timeout(20)->run(['powershell.exe', '-NoProfile', '-Command',
            "@(Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { \$_.CommandLine -match 'artisan\\s+queue:work' }).Count",
        ])->output();

        return (int) trim($count) > 0;
    }

    /** @return array{installed: bool, opened: bool, read_only: bool, message: string} */
    public function open(): array
    {
        $editor = $this->executable();
        if ($editor === null) {
            return ['installed' => false, 'opened' => false, 'read_only' => false,
                'message' => 'DB Browser for SQLite не установлен. Установите его кнопкой «Таблицы» в панели или командой: winget install --exact --id '.self::PACKAGE];
        }
        $database = (string) config('database.connections.sqlite.database');
        if (! is_file($database)) {
            return ['installed' => true, 'opened' => false, 'read_only' => false, 'message' => 'Файл базы данных не найден.'];
        }
        $readOnly = $this->botIsRunning();
        // start returns at once and leaves the editor running on its own.
        Process::run(['cmd.exe', '/c', 'start', '', $editor, ...($readOnly ? ['-R'] : []), $database]);

        return ['installed' => true, 'opened' => true, 'read_only' => $readOnly, 'message' => $readOnly
            ? 'Таблицы открыты только для просмотра: бот работает. Чтобы править, остановите бота.'
            : 'Таблицы открыты для правки. Изменения сохраняются кнопкой «Записать изменения».'];
    }
}
