// Opens pages of a running copy of the site and takes a screenshot of each.
// Usage: node look.mjs <base url> <path> [<path> ...]
// Environment:
//   LOOK_USER, LOOK_PASS   admin sign-in, used when a path starts with /admin
//   LOOK_FIND              texts separated by | - reports whether each is on the page
//   LOOK_FULL=1            a full-page screenshot instead of the first screen
//   LOOK_WIDTH             viewport width, 1440 by default
//   LOOK_CLICK             text of an element to press before the screenshot;
//                          works only with LOOK_ALLOW_CLICKS=1 (your own copy, never the operator's site)
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const require = createRequire(path.join(root, 'package.json'));
const { chromium } = require('playwright-core');

const [base, ...paths] = process.argv.slice(2);
if (!base || paths.length === 0) {
    console.log('usage: node look.mjs <base url> <path> [<path> ...]');
    process.exit(1);
}
const out = path.join(root, 'storage', 'app', 'agent', 'shots');
mkdirSync(out, { recursive: true });
const find = (process.env.LOOK_FIND ?? '').split('|').map((text) => text.trim()).filter(Boolean);
const mayClick = process.env.LOOK_ALLOW_CLICKS === '1';

const browser = await chromium.launch({ headless: true });
try {
    const page = await (await browser.newContext({ viewport: { width: Number(process.env.LOOK_WIDTH || 1440), height: 1000 } })).newPage();
    let serverErrors = [];
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url().replace(base, '').slice(0, 90)}`);
    });
    if (paths.some((item) => item.startsWith('/admin'))) {
        await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.locator('[id="form.name"]').fill(process.env.LOOK_USER ?? '');
        await page.locator('[id="form.password"]').fill(process.env.LOOK_PASS ?? '');
        await page.locator('button[type="submit"]').first().click();
        await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 45000 }).catch(() => {});
        if (new URL(page.url()).pathname.endsWith('/login')) {
            console.log('sign-in failed: check LOOK_USER and LOOK_PASS');
            process.exit(2);
        }
    }
    for (const item of paths) {
        serverErrors = [];
        const response = await page.goto(base + item, { waitUntil: 'networkidle', timeout: 90000 });
        await page.waitForTimeout(600);
        if (process.env.LOOK_CLICK) {
            if (!mayClick) {
                console.log('LOOK_CLICK ignored: pressing is allowed only with LOOK_ALLOW_CLICKS=1, on your own copy');
            } else {
                await page.getByText(process.env.LOOK_CLICK, { exact: false }).first().click();
                await page.waitForTimeout(1200);
            }
        }
        const name = item.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';
        const file = path.join(out, `${name}.png`);
        await page.screenshot({ path: file, fullPage: process.env.LOOK_FULL === '1' });
        const text = await page.locator('body').innerText().catch(() => '');
        console.log(`${item} -> HTTP ${response?.status()} | ${await page.title()}`);
        for (const wanted of find) console.log(`   ${text.includes(wanted) ? 'there' : 'MISSING'}: ${wanted}`);
        if (serverErrors.length) console.log(`   server errors: ${[...new Set(serverErrors)].join(' | ')}`);
        console.log(`   screenshot: ${file}`);
    }
} finally {
    await browser.close();
}
