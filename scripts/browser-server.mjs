/**
 * One browser for the whole bot, instead of one per page.
 *
 * Extraction used to launch Chromium, open the page, and close the browser -
 * per round. Training repeats a round four or five times on the same URL, so a
 * shop saw five separate first-time visitors hammering one page inside a couple
 * of minutes. That is what a bot looks like from the other side, and it is why
 * the challenges started.
 *
 * This process keeps a single browser alive for as long as the bot runs. Every
 * extraction connects to it, works in its own context, and disconnects - the
 * browser itself, and the reputation it accumulates with a host, stay. It is
 * started alongside the queue workers by npm run start, and extraction falls
 * back to launching its own browser whenever this is not reachable, so nothing
 * depends on it being up.
 */
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { dirname } from 'node:path';
import { chromium } from 'playwright-core';
import { browserServerEndpointFile } from './product-gallery-utils.mjs';

const endpointFile = browserServerEndpointFile(process.cwd());
const headless = process.env.PRODUCT_IMAGE_BROWSER_HEADLESS !== 'false';
let server = null;

for (const channel of [process.env.PRODUCT_IMAGE_BROWSER_CHANNEL || 'msedge', 'chrome', null]) {
    try {
        server = await chromium.launchServer({
            headless,
            args: ['--disable-blink-features=AutomationControlled'],
            ...(channel ? { channel } : {}),
        });
        process.stdout.write(`Shared browser started (${channel || 'bundled chromium'}, headless=${headless}).\n`);
        break;
    } catch {
        // Try the next locally available Chromium channel.
    }
}

if (!server) {
    process.stderr.write('No compatible Chromium browser is installed; extraction will launch its own.\n');
    process.exit(3);
}

await mkdir(dirname(endpointFile), { recursive: true });
await writeFile(endpointFile, JSON.stringify({
    ws_endpoint: server.wsEndpoint(),
    pid: process.pid,
    started_at: new Date().toISOString(),
}), 'utf8');

// The endpoint file outliving the browser would send every extraction to a dead
// socket, so it is removed on the way out however the process ends.
const shutdown = async () => {
    await rm(endpointFile, { force: true }).catch(() => {});
    await server.close().catch(() => {});
    process.exit(0);
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
process.on('SIGHUP', shutdown);
server.on('close', () => {
    rm(endpointFile, { force: true }).catch(() => {});
});
