import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, copyFileSync, writeFileSync, readFileSync, symlinkSync, existsSync, readdirSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Real local Git remotes and real SQLite backups; dependency installers and
// launcher are substitutes. No network, real bot, migrations or paid AI calls.
const project = fileURLToPath(new URL('../..', import.meta.url));
const windows = process.platform === 'win32';
const php = windows ? execFileSync('powershell.exe', ['-NoProfile', '-Command', '(Get-Command php -CommandType Application | Select-Object -First 1).Source'], { encoding: 'utf8' }).trim() : 'php';
const git = windows ? execFileSync('powershell.exe', ['-NoProfile', '-Command', '(Get-Command git -CommandType Application | Select-Object -First 1).Source'], { encoding: 'utf8' }).trim() : 'git';
const artifactRoot = join(project, 'output', 'update-tests');
mkdirSync(artifactRoot, { recursive: true });
const psQuote = (value) => `'${value.replaceAll("'", "''")}'`;
const environment = { ...process.env, GIT_CONFIG_GLOBAL: 'NUL', GIT_CONFIG_NOSYSTEM: '1' };
for (const key of Object.keys(environment)) {
    if (/^(DB_|APP_(ENV|CONFIG_CACHE)|OPENAI_|TELEGRAM_)/.test(key)) delete environment[key];
}

