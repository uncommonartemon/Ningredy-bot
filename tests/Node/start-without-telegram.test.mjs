import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { delimiter, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// Only fake CLI commands run. No PHP application, server, queue or Telegram API.
const windows = process.platform === 'win32';
const project = fileURLToPath(new URL('../..', import.meta.url));
const artifactRoot = join(project, 'output', 'web-launcher-tests');
const psQuote = (value) => `'${value.replaceAll("'", "''")}'`;

function runLauncher(mode) {
    mkdirSync(artifactRoot, { recursive: true });
    const work = mkdtempSync(join(artifactRoot, 'case-'));
    const root = join(work, "local copy Юзер's");
    for (const folder of ['scripts', 'vendor', 'database', 'public/build']) {
        mkdirSync(join(root, folder), { recursive: true });
    }
    const launcher = join(root, 'scripts/start-ningredy.ps1');
    copyFileSync(join(project, 'scripts/start-ningredy.ps1'), launcher);
    if (mode !== 'missing-artisan') writeFileSync(join(root, 'artisan'), '<?php // never executed');
    if (mode !== 'missing-env') writeFileSync(join(root, '.env'), 'APP_NAME=Local\nQUEUE_CONNECTION=database\n');
    if (mode !== 'missing-vendor') writeFileSync(join(root, 'vendor/autoload.php'), '<?php // fixture');
    if (mode !== 'missing-build') writeFileSync(join(root, 'public/build/manifest.json'), '{}');
    const database = join(root, 'database/database.sqlite');
    writeFileSync(database, 'catalog and queued work must remain untouched');
    const trace = join(work, 'trace.txt');
    const tool = join(work, 'tool.cjs');
    writeFileSync(tool, `
const { appendFileSync } = require('node:fs');
const [name, ...args] = process.argv.slice(2);
appendFileSync(process.env.WEB_LAUNCHER_TRACE, JSON.stringify([name, ...args]) + '\\n');
if (name === 'node') appendFileSync(process.env.WEB_LAUNCHER_TRACE, JSON.stringify(['runtime-mode', process.env.NINGREDY_ADMIN_ONLY]) + '\\n');
process.exit(process.env.WEB_LAUNCHER_MODE === 'serve-fails' && name === 'node' ? 7 : 0);
`);
    for (const name of ['php', 'npm', 'node']) {
        writeFileSync(join(work, name + '.cmd'), `@echo off\r\n"${process.execPath}" "${tool}" ${name} %*\r\nexit /b %errorlevel%\r\n`);
    }
    writeFileSync(join(root, 'scripts/clear-leftover-processes.ps1'), `Add-Content -LiteralPath $env:WEB_LAUNCHER_TRACE -Value '["cleanup"]'\nexit 0\n`);
    writeFileSync(join(root, 'scripts/keep-local-processes-together.ps1'), `Add-Content -LiteralPath $env:WEB_LAUNCHER_TRACE -Value '["process-group"]'\nif ($env:WEB_LAUNCHER_MODE -eq 'process-group-fails') { throw 'fixture failure' }\n[System.IntPtr]::new(1)\n`);
    if (mode !== 'missing-concurrently') {
        mkdirSync(join(root, 'node_modules/concurrently/dist/bin'), { recursive: true });
        writeFileSync(join(root, 'node_modules/concurrently/dist/bin/concurrently.js'), '// fixture; never executed');
    }
    const harness = join(work, 'harness.ps1');
    writeFileSync(harness, '\ufeff' + `
function Get-Command {
    param([string]$Name)
    if ($Name -eq 'php' -and $env:WEB_LAUNCHER_MODE -ne 'missing-php') {
        [PSCustomObject]@{Source = ${psQuote(join(work, 'php.cmd'))}}
    } elseif ($Name -eq 'node' -and $env:WEB_LAUNCHER_MODE -ne 'missing-node') {
        [PSCustomObject]@{Source = ${psQuote(join(work, 'node.cmd'))}}
    } elseif ($env:WEB_LAUNCHER_MODE -eq 'normal' -and $Name -in @('npm', 'ngrok')) {
        [PSCustomObject]@{Source = 'fixture-only'}
    }
}
& ${psQuote(launcher)} -NoPause${mode === 'normal' ? '' : ' -WithoutTelegram'}
exit $LASTEXITCODE
`);
    const environment = { ...process.env, WEB_LAUNCHER_TRACE: trace, WEB_LAUNCHER_MODE: mode,
        PATH: work + delimiter + process.env.PATH };
    for (const key of Object.keys(environment)) {
        if (/^(OPENAI_|TELEGRAM_)/.test(key)) delete environment[key];
    }
    const result = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', harness], {
        env: environment, encoding: 'utf8', timeout: 20000, windowsHide: true,
    });
    const calls = existsSync(trace) ? readFileSync(trace, 'utf8').trim().split(/\r?\n/).map((line) => JSON.parse(line)) : [];
    assert.equal(readFileSync(database, 'utf8'), 'catalog and queued work must remain untouched');
    if (mode !== 'missing-env') assert.equal(readFileSync(join(root, '.env'), 'utf8'), 'APP_NAME=Local\nQUEUE_CONNECTION=database\n');
    assert.ifError(result.error);

    return { ...result, calls, root };
}

