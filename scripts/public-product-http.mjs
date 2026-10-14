import dns from 'node:dns/promises';
import net from 'node:net';
import http from 'node:http';
import https from 'node:https';
import { createBrotliDecompress, createGunzip, createInflate } from 'node:zlib';
import { realpath, rm } from 'node:fs/promises';
import { join, sep } from 'node:path';
import { isNavigationRequestOffConfinedHost } from './product-gallery-utils.mjs';

export const isPublicProductIp = (address) => {
    if (net.isIP(address) === 4) {
        const [a, b, c] = address.split('.').map(Number);
        return !(a === 0 || a === 10 || a === 127 || a >= 224
            || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254)
            || (a === 172 && b >= 16 && b <= 31)
            || (a === 192 && (b === 168 || (b === 0 && (c === 0 || c === 2)) || (b === 88 && c === 99)))
            || (a === 198 && (b === 18 || b === 19 || (b === 51 && c === 100)))
            || (a === 203 && b === 0 && c === 113));
    }
    if (net.isIP(address) !== 6) return false;
    // URL canonicalizes expanded forms. Only global unicast is eligible;
    // mapped IPv4, NAT64, local, multicast and tunnel prefixes stay blocked.
    const ip = new URL(`http://[${address}]/`).hostname.slice(1, -1).toLowerCase();
    return /^[23][0-9a-f]{3}:/.test(ip) && !ip.startsWith('2001:db8:')
        && !ip.startsWith('2001:0:') && !ip.startsWith('2001::') && !ip.startsWith('2002:') && !ip.startsWith('3fff:');
};

export const resolvePublicProductUrl = async (rawUrl, lookup = dns.lookup) => {
    const url = new URL(rawUrl);
    const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password
        || host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.internal')) {
        throw new Error('Blocked non-public product source URL.');
    }
    const addresses = net.isIP(host)
        ? [{ address: host, family: net.isIP(host) }]
        : await lookup(host, { all: true });
    if (!addresses.length || addresses.some(({ address }) => !isPublicProductIp(address))) {
        throw new Error('Blocked non-public product source URL.');
    }
    return { url, host, addresses };
};

export const createPublicProductUrlCheck = () => {
    const hosts = new Map();
    return async (rawUrl) => {
        try {
            const url = new URL(rawUrl);
            // Parse/auth/scheme checks run on every URL, not only its host's first use.
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return false;
            if (!hosts.has(url.hostname)) hosts.set(url.hostname, resolvePublicProductUrl(url.href).then(() => true, () => false));
            return await hosts.get(url.hostname);
        } catch { return false; }
    };
};

export const installProductNetworkGuard = async (context, publicHttpUrl, confineHost = '') => {
    // Context scope covers new tabs/popups before their first request. This
    // complements serviceWorkers:'block'; neither is DNS pinning for Chromium.
    await context.route('**/*', async (route) => {
        const request = route.request();
        if (!await publicHttpUrl(request.url())) return route.abort('blockedbyclient');
        let mainFrame;
        try { mainFrame = request.frame().page().mainFrame(); } catch { return route.abort('blockedbyclient'); }
        if (isNavigationRequestOffConfinedHost(request, mainFrame, confineHost)) return route.abort('blockedbyclient');
        return route.continue();
    });
    await context.routeWebSocket('**/*', async (socket) => {
        try {
            const url = new URL(socket.url());
            if (!['ws:', 'wss:'].includes(url.protocol)) throw new Error('Unsupported socket scheme.');
            url.protocol = url.protocol === 'wss:' ? 'https:' : 'http:';
            if (!await publicHttpUrl(url.href)) throw new Error('Private socket destination.');
            socket.connectToServer(); // Default forwarding preserves legitimate public CDN sockets.
        } catch {
            await socket.close({ code: 1008, reason: 'Non-public destination blocked' });
        }
    });
};

export const clearStoredProductServiceWorkers = async (profileDirectory, ownedProfilesRoot) => {
    // Playwright blocks new registration, not old registrations on disk. Clear
    // only this browser profile's SW store before Chromium can run an old worker.
    // A busy/unwritable store throws so the caller uses a fresh context instead.
    const root = await realpath(profileDirectory);
    const normalized = value => process.platform === 'win32' ? value.toLowerCase() : value;
    if (!ownedProfilesRoot || !normalized(root).startsWith(normalized(ownedProfilesRoot) + sep)) {
        throw new Error('Browser profile escaped application-owned storage.');
    }
    let workerStore;
    try { workerStore = await realpath(join(root, 'Default', 'Service Worker')); }
    catch (error) { if (error.code === 'ENOENT') return; throw error; }
    if (!normalized(workerStore).startsWith(normalized(root) + sep)) throw new Error('Service Worker store escaped browser profile.');
    await rm(workerStore, { recursive: true, force: true, maxRetries: 2, retryDelay: 100 });
};

const nativeRequest = (url, options, callback) => (url.protocol === 'https:' ? https : http).request(url, options, callback);

