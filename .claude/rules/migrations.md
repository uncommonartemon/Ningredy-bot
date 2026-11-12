---
paths:
  - "database/migrations/**"
  - "database/seeders/**"
---

# Migrations and seeders

Every bot runs every migration on its own database when it updates. A migration that worked on a scratch database still has to be right for a catalog that has months of real data in it.

- Never edit a migration that has reached `main`. Another machine may have run it; add a new one.
- The database is SQLite. Changing a column or a foreign key rebuilds the table; put `dropForeign` in its own `Schema::table()` call, and check the result with `select sql from sqlite_master` on an in-memory database before relying on it.
- Write data with plain queries (`DB::table()`), not models: a model's hooks and fields change later, the migration must not.
- A backfill states its condition narrowly and leaves alone what somebody stated. Example: only positions on sale whose stock nobody ever set become one item in stock.
- `down()` restores the structure. Where data cannot be told apart again, say so in a comment and leave it.
- Starter data comes from seeders that only add what is missing and never change what was edited (`StarterCatalogSeeder`). The updater runs them on every update.
- A new table or setting also goes into the chat's data tools in the same change (see `assistant-and-telegram.md`).
- A test may run a migration's `up()` by hand after dropping its table. If a later migration alters that table, the test must run it too.