function runGit(cwd, ...args) {
    return execFileSync(git, args, { cwd, env: environment, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function fixture(mode) {
    const work = mkdtempSync(join(artifactRoot, 'case-'));
    const root = join(work, "old copy Юзер's");
    const remote = join(work, 'remote.git');
    const upstream = join(work, 'upstream');
    mkdirSync(root);
    for (const folder of ['scripts', 'config', 'database/seeders', 'bootstrap/cache', 'storage/app/public', 'public']) {
        mkdirSync(join(root, folder), { recursive: true });
    }
    const updaterFiles = ['scripts/update-and-start.ps1', 'scripts/backup-before-update.php', 'scripts/backup-update-code.mjs'];
    for (const file of [...updaterFiles, 'config/database.php']) {
        copyFileSync(join(project, file), join(root, file));
    }
    symlinkSync(join(project, 'vendor'), join(root, 'vendor'), 'junction');
    writeFileSync(join(root, 'artisan'), '<?php // fixture only');
    for (const seeder of ['ProductSourceDomainHintSeeder', 'BrandCatalogSeeder']) writeFileSync(join(root, `database/seeders/${seeder}.php`), '<?php // fixture only');
    writeFileSync(join(root, 'composer.lock'), '{}');
    writeFileSync(join(root, 'package.json'), '{"private":true}\n');
    writeFileSync(join(root, 'package-lock.json'), '{}');
    writeFileSync(join(root, '.gitignore'), '.env\nvendor/\ndatabase/*.sqlite*\nstorage/\npublic/\nbootstrap/cache/\n');
    writeFileSync(join(root, 'version.txt'), 'old');
    writeFileSync(join(root, 'delete-me.txt'), 'recover deleted file');
    writeFileSync(join(root, '.env'), 'APP_KEY=fixture-preserve-me\nDB_CONNECTION=sqlite\n');
    writeFileSync(join(root, 'storage/app/public/photo.txt'), 'original-photo');
    writeFileSync(join(root, 'public/hot'), 'http://localhost:5173');
    // Fail if migration starts while the old config/package cache survives.
    writeFileSync(join(root, 'bootstrap/cache/config.php'), "<?php return ['database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/../../database/database.sqlite']]]];");
    writeFileSync(join(root, 'bootstrap/cache/services.php'), '<?php return [];');
    writeFileSync(join(root, 'scripts/start-ningredy.ps1'), "Add-Content -LiteralPath $env:UPDATE_TEST_TRACE -Value 'START'\nexit 0\n");
    const seed = join(work, 'seed.php');
    writeFileSync(seed, '<?php $db = new PDO("sqlite:".$argv[1]); $db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, title TEXT); INSERT INTO products VALUES (1, \'Original laptop\')");');
    execFileSync(php, [seed, join(root, 'database/database.sqlite')], { env: environment });

    runGit(root, 'init', '-b', 'main');
    runGit(root, 'config', 'user.name', 'Updater test');
    runGit(root, 'config', 'user.email', 'fixture@example.invalid');
    runGit(root, 'add', '.');
    runGit(root, 'commit', '-m', 'old installed version');
    runGit(work, 'init', '--bare', remote);
    runGit(root, 'remote', 'add', 'origin', remote);
    runGit(root, 'push', '-u', 'origin', 'main');
    runGit(work, 'clone', '-b', 'main', remote, upstream);
    runGit(upstream, 'config', 'user.name', 'Updater test');
    runGit(upstream, 'config', 'user.email', 'fixture@example.invalid');
    writeFileSync(join(upstream, 'version.txt'), 'new');
    runGit(upstream, 'add', 'version.txt');
    runGit(upstream, 'commit', '-m', 'new version');
    runGit(upstream, 'push');

    const trace = join(work, 'trace.txt');
    const stub = join(work, 'tool.cjs');
    writeFileSync(stub, `
const {appendFileSync, existsSync, mkdirSync} = require('node:fs');
const {spawnSync} = require('node:child_process');
const [tool, ...args] = process.argv.slice(2);
appendFileSync(process.env.UPDATE_TEST_TRACE, tool + ' ' + args.join(' ') + '\\n');
if (tool === 'php' && (args[0] === '-r' || args[0].endsWith('backup-before-update.php'))) {
    if (process.env.UPDATE_TEST_MODE.endsWith('backup-fails') && args[0].endsWith('.php')) process.exit(9);
    const child = spawnSync(process.env.UPDATE_TEST_PHP, args, {stdio: 'inherit'});
    process.exit(child.status ?? 1);
}
if (tool === 'php' && args[1] === 'migrate') {
    if (existsSync('bootstrap/cache/config.php') || existsSync('bootstrap/cache/services.php')) process.exit(17);
    if (process.env.UPDATE_TEST_MODE === 'migration-fails') process.exit(8);
}
if (tool === 'php' && args[1] === 'storage:link') mkdirSync('public/storage', {recursive:true});
if (tool === 'composer' && process.env.UPDATE_TEST_MODE.endsWith('composer-fails')) process.exit(7);
if (tool === 'npm' && args[0] === 'run' && process.env.UPDATE_TEST_MODE === 'build-fails') process.exit(6);
process.exit(0);
`);
    for (const command of ['php', 'composer', 'npm', 'ngrok']) {
        writeFileSync(join(work, command + '.cmd'), `@echo off\r\n"${process.execPath}" "${stub}" ${command} %*\r\nexit /b %errorlevel%\r\n`);
    }
    // The force batch downloads its updater outside the old checkout. Exercise
    // that same ProjectRoot path without contacting GitHub or starting the bot.
    let updater = join(root, 'scripts/update-and-start.ps1');
    if (mode === 'force-success') {
        const bootstrap = join(work, 'downloaded updater');
        const archive = join(work, 'updater.zip');
        runGit(upstream, 'archive', '--format=zip', '--output', archive, 'HEAD', ...updaterFiles);
        execFileSync('powershell.exe', ['-NoProfile', '-Command', `Expand-Archive -LiteralPath ${psQuote(archive)} -DestinationPath ${psQuote(bootstrap)} -ErrorAction Stop`], {
            env: environment, stdio: ['ignore', 'pipe', 'pipe'],
        });
        updater = join(bootstrap, 'scripts/update-and-start.ps1');
    }
    const harness = join(work, 'harness.ps1');
    writeFileSync(harness, '\ufeff' + `
function Get-CimInstance {
    [CmdletBinding()] param($ClassName)
    if ($env:UPDATE_TEST_MODE -in @('running', 'force-running')) {
        [PSCustomObject]@{ ProcessId = 12345; Name = 'php.exe'; CommandLine = 'php artisan queue:work --queue=media' }
    }
}
function Get-Command {
    [CmdletBinding()] param([string]$Name, $CommandType)
    $toolName = $Name -replace '\\.(exe|cmd|bat)$', ''
    switch ($toolName) {
        'git' { [PSCustomObject]@{Source = ${psQuote(git)}} }
        'node' { [PSCustomObject]@{Source = ${psQuote(process.execPath)}} }
        default { [PSCustomObject]@{Source = (Join-Path ${psQuote(work)} ($toolName + '.cmd'))} }
    }
}
& ${psQuote(updater)} -NoPause${mode.startsWith('force-') ? ' -ForceUpdate' : ''}${mode === 'force-success' ? ` -ProjectRoot ${psQuote(root)}` : ''}
exit $LASTEXITCODE
`);
    if (mode === 'dirty') writeFileSync(join(root, 'version.txt'), 'local important edit');
    if (mode === 'untracked') writeFileSync(join(root, 'personal.txt'), 'do not delete');
    if (mode === 'diverged') {
        writeFileSync(join(root, 'local.txt'), 'local commit');
        runGit(root, 'add', 'local.txt');
        runGit(root, 'commit', '-m', 'local work');
    }
    if (mode === 'offline') runGit(root, 'remote', 'set-url', 'origin', join(work, 'missing.git'));
    let localCommit;
    const staged = 'local staged title\n';
    const unstaged = 'local unstaged title\nčeský název\n';
    const localPackage = '{"private":true,"local":"keep my edits"}\n';
    const unicodeFile = 'český výběr Юзер.txt';
    const binary = Buffer.from([0, 255, 13, 10, 195, 169]);
    if (mode.startsWith('force-')) {
        writeFileSync(join(root, 'local.txt'), 'local committed work');
        runGit(root, 'add', 'local.txt');
        runGit(root, 'commit', '-m', 'local work to preserve');
        localCommit = runGit(root, 'rev-parse', 'HEAD');
        writeFileSync(join(root, 'version.txt'), staged);
        runGit(root, 'add', 'version.txt');
        writeFileSync(join(root, 'version.txt'), unstaged);
        writeFileSync(join(root, 'package.json'), localPackage);
        writeFileSync(join(root, 'incoming.txt'), 'local untracked collision');
        writeFileSync(join(root, 'personal.txt'), 'untracked without collision');
        writeFileSync(join(root, unicodeFile), binary);
        unlinkSync(join(root, 'delete-me.txt'));
        writeFileSync(join(upstream, 'incoming.txt'), 'remote tracked version');
        runGit(upstream, 'add', 'incoming.txt');
        if (mode === 'force-ignored-target') {
            writeFileSync(join(upstream, '.env'), 'APP_KEY=remote-must-never-replace-local\n');
            runGit(upstream, 'add', '--force', '.env');
        }
        runGit(upstream, 'commit', '-m', 'remote files for force update');
        runGit(upstream, 'push');
    }
    const target = runGit(upstream, 'rev-parse', 'HEAD');
    return { work, root, trace, harness, target, localCommit, staged, unstaged, localPackage, unicodeFile, binary,
        databaseBytes: readFileSync(join(root, 'database/database.sqlite')),
        env: { ...environment, UPDATE_TEST_TRACE: trace, UPDATE_TEST_MODE: mode, UPDATE_TEST_PHP: php } };
}

for (const mode of ['success', 'running', 'dirty', 'untracked', 'diverged', 'offline', 'backup-fails', 'composer-fails', 'migration-fails', 'build-fails']) {
    test(`Windows updater: ${mode}`, { skip: !windows, timeout: 60000 }, () => {
        const f = fixture(mode);
        const outcome = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', f.harness], {
            cwd: f.root, env: f.env, encoding: 'utf8', timeout: 45000,
        });
        writeFileSync(join(f.work, 'result.log'), outcome.stdout + '\n' + outcome.stderr);
        assert.equal(outcome.error, undefined, outcome.error?.message);
        assert.equal(outcome.status, mode === 'success' ? 0 : 1, outcome.stdout + outcome.stderr);
        const trace = existsSync(f.trace) ? readFileSync(f.trace, 'utf8') : '';
        assert.equal(trace.includes('START'), mode === 'success', trace);
        assert.equal(readFileSync(join(f.root, '.env'), 'utf8'), 'APP_KEY=fixture-preserve-me\nDB_CONNECTION=sqlite\n');
        assert.equal(readFileSync(join(f.root, 'storage/app/public/photo.txt'), 'utf8'), 'original-photo');
        const preMergeFailure = ['running', 'dirty', 'untracked', 'diverged', 'offline', 'backup-fails'].includes(mode);
        assert.equal(readFileSync(join(f.root, 'version.txt'), 'utf8'), mode === 'dirty' ? 'local important edit' : preMergeFailure ? 'old' : 'new');
        if (preMergeFailure) assert.ok(!trace.includes('composer '), trace);
        if (mode === 'composer-fails') assert.ok(!trace.includes('artisan migrate'), trace);
        if (mode === 'migration-fails') assert.ok(!trace.includes('npm run build'), trace);
        const backups = readdirSync(f.work).filter((name) => name.includes('-backup-'));
        if (['success', 'composer-fails', 'migration-fails', 'build-fails'].includes(mode)) {
            assert.equal(backups.length, 1);
            const backup = join(f.work, backups[0]);
            assert.ok(existsSync(join(backup, 'COMPLETE.txt')));
            assert.equal(readFileSync(join(backup, '.env'), 'utf8'), readFileSync(join(f.root, '.env'), 'utf8'));
            assert.equal(readFileSync(join(backup, 'storage-app/public/photo.txt'), 'utf8'), 'original-photo');
            const query = join(f.work, 'check.php');
            writeFileSync(query, '<?php $db = new PDO("sqlite:".$argv[1]); echo $db->query("SELECT title FROM products WHERE id=1")->fetchColumn();');
            assert.equal(execFileSync(php, [query, join(backup, 'database.sqlite')], { encoding: 'utf8', env: environment }), 'Original laptop');
            assert.equal(execFileSync(php, [query, join(f.root, 'database/database.sqlite')], { encoding: 'utf8', env: environment }), 'Original laptop');
        }
        if (mode === 'success') {
            assert.equal(existsSync(join(f.root, 'public/hot')), false);
            assert.equal(runGit(f.root, 'status', '--porcelain'), '', 'A successful update remains repeatable.');
            assert.ok(trace.indexOf('backup-before-update.php') < trace.indexOf('composer install'));
            assert.ok(trace.indexOf('artisan migrate --force') < trace.indexOf('npm run build'));
            // Reference data reaches every machine, after the tables exist, without touching edits.
            assert.ok(trace.indexOf('artisan migrate --force') < trace.indexOf('artisan db:seed --class=Database\\Seeders\\BrandCatalogSeeder --force'), trace);
            assert.ok(trace.includes('artisan db:seed --class=Database\\Seeders\\ProductSourceDomainHintSeeder --force'), trace);
            const repeated = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', f.harness], {
                cwd: f.root, env: f.env, encoding: 'utf8', timeout: 45000,
            });
            assert.equal(repeated.status, 0, repeated.stdout + repeated.stderr);
            assert.equal(readdirSync(f.work).filter((name) => name.includes('-backup-')).length, 2);
        }
    });
}