const webCommand = 'php artisan serve --host=127.0.0.1 --port=8000 --tries=1 --no-reload';
const expectedLocalCalls = (root) => [
    ['process-group'],
    ['node', join(root, 'node_modules/concurrently/dist/bin/concurrently.js'), '--kill-others', '--names', 'web,admin-ai',
        '--prefix-colors', 'blue,green', webCommand, 'run-admin-ai-worker.bat'],
    ['runtime-mode', '1'],
];

test('local launcher only supervises localhost PHP and isolated admin AI, without ngrok/token or bot cleanup', { skip: !windows }, () => {
    const result = runLauncher('success');
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.deepEqual(result.calls, expectedLocalCalls(result.root));
    assert.match(result.stdout, /http:\/\/127\.0\.0\.1:8000\/admin/);
});

for (const mode of ['missing-artisan', 'missing-php', 'missing-env', 'missing-vendor', 'missing-build', 'missing-node', 'missing-concurrently']) {
    test(`web launcher refuses ${mode} before executing anything`, { skip: !windows }, () => {
        const result = runLauncher(mode);
        assert.equal(result.status, 1, result.stdout + result.stderr);
        assert.deepEqual(result.calls, []);
    });
}

test('failed local supervisor exits with an error and never cleans up another process', { skip: !windows }, () => {
    const result = runLauncher('serve-fails');
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.deepEqual(result.calls, expectedLocalCalls(result.root));
});

test('failed Windows process grouping does not start web or admin worker', { skip: !windows }, () => {
    const result = runLauncher('process-group-fails');
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.deepEqual(result.calls, [['process-group']]);
});

test('ordinary startup still follows cleanup, stale-job cleanup, smoke and bot startup order', { skip: !windows }, () => {
    const result = runLauncher('normal');
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.deepEqual(result.calls, [
        ['cleanup'], ['php', 'artisan', 'bot:clear-stale'], ['php', 'artisan', 'bot:smoke', '--machine'], ['npm', 'run', 'start'],
    ]);
});

test('admin worker drains only the database admin-ai queue and can restart after settings changes', () => {
    const worker = readFileSync(join(project, 'run-admin-ai-worker.bat'), 'utf8');
    assert.match(worker, /php artisan queue:work database --queue=admin-ai --sleep=1 --tries=1 --timeout=360 --max-time=86400/);
    assert.doesNotMatch(worker, /assistant,|voice|media|schedule:|sync-ngrok|clear-stale/);
    assert.match(worker, /goto restart/);
    assert.match(worker, /Start-Sleep -Seconds 5/);
    assert.doesNotMatch(worker, /timeout \/t/);
});

test('closing the local PowerShell job stops its dummy child and leaves an independent dummy process alive', { skip: !windows }, async () => {
    mkdirSync(artifactRoot, { recursive: true });
    const work = mkdtempSync(join(artifactRoot, 'lifetime-'));
    const dummy = join(work, 'dummy.cjs');
    const childPidFile = join(work, 'child.json');
    writeFileSync(dummy, `require('node:fs').writeFileSync(process.argv[2], JSON.stringify({pid:process.pid})); setInterval(()=>{}, 1000);`);
    const independent = spawn(process.execPath, [dummy, join(work, 'independent.json')], { stdio: 'ignore', windowsHide: true });
    let ownedPid;
    try {
        const harness = join(work, 'lifetime.ps1');
        writeFileSync(harness, `
$ErrorActionPreference = 'Stop'
$guard = & ${psQuote(join(project, 'scripts/keep-local-processes-together.ps1'))}
$child = Start-Process -FilePath ${psQuote(process.execPath)} -ArgumentList @(${psQuote('"' + dummy + '"')}, ${psQuote('"' + childPidFile + '"')}) -PassThru -WindowStyle Hidden
$deadline = [DateTime]::UtcNow.AddSeconds(5)
while (-not (Test-Path -LiteralPath ${psQuote(childPidFile)})) {
    if ([DateTime]::UtcNow -gt $deadline) { throw 'Dummy child did not start' }
    Start-Sleep -Milliseconds 25
}
exit 0
`);
        const result = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', harness], {
            encoding: 'utf8', timeout: 15000, windowsHide: true,
        });
        assert.ifError(result.error);
        assert.equal(result.status, 0, result.stdout + result.stderr);
        ownedPid = JSON.parse(readFileSync(childPidFile, 'utf8')).pid;
        await new Promise((resolve) => setTimeout(resolve, 250));
        assert.throws(() => process.kill(ownedPid, 0), { code: 'ESRCH' });
        assert.equal(process.kill(independent.pid, 0), true);
    } finally {
        independent.kill();
        if (ownedPid) {
            try { process.kill(ownedPid); } catch {}
        }
    }
});
