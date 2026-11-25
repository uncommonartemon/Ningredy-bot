// Screenshots of other sites for design references: the first screen and frames further
// down, so the agent can look at them with its own eyes before borrowing an idea.
// Usage: node shoot-refs.mjs <output folder> <url> [url ...]
// The output folder should be outside the repository (a scratch folder): the pictures are
// other people's pages and do not belong in git.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const require = createRequire(path.join(root, 'package.json'));
const { chromium } = require('playwright-core');
const [out, ...sites] = process.argv.slice(2);
if (!out || sites.length === 0) {
    console.log('usage: node shoot-refs.mjs <output folder> <url> [url ...]');
    process.exit(1);
}
mkdirSync(out, { recursive: true });
const frames = Number(process.env.REFS_FRAMES || 6);
const browser = await chromium.launch({ headless: true });
for (const url of sites) {
    const address = new URL(url);
    const name = address.hostname.replace(/^www\./, '') + address.pathname.replace(/\W+/g, '_');
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(3500);
        const height = await page.evaluate(() => document.documentElement.scrollHeight);
        const steps = Math.min(frames, Math.ceil(height / 900));
        for (let step = 0; step < steps; step++) {
            await page.evaluate((y) => window.scrollTo(0, y), step * 1100);
            await page.waitForTimeout(1300);
            await page.screenshot({ path: path.join(out, `${name}-${step}.jpg`), type: 'jpeg', quality: 60 });
        }
        console.log('ok', url, `${steps} frames`);
    } catch (error) {
        console.log('failed', url, error.message.split('\n')[0]);
    }
    await page.close();
}
await browser.close();
