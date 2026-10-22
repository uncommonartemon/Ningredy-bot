<?php

namespace App\Services\Admin;

use SQLite3;

/**
 * An SQLite authorizer for the table editor: rows may be read, added, changed
 * and deleted, but nothing about the structure - tables, columns, indexes,
 * triggers, views - and no setting that would get around that.
 *
 * The structure belongs to the Laravel migrations; a column added by hand
 * would break the next migration or the code that reads the table. Enforced
 * by SQLite itself, so neither a form nor a typed SQL command gets past it.
 */
final class SqliteDataOnlyGuard
{
    /** Pragmas whose argument names a table or index to look at, not a value to set. */
    private const INSPECTING_PRAGMAS = ['table_info', 'table_xinfo', 'table_list', 'index_list', 'index_info',
        'index_xinfo', 'foreign_key_list', 'foreign_key_check', 'integrity_check', 'quick_check'];

    /** Laravel's own record of which migrations ran: changing it is changing the structure. */
    private const BOOKKEEPING_TABLES = ['migrations', 'sqlite_master', 'sqlite_schema', 'sqlite_sequence'];

    public function install(SQLite3 $database): void
    {
        $database->setAuthorizer($this);
    }

    public function __invoke(int $action, ?string $first = null, ?string $second = null): int
    {
        return match ($action) {
            SQLite3::SELECT, SQLite3::READ, SQLite3::TRANSACTION, SQLite3::SAVEPOINT,
            SQLite3::FUNCTION, SQLite3::RECURSIVE => SQLite3::OK,
            SQLite3::INSERT, SQLite3::UPDATE, SQLite3::DELETE => in_array(strtolower((string) $first), self::BOOKKEEPING_TABLES, true)
                ? SQLite3::DENY : SQLite3::OK,
            SQLite3::PRAGMA => $second === null || in_array(strtolower((string) $first), self::INSPECTING_PRAGMAS, true)
                ? SQLite3::OK : SQLite3::DENY,
            default => SQLite3::DENY,
        };
    }
}
