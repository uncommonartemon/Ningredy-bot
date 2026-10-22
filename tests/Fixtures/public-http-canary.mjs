// Dedicated loopback server for SafeProductHttpTest; no application access.
import http from 'node:http';
import { gzipSync } from 'node:zlib';
const server = http.createServer((req, res) => {
    if (req.url === '/oversized-header') return res.writeHead(200, { 'Content-Length': '999999999' }).end('tiny');
    if (req.url === '/gzip') return res.writeHead(200, { 'Content-Encoding': 'gzip' }).end(gzipSync(Buffer.alloc(32_000)));
    if (req.url === '/gzip-wire') {
        const compressed = gzipSync('small');
        const header = Buffer.from(compressed.subarray(0, 10));
        header[3] |= 4; // Legal gzip FEXTRA: large wire body with only five decoded bytes.
        const length = Buffer.alloc(2);
        length.writeUInt16LE(6000);
        return res.writeHead(200, { 'Content-Encoding': 'gzip', 'Transfer-Encoding': 'chunked' })
            .end(Buffer.concat([header, length, Buffer.alloc(6000), compressed.subarray(10)]));
    }
    if (req.url.startsWith('/slow/')) return setTimeout(() => res.end(JSON.stringify({ path: req.url })), 1000);
    if (req.url === '/chunked') {
        res.writeHead(200, { 'Transfer-Encoding': 'chunked' });
        res.write(Buffer.alloc(600));
        return res.end(Buffer.alloc(600));
    }
    res.end(JSON.stringify({ host: req.headers.host, path: req.url }));
});
server.listen(0, '127.0.0.1', () => process.stdout.write(String(server.address().port) + '\n'));
