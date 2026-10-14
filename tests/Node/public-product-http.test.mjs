import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { gzipSync } from 'node:zlib';
import { chromium } from 'playwright-core';
import { mkdtemp, mkdir, readFile, writeFile, access, realpath, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, dirname, basename } from 'node:path';
import { isPublicProductIp, resolvePublicProductUrl, createPublicProductUrlCheck,
    installProductNetworkGuard, createPinnedProductDownloader, clearStoredProductServiceWorkers } from '../../scripts/public-product-http.mjs';

test('public IP policy rejects private, reserved and alternate encodings', async () => {
    for (const ip of ['127.0.0.1', '10.0.0.2', '100.64.0.1', '169.254.169.254', '172.16.0.1', '192.168.1.1',
        '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '::', '::1', 'fc00::1', 'fe80::1',
        '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::1']) assert.equal(isPublicProductIp(ip), false, ip);
    for (const ip of ['8.8.8.8', '93.184.216.34', '192.0.78.1', '2606:4700:4700::1111']) assert.equal(isPublicProductIp(ip), true, ip);
    for (const url of ['file:///etc/passwd', 'http://127.1/', 'http://2130706433/', 'http://0x7f000001/',
        'http://[::ffff:7f00:1]/', 'http://user:pass@8.8.8.8/', 'http://localhost/']) {
        await assert.rejects(resolvePublicProductUrl(url));
    }
    await assert.rejects(resolvePublicProductUrl('http://mixed.example/', async () => [
        { address: '8.8.8.8', family: 4 }, { address: '::1', family: 6 },
    ]));
});

const withFixture = async (handler, work) => {
    const server = http.createServer(handler);
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const pins = [];
    const lookups = [];
    // Transport substitution only in this test: production validates a public
    // IP; the canary replaces the resulting socket with our own loopback server.
    const download = createPinnedProductDownloader({
        lookup: async host => { lookups.push(host); return [{ address: '93.184.216.34', family: 4 }]; },
        request: (url, options, callback) => {
            options.lookup(url.hostname, {}, (error, address) => { assert.ifError(error); pins.push(address); });
            return http.request({ ...options, host: '127.0.0.1', port: server.address().port,
                path: url.pathname + url.search, lookup: undefined, headers: { ...options.headers, host: url.hostname } }, callback);
        },
    });
    const context = { cookies: async url => [{ name: 'browser_session', value: new URL(url).hostname }] };
    try { await work(download, { context, timeout: 3000, maxBytes: 1024 }, { pins, lookups, port: server.address().port }); }
    finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
};

test('download keeps browser cookies/referer, pins checked IP and follows a legal CDN redirect', async () => {
    const seen = [];
    await withFixture((req, res) => {
        seen.push({ path: req.url, cookie: req.headers.cookie, referer: req.headers.referer, host: req.headers.host });
        if (req.url === '/start') return res.writeHead(302, { Location: 'http://cdn.example/step', 'Set-Cookie': 'shop_only=secret; Path=/' }).end();
        if (req.url === '/step') return res.writeHead(302, { Location: '/image', 'Set-Cookie': 'cdn_token=ok; Path=/' }).end();
        res.end('valid-image-bytes');
    }, async (download, options, evidence) => {
        const result = await download('http://shop.example/start', { ...options, referer: 'https://shop.example/product' });
        assert.equal(result.body.toString(), 'valid-image-bytes');
        assert.equal(result.url, 'http://cdn.example/image');
        assert.deepEqual(evidence.pins, ['93.184.216.34', '93.184.216.34', '93.184.216.34']);
        assert.deepEqual(evidence.lookups, ['shop.example', 'cdn.example', 'cdn.example']);
        assert.match(seen[0].cookie, /browser_session=shop.example/);
        assert.match(seen[2].cookie, /browser_session=cdn.example/);
        assert.match(seen[2].cookie, /cdn_token=ok/);
        assert.doesNotMatch(seen[2].cookie, /shop_only/);
        assert.equal(seen[2].referer, 'https://shop.example/product');
    });
});

test('redirect to private URL is rejected before the second network request', async () => {
    let received = 0;
    await withFixture((_req, res) => { received++; res.writeHead(302, { Location: 'http://127.0.0.1/private' }).end(); },
        async (download, options) => {
            await assert.rejects(download('http://public.example/start', options), /non-public/);
            assert.equal(received, 1);
        });
});

test('image limits cover oversized headers, chunked bodies and gzip expansion', async () => {
    await withFixture((req, res) => {
        if (req.url === '/headers') return res.writeHead(200, { 'Content-Length': '999999999' }).end('small');
        if (req.url === '/gzip') return res.writeHead(200, { 'Content-Encoding': 'gzip' }).end(gzipSync(Buffer.alloc(32_000)));
        res.writeHead(200, { 'Transfer-Encoding': 'chunked' });
        res.write(Buffer.alloc(600));
        res.end(Buffer.alloc(600));
    }, async (download, options) => {
        for (const path of ['/headers', '/chunked', '/gzip']) {
            await assert.rejects(download('http://public.example' + path, options), /exceeds_max_bytes/, path);
        }
    });
});

