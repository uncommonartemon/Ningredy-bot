// Walks the whole admin panel in a real browser: every page of the menu, every tab, the create
// page and the first record's edit page of every list. It only navigates. Reports what failed
// to open, server errors behind a page and errors in the browser's console.
// Usage: node walk-admin.mjs <base url> [only paths containing this]
// Environment: LOOK_USER, LOOK_PASS - the admin sign-in.
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const require = createRequire(path.join(root, 'package.json'));
const { chromium } = require('playwright-core');

const base = process.argv[2];
const only = process.argv[3] ?? '';
if (!base) {
    console.log('usage: node walk-admin.mjs <base url> [only paths containing this]');
    process.exit(1);
}
const out = path.join(root, 'storage', 'app', 'agent');
mkdirSync(out, { recursive: true });
const broken = /Internal Server Error|Whoops|SQLSTATE|Call to undefined|Undefined (variable|array key|property)|ErrorException|TypeError/;

const browser = await chromium.launch({ headless: true });
const findings = [];
const visited = [];
try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    let page;
    let consoleErrors = [];
    let badResponses = [];
    // A tab of the test browser that died is replaced: the session lives in the context.
    const freshPage = async () => {
        page = await context.newPage();
        page.on('console', (message) => { if (message.type() === 'error') consoleErrors.push(message.text().slice(0, 200)); });
        page.on('pageerror', (error) => consoleErrors.push(`pageerror: ${String(error).slice(0, 200)}`));
        page.on('response', (response) => {
            const url = response.url();
            if (response.status() >= 400 && url.startsWith(base) && !url.includes('/livewire/preview-file')) {
                badResponses.push(`${response.status()} ${url.replace(base, '').slice(0, 120)}`);
            }
        });
    };
    await freshPage();

    const open = async (item, label, attempt = 1) => {
        consoleErrors = [];
        badResponses = [];
        let status = 0;
        try {
            const response = await page.goto(base + item, { waitUntil: 'networkidle', timeout: 60000 });
            status = response?.status() ?? 0;
        } catch (error) {
            const crashed = /crash|closed/i.test(String(error));
            if (crashed) await freshPage();
            if (attempt === 1) return open(item, label, 2);
            findings.push({ path: item, label, problem: `${crashed ? 'the test browser tab died twice' : 'did not open'}: ${String(error).split('\n')[0].slice(0, 160)}` });
            return false;
        }
        await page.waitForTimeout(350);
        const text = (await page.locator('body').innerText().catch(() => '')).slice(0, 4000);
        const failed = status >= 400 || broken.test(text);
        visited.push({ path: item, label, status, attempt });
        if (failed) findings.push({ path: item, label, problem: `error on the page (HTTP ${status}): ${text.replace(/\s+/g, ' ').slice(0, 260)}` });
        if (badResponses.length) findings.push({ path: item, label, problem: `failed requests: ${[...new Set(badResponses)].slice(0, 4).join(' | ')}` });
        if (consoleErrors.length) findings.push({ path: item, label, problem: `browser console: ${[...new Set(consoleErrors)].slice(0, 3).join(' | ')}` });
        return !failed;
    };

    await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.locator('[id="form.name"]').fill(process.env.LOOK_USER ?? '');
    await page.locator('[id="form.password"]').fill(process.env.LOOK_PASS ?? '');
    await page.locator('button[type="submit"]').first().click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 45000 }).catch(() => {});
    if (new URL(page.url()).pathname.endsWith('/login')) {
        console.log('sign-in failed: check LOOK_USER and LOOK_PASS');
        process.exit(2);
    }

    await open('/admin', 'home');
    const menu = await page.locator('a.fi-sidebar-item-btn[href]').evaluateAll((links) => links.map((link) => [link.getAttribute('href'), link.textContent.trim().replace(/\s+/g, ' ')]));
    const pages = [...new Map(menu.filter(([href]) => href && href.startsWith(`${base}/admin`)).map(([href, label]) => [href.replace(base, ''), label])).entries()]
        .filter(([item]) => item.includes(only));
    for (const [item, label] of pages) {
        if (!(await open(item, label))) continue;
        const tabs = await page.locator('.fi-tabs .fi-tabs-item').allInnerTexts().catch(() => []);
        for (const tab of tabs.slice(0, 8)) {
            const name = tab.trim().split('\n')[0];
            if (!name) continue;
            consoleErrors = [];
            badResponses = [];
            await page.locator('.fi-tabs .fi-tabs-item', { hasText: name }).first().click().catch(() => {});
            await page.waitForTimeout(900);
            const text = (await page.locator('body').innerText().catch(() => '')).slice(0, 3000);
            if (broken.test(text) || badResponses.length) {
                findings.push({ path: item, label: `${label} -> tab ${name}`, problem: badResponses[0] ?? text.replace(/\s+/g, ' ').slice(0, 200) });
            }
            visited.push({ path: `${item} [tab ${name}]`, label, status: 200, attempt: 1 });
        }
        const create = await page.locator('a[href$="/create"]').first().getAttribute('href', { timeout: 1500 }).catch(() => null);
        const edit = await page.locator('table a[href*="/edit"]').first().getAttribute('href', { timeout: 1500 }).catch(() => null);
        if (create) await open(create.replace(base, ''), `${label} -> create`);
        if (edit && edit.startsWith(base)) await open(edit.replace(base, ''), `${label} -> first record`);
    }
    writeFileSync(path.join(out, 'admin-walk.json'), JSON.stringify({ visited, findings }, null, 1));
    const retried = visited.filter((entry) => entry.attempt === 2).map((entry) => entry.label);
    console.log(`pages opened: ${visited.length}; findings: ${findings.length}; opened on the second try: ${retried.join(', ') || 'none'}`);
    for (const finding of findings) console.log(`- ${finding.label} (${finding.path}): ${finding.problem}`);
} finally {
    await browser.close();
}
