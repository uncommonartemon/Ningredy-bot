import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkForUpdate } from '../../desktop/update-check.mjs';

// Real local Git remotes, as in the updater's own test; no network and no bot.
const project = fileURLToPath(new URL('../..', import.meta.url));
const artifactRoot = join(project, 'output', 'update-tests');
mkdirSync(artifactRoot, { recursive: true });
const environment = { ...process.env, GIT_CONFIG_GLOBAL: process.platform === 'win32' ? 'NUL' : '/dev/null', GIT_CONFIG_NOSYSTEM: '1' };
const git = (cwd, ...args) => execFileSync('git', args, { cwd, env: environment, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

function installation() {
    const work = mkdtempSync(join(artifactRoot, 'panel-'));
    const remote = join(work, 'remote.git');
    const upstream = join(work, 'upstream');
    const root = join(work, 'installed');
    git(work, 'init', '--bare', '-b', 'main', remote);
    git(work, 'clone', remote, upstream);
    git(upstream, 'config', 'user.name', 'Panel test');
    git(upstream, 'config', 'user.email', 'fixture@example.invalid');
    writeFileSync(join(upstream, 'version.txt'), 'one');
    git(upstream, 'add', '.');
    git(upstream, 'commit', '-m', 'first version');
    git(upstream, 'push', '-u', 'origin', 'HEAD:main');
    git(work, 'clone', '-b', 'main', remote, root);
    const publish = (text) => {
        writeFileSync(join(upstream, 'version.txt'), text);
        git(upstream, 'commit', '-am', `version ${text}`);
        git(upstream, 'push', 'origin', 'HEAD:main');
    };

    return { root, publish };
}

test('the panel says an update is there, how large it is, and when it has been installed', async () => {
    const { root, publish } = installation();
    assert.deepEqual(await checkForUpdate(root), { state: 'current' });

    publish('two');
    publish('three');
    const found = await checkForUpdate(root);
    assert.equal(found.state, 'available');
    assert.equal(found.count, 2);
    assert.match(found.date, /^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/);
    // Asking changes nothing in the project: the updater still starts from a clean tree.
    assert.equal(git(root, 'status', '--porcelain'), '');

    git(root, 'merge', '--ff-only', 'FETCH_HEAD');
    assert.deepEqual(await checkForUpdate(root), { state: 'current' });
});

test('a checkout the updater would refuse says why instead of offering an update', async () => {
    const { root, publish } = installation();
    publish('two');
    git(root, 'checkout', '--detach');
    const detached = await checkForUpdate(root);
    assert.equal(detached.state, 'blocked');
    assert.match(detached.reason, /не ветка main/);

    git(root, 'checkout', '-b', 'experiment');
    const branch = await checkForUpdate(root);
    assert.equal(branch.state, 'blocked');
    assert.match(branch.reason, /experiment/);
});

test('no way to GitHub is not reported as nothing new', async () => {
    const { root } = installation();
    git(root, 'remote', 'set-url', 'origin', join(root, '..', 'missing.git'));
    const found = await checkForUpdate(root);
    assert.equal(found.state, 'unknown');
    assert.match(found.reason, /Нет связи с GitHub/);
});
