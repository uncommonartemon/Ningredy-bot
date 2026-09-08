import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { closeWithin, saveGalleryCheckpoint } from '../../scripts/gallery-checkpoint.mjs';

test('checkpoint preserves the latest complete DOM before a subsequent hang', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'gallery-checkpoint-'));
    try {
        saveGalleryCheckpoint(directory, { scout: { fragments: ['first DOM'] } });
        const latest = { scout: { fragments: ['viewer DOM'] }, diagnostics: { browser_stage: 'screenshot' } };
        saveGalleryCheckpoint(directory, latest);
        await closeWithin(() => new Promise(() => {}), 20);
        assert.deepEqual(JSON.parse(readFileSync(join(directory, 'checkpoint.json'), 'utf8')), latest);
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});

test('cleanup handles rejection and synchronous failure', async () => {
    await closeWithin(() => Promise.reject(new Error('closed')), 20);
    await closeWithin(() => { throw new Error('closed'); }, 20);
});