for (const mode of ['force-success', 'force-backup-fails', 'force-ignored-target', 'force-running', 'force-composer-fails']) {
    test(`Windows forced updater: ${mode}`, { skip: !windows, timeout: 60000 }, () => {
        const f = fixture(mode);
        const outcome = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', f.harness], {
            cwd: f.root, env: f.env, encoding: 'utf8', timeout: 45000,
        });
        writeFileSync(join(f.work, 'result.log'), outcome.stdout + '\n' + outcome.stderr);
        assert.equal(outcome.error, undefined, outcome.error?.message);
        assert.equal(outcome.status, mode === 'force-success' ? 0 : 1, outcome.stdout + outcome.stderr);
        const trace = existsSync(f.trace) ? readFileSync(f.trace, 'utf8') : '';
        assert.equal(trace.includes('START'), mode === 'force-success', trace);
        assert.equal(readFileSync(join(f.root, '.env'), 'utf8'), 'APP_KEY=fixture-preserve-me\nDB_CONNECTION=sqlite\n');
        assert.equal(readFileSync(join(f.root, 'storage/app/public/photo.txt'), 'utf8'), 'original-photo');
        assert.deepEqual(readFileSync(join(f.root, 'database/database.sqlite')), f.databaseBytes, 'The installed database must not be replaced.');
        assert.equal(readFileSync(join(f.root, 'personal.txt'), 'utf8'), 'untracked without collision', 'Force mode must not clean unrelated untracked files.');
        assert.deepEqual(readFileSync(join(f.root, f.unicodeFile)), f.binary);

        const replaced = ['force-success', 'force-composer-fails'].includes(mode);
        assert.equal(runGit(f.root, 'rev-parse', 'HEAD'), replaced ? f.target : f.localCommit);
        assert.equal(readFileSync(join(f.root, 'version.txt'), 'utf8'), replaced ? 'new' : f.unstaged);
        assert.equal(readFileSync(join(f.root, 'package.json'), 'utf8'), replaced ? '{"private":true}\n' : f.localPackage);
        assert.equal(readFileSync(join(f.root, 'incoming.txt'), 'utf8'), replaced ? 'remote tracked version' : 'local untracked collision');
        assert.equal(existsSync(join(f.root, 'local.txt')), !replaced);
        assert.equal(existsSync(join(f.root, 'delete-me.txt')), replaced);
        if (!replaced) assert.ok(!trace.includes('composer '), trace);
        if (mode === 'force-composer-fails') assert.ok(!trace.includes('artisan migrate'), trace);

        const backups = readdirSync(f.work).filter((name) => name.includes('-backup-'));
        if (!replaced) {
            assert.ok(backups.every((name) => !existsSync(join(f.work, name, 'COMPLETE.txt'))), 'A failed backup must never be declared complete.');
            if (mode === 'force-running') assert.equal(backups.length, 0, 'An active worker blocks even force mode before backup.');
            return;
        }

        assert.equal(backups.length, 1);
        const backup = join(f.work, backups[0]);
        assert.ok(existsSync(join(backup, 'COMPLETE.txt')));
        assert.equal(readFileSync(join(backup, '.env'), 'utf8'), readFileSync(join(f.root, '.env'), 'utf8'));
        assert.equal(readFileSync(join(backup, 'storage-app/public/photo.txt'), 'utf8'), 'original-photo');
        assert.equal(readFileSync(join(backup, 'git-commit-before.txt'), 'utf8').trim(), f.localCommit);

        const code = join(backup, 'local-code');
        assert.deepEqual(readFileSync(join(code, 'files/version.txt')), Buffer.from(f.unstaged));
        assert.equal(readFileSync(join(code, 'files/package.json'), 'utf8'), f.localPackage);
        assert.equal(readFileSync(join(code, 'files/incoming.txt'), 'utf8'), 'local untracked collision');
        assert.equal(readFileSync(join(code, 'files/personal.txt'), 'utf8'), 'untracked without collision');
        assert.deepEqual(readFileSync(join(code, 'files', f.unicodeFile)), f.binary, 'Unicode filenames and binary contents must survive the backup.');
        assert.match(readFileSync(join(code, 'status.txt'), 'utf8'), /delete-me\.txt/);
        const bundle = join(code, 'repository.bundle');
        runGit(f.root, 'bundle', 'verify', bundle);
        assert.ok(runGit(f.root, 'bundle', 'list-heads', bundle).includes(f.localCommit));
        // Prove that the backup alone can reconstruct the old commit, index and
        // working version, even after reset has removed them from the checkout.
        const recovered = join(f.work, 'recovered local code');
        runGit(f.work, 'clone', bundle, recovered);
        runGit(recovered, 'checkout', '--detach', f.localCommit);
        assert.equal(readFileSync(join(recovered, 'local.txt'), 'utf8'), 'local committed work');
        assert.equal(readFileSync(join(recovered, 'delete-me.txt'), 'utf8'), 'recover deleted file');
        runGit(recovered, 'apply', '--index', join(code, 'staged.patch'));
        assert.equal(readFileSync(join(recovered, 'version.txt'), 'utf8'), f.staged);
        runGit(recovered, 'apply', join(code, 'unstaged.patch'));
        assert.equal(readFileSync(join(recovered, 'version.txt'), 'utf8'), f.unstaged);
        assert.equal(readFileSync(join(recovered, 'package.json'), 'utf8'), f.localPackage);
        assert.equal(existsSync(join(recovered, 'delete-me.txt')), false);
        const check = join(f.work, 'check.php');
        writeFileSync(check, '<?php $db = new PDO("sqlite:".$argv[1]); echo $db->query("SELECT title FROM products WHERE id=1")->fetchColumn();');
        assert.equal(execFileSync(php, [check, join(backup, 'database.sqlite')], { encoding: 'utf8', env: environment }), 'Original laptop');
        assert.ok(trace.indexOf('backup-before-update.php') < trace.indexOf('composer install'));
    });
}

