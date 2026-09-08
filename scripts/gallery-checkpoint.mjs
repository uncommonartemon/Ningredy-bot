import { renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// Atomic replacement: a hard process kill must leave either the previous
// complete checkpoint or the new one, never half a JSON document.
export function saveGalleryCheckpoint(directory, result) {
    if (!directory) return;
    try {
        const target = join(directory, 'checkpoint.json');
        writeFileSync(`${target}.tmp`, JSON.stringify(result));
        renameSync(`${target}.tmp`, target);
    } catch {
        // Checkpoint storage is best effort; stdout remains the primary channel.
    }
}

export async function closeWithin(close, milliseconds = 3000) {
    let timer;
    try {
        await Promise.race([
            Promise.resolve().then(close).catch(() => {}),
            new Promise((resolve) => { timer = setTimeout(resolve, milliseconds); }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}