// Session cookies already obtained by the real browser are read for EACH hop.
// Temporary download Set-Cookies are deliberately host-only: an untrusted CDN
// response cannot widen cookie scope or mutate another browser origin.
const rememberCookies = (jar, response, url) => {
    for (const line of response.headers['set-cookie'] || []) {
        const [pair, ...attributes] = line.split(';');
        const separator = pair.indexOf('=');
        if (separator < 1) continue;
        const name = pair.slice(0, separator).trim();
        const value = pair.slice(separator + 1).trim();
        const attrs = Object.fromEntries(attributes.map(part => {
            const index = part.indexOf('=');
            return index === -1 ? [part.trim().toLowerCase(), true]
                : [part.slice(0, index).trim().toLowerCase(), part.slice(index + 1).trim()];
        }));
        if (typeof attrs.domain === 'string') {
            const domain = attrs.domain.replace(/^\./, '').toLowerCase();
            if (url.hostname !== domain && !url.hostname.endsWith('.' + domain)) continue;
        }
        const path = typeof attrs.path === 'string' && attrs.path.startsWith('/') ? attrs.path
            : url.pathname.slice(0, url.pathname.lastIndexOf('/') + 1) || '/';
        const expiry = typeof attrs['max-age'] === 'string' && /^-?\d+$/.test(attrs['max-age'])
            ? Date.now() + Number(attrs['max-age']) * 1000
            : typeof attrs.expires === 'string' ? Date.parse(attrs.expires) : Infinity;
        jar.set(`${url.hostname}|${path}|${name}`, { host: url.hostname, name, value, path, secure: attrs.secure === true,
            expires: Number.isFinite(expiry) ? expiry : Infinity });
    }
};

const cookieHeader = async (context, url, jar) => {
    const cookies = new Map((await context.cookies(url.href))
        .filter(cookie => !cookie.secure || url.protocol === 'https:')
        .map(cookie => [cookie.name, cookie.value]));
    for (const cookie of jar.values()) {
        if (cookie.host !== url.hostname || (cookie.secure && url.protocol !== 'https:')
            || !(url.pathname === cookie.path || url.pathname.startsWith(cookie.path.endsWith('/') ? cookie.path : cookie.path + '/'))) continue;
        if (cookie.expires <= Date.now()) cookies.delete(cookie.name);
        else cookies.set(cookie.name, cookie.value);
    }
    return [...cookies].map(([name, value]) => `${name}=${value}`).join('; ');
};

/** No APIRequest buffering: every hop pins DNS and bounds wire + decoded bytes. */
export const createPinnedProductDownloader = ({ lookup = dns.lookup, request = nativeRequest } = {}) =>
    async (rawUrl, { context, referer = '', userAgent = '', maxBytes = 8_388_608, timeout = 5000, maxRedirects = 3 }) => {
        const deadline = Date.now() + timeout;
        const jar = new Map();
        let nextUrl = rawUrl;
        for (let hop = 0; hop <= maxRedirects; hop++) {
            const target = await resolvePublicProductUrl(nextUrl, lookup);
            const remaining = deadline - Date.now();
            if (remaining <= 0) throw new Error('Product image download timeout.');
            const cookie = await cookieHeader(context, target.url, jar);
            const pinned = target.addresses.find(item => item.family === 4) || target.addresses[0];
            let req;
            let timer;
            let response;
            try {
                response = await new Promise((resolve, reject) => {
                    req = request(target.url, {
                        method: 'GET', agent: false,
                        // URL/Host and TLS SNI retain the original hostname, but the
                        // socket cannot trigger a second, different DNS resolution.
                        lookup: (_host, options, callback) => options?.all
                            ? callback(null, [pinned]) : callback(null, pinned.address, pinned.family),
                        headers: { accept: 'image/avif,image/webp,image/*,*/*;q=0.8', 'accept-encoding': 'gzip, deflate, br',
                            ...(referer ? { referer } : {}), ...(userAgent ? { 'user-agent': userAgent } : {}),
                            ...(cookie ? { cookie } : {}) },
                    }, resolve);
                    timer = setTimeout(() => req.destroy(new Error('Product image download timeout.')), remaining);
                    req.on('error', reject);
                    req.end();
                });
                rememberCookies(jar, response, target.url);
                if ([301, 302, 303, 307, 308].includes(response.statusCode)) {
                    if (!response.headers.location || hop === maxRedirects) throw new Error('Too many or invalid product image redirects.');
                    nextUrl = new URL(response.headers.location, target.url).href;
                    continue; // finally closes this body; the next URL gets a new check + pin.
                }
                if (response.statusCode < 200 || response.statusCode >= 300) throw new Error(`HTTP ${response.statusCode}`);
                if (Number(response.headers['content-length'] || 0) > maxBytes) throw new Error('Product image exceeds_max_bytes.');
                let wireBytes = 0;
                let decodedBytes = 0;
                const chunks = [];
                const encoding = String(response.headers['content-encoding'] || '').toLowerCase();
                const decoder = encoding === 'gzip' ? createGunzip() : encoding === 'deflate' ? createInflate()
                    : encoding === 'br' ? createBrotliDecompress() : null;
                if (encoding && encoding !== 'identity' && !decoder) throw new Error('Unsupported image content encoding.');
                response.on('data', chunk => {
                    wireBytes += chunk.length;
                    if (wireBytes > maxBytes) response.destroy(new Error('Product image exceeds_max_bytes.'));
                });
                if (decoder) response.on('error', error => decoder.destroy(error));
                const stream = decoder ? response.pipe(decoder) : response;
                try {
                    for await (const chunk of stream) {
                        decodedBytes += chunk.length;
                        if (decodedBytes > maxBytes) throw new Error('Product image exceeds_max_bytes.');
                        chunks.push(chunk);
                    }
                } finally { decoder?.destroy(); }
                if (!decodedBytes) throw new Error('Empty product image.');
                return { body: Buffer.concat(chunks, decodedBytes), url: target.url.href };
            } finally {
                clearTimeout(timer);
                response?.destroy();
                req?.destroy();
            }
        }
        throw new Error('Too many product image redirects.');
    };

export const downloadPublicProductImage = createPinnedProductDownloader();
