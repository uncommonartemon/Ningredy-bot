<?php

namespace Tests\Unit;

use App\Services\Admin\SqliteDataOnlyGuard;
use PHPUnit\Framework\TestCase;
use SQLite3;

class SqliteDataOnlyGuardTest extends TestCase
{
    private SQLite3 $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new SQLite3(':memory:');
        $this->database->enableExceptions(true);
        $this->database->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, title TEXT); INSERT INTO products VALUES (1, \'Old\');'
            .'CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT); INSERT INTO migrations VALUES (1, \'create_products\');');
        (new SqliteDataOnlyGuard)->install($this->database);
    }

    public function test_rows_can_be_read_added_changed_and_deleted(): void
    {
        $this->database->exec("UPDATE products SET title = 'New' WHERE id = 1");
        $this->database->exec("INSERT INTO products (title) VALUES ('Second')");
        $this->database->exec('DELETE FROM products WHERE id = 2');

        $this->assertSame('New', $this->database->querySingle('SELECT title FROM products WHERE id = 1'));
        $this->assertSame('title', $this->database->query("PRAGMA table_info('products')")->fetchArray(SQLITE3_ASSOC)['name'] === 'id'
            ? 'title' : 'unexpected');
        $this->assertNotFalse($this->database->querySingle('PRAGMA user_version'));
    }

    public function test_the_structure_and_the_migration_record_cannot_be_changed(): void
    {
        foreach ([
            'CREATE TABLE extra (id INTEGER)',
            'ALTER TABLE products ADD COLUMN price REAL',
            'ALTER TABLE products RENAME TO goods',
            'DROP TABLE products',
            'CREATE INDEX products_title ON products (title)',
            'CREATE VIEW titles AS SELECT title FROM products',
            'CREATE TRIGGER t AFTER INSERT ON products BEGIN SELECT 1; END',
            'PRAGMA writable_schema = 1',
            'PRAGMA foreign_keys = 0',
            "ATTACH ':memory:' AS other",
            "UPDATE migrations SET migration = 'x'",
            'DELETE FROM migrations',
        ] as $statement) {
            try {
                $this->database->exec($statement);
                $this->fail('Allowed: '.$statement);
            } catch (\Exception $error) {
                $this->assertStringContainsString('not authorized', $error->getMessage(), $statement);
            }
        }
        $this->assertSame(1, $this->database->querySingle('SELECT COUNT(*) FROM migrations'));
        $this->assertSame(2, $this->database->querySingle("SELECT COUNT(*) FROM pragma_table_info('products')"));
    }
}
