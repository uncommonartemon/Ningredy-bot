---
name: update-bot
description: Put a version of the code into the operator's bot folder so it shows on their own site - state check, backup, code, migrations, build, queue reload, a look at the result, rollback. Use it whenever the operator asks to update the bot, to put a change on their site or on the bot's port, to see a new feature in their own admin, or says that they do not see a change that is already on GitHub.
argument-hint: "[branch or main]"
---

# Update the bot folder

The operator judges work by their own site. A change that is only on GitHub does not exist for them yet. This skill is how it gets to their site.

## Whose folder this is

- The bot folder is the main worktree of this repository: the first line of `git worktree list`. Agent work happens in a linked worktree next to it.
- The folder, its database and its running processes are the operator's. Touch them only on the operator's direct word in the current conversation ("update the bot", "put it on my site", "do it yourself"). A question about why something is not visible is not that word; answer it and ask.
- The harness may refuse to change the bot folder even then. Do not work around a refusal. Give the operator the commands of section 3 as one line to paste, and say why you could not run them.
- Never start or stop the bot or its server. `php artisan queue:restart` only reloads the queue workers with the new code and is fine.

## 1. Look before touching

```bash
php ${CLAUDE_SKILL_DIR}/scripts/bot-state.php
```

It finds the bot folder by itself, reads only, and prints: the version, whether a request is in work, the queue, pending migrations. Do not update while a request is in work; a search would be cut.

Say in one sentence what will happen and how long it takes (two to seven minutes).

## 2. Back up

Use the project's own snapshot script, into a folder next to the bot folder, never into a temporary folder: the database holds settings.

```powershell
$bot = (git worktree list --porcelain | Select-Object -First 1) -replace '^worktree ', ''
$backup = "$bot-backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
New-Item -ItemType Directory -Path $backup | Out-Null
php "$bot\scripts\backup-before-update.php" $bot $backup
git -C $bot rev-parse HEAD | Set-Content "$backup\git-commit-before.txt" -Encoding ASCII
```

The script prints that the snapshot was verified. Stop if it does not.

## 3. Put the version in

**The version is in `main`.** The project's updater does everything, backup included: the operator presses the update button in the program window or runs `update.bat`. It refuses a folder that is not on `main`; if the folder was left on a branch tip, `git -C <bot> checkout main` first.

**The version is a branch that is not merged yet.** Run in the bot folder, in this order:

```bash
git checkout --detach <branch>
composer install --no-interaction --prefer-dist --no-scripts
php artisan migrate --force
php artisan db:seed --class="Database\\Seeders\\StarterCatalogSeeder" --force
php artisan optimize:clear
npm run build
php artisan queue:restart
```

- Migrate right after the checkout: new code on an old schema fails on the first page.
- `npm run build` is needed when anything under `resources/` changed; it is safe to run always. Do not run `npm ci` while the bot runs: it replaces the modules the program window runs from.
- The seeder only adds missing starter data.

## 4. Check the result

1. `php ${CLAUDE_SKILL_DIR}/scripts/bot-state.php` again: the new version, no pending migrations.
2. The site answers: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/admin/login`. If nobody listens on the port, the bot is stopped; say so and ask the operator to start it. Do not start it.
3. Look at the page that changed with the `look-at-pages` skill, on their site, reading only.

## 5. Tell the operator

- What to refresh and what they will see there, in the words on the screen.
- That the program window has to be closed and opened again to pick up changes of the window itself.
- Where the backup is.

## Rollback

`git -C <bot> checkout --detach <commit from git-commit-before.txt>`, then `php artisan optimize:clear` and `php artisan queue:restart`. Tables a migration added can stay; older code does not read them. Putting the database snapshot back is the operator's decision and needs the bot stopped by them.