test('DNS changing to private on a later hop is refused; the first socket only sees the pinned result', async () => {
    let calls = 0;
    let network = 0;
    await withFixture((_req, res) => { network++; res.writeHead(302, { Location: '/next' }).end(); }, async (_download, options, evidence) => {
        const download = createPinnedProductDownloader({
            lookup: async () => [{ address: ++calls === 1 ? '93.184.216.34' : '127.0.0.1', family: 4 }],
            request: (url, requestOptions, callback) => {
                requestOptions.lookup(url.hostname, {}, (_error, address) => assert.equal(address, '93.184.216.34'));
                return http.request({ ...requestOptions, host: '127.0.0.1', port: evidence.port,
                    path: url.pathname, lookup: undefined }, callback);
            },
        });
        await assert.rejects(download('http://rebind.example/start', options), /non-public/);
        assert.equal(calls, 2);
        assert.equal(network, 1);
    });
});

test('websocket guard refuses private sockets while forwarding public CDN sockets', async () => {
    let handler;
    await installProductNetworkGuard({ route: async () => {}, routeWebSocket: async (_pattern, callback) => { handler = callback; } }, createPublicProductUrlCheck());
    let closed = 0;
    let forwarded = 0;
    for (const url of ['ws://127.0.0.1/private', 'wss://[::1]/private', 'wss://8.8.8.8/gallery']) {
        await handler({ url: () => url, close: async () => closed++, connectToServer: () => forwarded++ });
    }
    assert.equal(closed, 2);
    assert.equal(forwarded, 1);
});

test('persistent profile cleanup removes only stored service workers', async () => {
    const root = await mkdtemp(join(tmpdir(), 'ningredy-sw-policy-'));
    try {
        const profile = join(root, 'profiles', 'shop.example');
        const worker = join(profile, 'Default', 'Service Worker');
        await mkdir(worker, { recursive: true });
        await writeFile(join(worker, 'registration'), 'old worker');
        await writeFile(join(profile, 'Default', 'Cookies'), 'retained session');
        const ownedRoot = await realpath(join(root, 'profiles'));
        await assert.rejects(clearStoredProductServiceWorkers(profile, join(root, 'foreign')), /escaped/);
        await access(worker);
        await clearStoredProductServiceWorkers(profile, ownedRoot);
        await assert.rejects(access(worker));
        assert.equal(await readFile(join(profile, 'Default', 'Cookies'), 'utf8'), 'retained session');
        await clearStoredProductServiceWorkers(profile, ownedRoot); // Already absent is harmless.
    } finally {
        const resolved = await realpath(root);
        if (dirname(resolved).toLowerCase() === (await realpath(tmpdir())).toLowerCase()
            && basename(resolved).startsWith('ningredy-sw-policy-')) await rm(resolved, { recursive: true, force: true });
    }
});

test('real browser context guard blocks private popup/iframe/fetch and service worker, keeps public CDN', { timeout: 20000 }, async () => {
    let canaryHits = 0;
    const canary = http.createServer((_req, res) => { canaryHits++; res.end('private'); });
    canary.on('upgrade', (_req, socket) => { canaryHits++; socket.destroy(); });
    await new Promise(resolve => canary.listen(0, '127.0.0.1', resolve));
    const privateUrl = `http://127.0.0.1:${canary.address().port}/private`;
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({ serviceWorkers: 'block' });
        let publicCdnHits = 0;
        let workerRequests = 0;
        const route = context.route.bind(context);
        // Existing gallery E2E preload uses the same approach: replace only
        // continue(), so production checks execute before fixture fulfillment.
        context.route = (pattern, handler) => route(pattern, (realRoute, request) => handler(new Proxy(realRoute, {
            get(target, key) {
                if (key === 'continue') return async () => {
                    const url = new URL(request.url());
                    assert.ok(['8.8.8.8', '9.9.9.9'].includes(url.hostname));
                    if (url.hostname === '9.9.9.9') { publicCdnHits++; return realRoute.fulfill({ body: 'cdn-ok' }); }
                    if (url.pathname === '/sw.js') workerRequests++;
                    return realRoute.fulfill({ contentType: 'text/html', body: `<a id="open" target="_blank" href="${privateUrl}">Gallery</a>` });
                };
                const value = Reflect.get(target, key);
                return typeof value === 'function' ? value.bind(target) : value;
            },
        }), request));
        await installProductNetworkGuard(context, createPublicProductUrlCheck());
        const page = await context.newPage();
        await page.goto('https://8.8.8.8/product');
        await page.evaluate(url => { fetch(url).catch(() => {}); const f = document.createElement('iframe'); f.src = url; document.body.append(f);
            const socket = new WebSocket(url.replace('http:', 'ws:')); socket.onerror = () => {}; }, privateUrl);
        const popup = context.waitForEvent('page');
        await page.locator('#open').click();
        await (await popup).waitForLoadState().catch(() => {});
        await page.evaluate(() => { navigator.serviceWorker.register('/sw.js').catch(() => {}); return fetch('https://9.9.9.9/image', { mode: 'no-cors' }); });
        await new Promise(resolve => setTimeout(resolve, 250));
        assert.equal(canaryHits, 0);
        assert.equal(workerRequests, 0);
        assert.equal(publicCdnHits, 1);
    } finally {
        await browser.close();
        canary.closeAllConnections();
        await new Promise(resolve => canary.close(resolve));
    }
});