for (const mode of ['custom-path-and-old-cache', 'missing-database', 'mysql']) {
    test(`SQLite update backup: ${mode}`, { skip: !windows, timeout: 60000 }, () => {
        const f = fixture('backup-only');
        const backup = join(f.work, 'snapshot');
        mkdirSync(backup);
        const custom = join(f.work, 'custom database.sqlite');
        if (mode === 'custom-path-and-old-cache') {
            copyFileSync(join(f.root, 'database/database.sqlite'), custom);
            writeFileSync(join(f.root, '.env'), `DB_CONNECTION=sqlite\nDB_DATABASE="${custom.replaceAll('\\', '/')}"\n`);
        } else if (mode === 'missing-database') {
            writeFileSync(join(f.root, '.env'), `DB_CONNECTION=sqlite\nDB_DATABASE="${custom.replaceAll('\\', '/')}"\n`);
        } else {
            writeFileSync(join(f.root, '.env'), 'DB_CONNECTION=mysql\n');
        }
        const result = spawnSync(php, [join(f.root, 'scripts/backup-before-update.php'), f.root, backup], {
            cwd: f.root, env: f.env, encoding: 'utf8', timeout: 15000,
        });
        assert.equal(result.status, mode === 'custom-path-and-old-cache' ? 0 : 1, result.stdout + result.stderr);
        if (mode === 'custom-path-and-old-cache') {
            const manifest = JSON.parse(readFileSync(join(backup, 'databases.json'), 'utf8'));
            assert.equal(manifest.length, 2);
            assert.ok(existsSync(join(backup, 'database.sqlite')));
            assert.ok(existsSync(join(backup, 'database-cached.sqlite')));
        } else {
            assert.equal(existsSync(custom), false, 'Never create an empty replacement database.');
            assert.equal(existsSync(join(backup, 'databases.json')), false);
        }
    });
}
