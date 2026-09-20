// Test transport only. The production CLI, public-URL guard, recipe execution,
// collection, probing and JSON output run unchanged in a real Chromium process.
import { chromium } from 'playwright-core';
const count = Number(process.env.FIXTURE_GALLERY_COUNT || 7);
const mode = process.env.FIXTURE_GALLERY_MODE || 'slider';
const media = `<div id="media"><img id="active" src="https://8.8.8.8/180x120/0.jpg">
    <span id="position">0</span><button id="go">→</button><button id="zoom">Zoom</button><div id="strip"></div>
    ${Array.from({ length: count }, (_, i) => `<img class="thumb" src="https://8.8.8.8/180x120/${i}.jpg">`).join('')}</div>
    <script>let n=0; const show=(i)=>{ n=i; active.src='https://8.8.8.8/180x120/'+n+'.jpg'; position.textContent=n; };
    go.onclick=()=>{ show((n+1)%${count}); if('${mode}'==='finite' && n===${count - 1}) go.disabled=true; };
    const add=()=>{const i=strip.children.length; if(i>=${count})return; const b=document.createElement('button');b.className='item';b.textContent=i;
    b.onclick=()=>{show(i);add()};strip.append(b)}; if('${mode}'==='growing'){add();add()}
    zoom.onclick=()=>{active.src='https://8.8.8.8/1600x1200/'+n+'.jpg'};</script>`;
const staticMedia = `<div id="media">${Array.from({ length: count }, (_, i) => `<img src="https://8.8.8.8/1600x1200/${i}.jpg">`).join('')}</div>`;
const relativeMedia = `<base href="https://8.8.8.8/media-assets/"><div id="media">${Array.from({ length: count }, (_, i) =>
    `<img src="https://8.8.8.8/180x120/${i}.jpg" data-full="1600x1200/${i}.jpg">`).join('')}</div>`;
const openedFrame = `<button id="launch"><img src="https://8.8.8.8/180x120/0.jpg"></button>
    <script>launch.onclick=()=>{const f=document.createElement('iframe');f.id='viewer';f.src='https://8.8.8.8/viewer';
    f.style='width:1300px;height:950px';document.body.append(f);launch.remove();};</script>`;
const product = `<html><head><title>Fixture laptop</title><link rel="canonical" href="https://8.8.8.8/product">
    <script type="application/ld+json">{"@type":"Product","name":"Fixture laptop","sku":"TEST123"}</script></head>
    <body>${['frame', 'nested', 'relative-frame'].includes(mode) ? `<iframe id="viewer" src="https://8.8.8.8/${mode === 'nested' ? 'outer' : 'viewer'}" style="width:1300px;height:950px"></iframe>` : mode === 'opened-frame' ? openedFrame : mode === 'static' ? staticMedia : media}</body></html>`;
const launch = chromium.launch.bind(chromium);
chromium.launch = async (options) => {
    const browser = await launch({ ...options, channel: undefined, headless: true });
    const newContext = browser.newContext.bind(browser);
    browser.newContext = async (options) => {
        const context = await newContext(options);
        const newPage = context.newPage.bind(context);
        context.newPage = async () => {
            const page = await newPage();
            const route = page.route.bind(page);
            page.route = (pattern, handler) => route(pattern, (realRoute, request) => handler(new Proxy(realRoute, {
                get(target, key) {
                    if (key === 'continue') return async () => {
                        const url = new URL(request.url());
                        if (url.hostname !== '8.8.8.8') throw new Error('Unexpected fixture request: ' + url.href);
                        if (url.pathname.endsWith('.jpg')) {
                            if (mode === 'broken' && url.pathname === '/1600x1200/2.jpg') {
                                return realRoute.fulfill({ status: 503, body: '' });
                            }
                            const size = url.pathname.includes('1600x1200') ? 1600 : 180;
                            await new Promise((resolve) => setTimeout(resolve, 100));
                            return realRoute.fulfill({ contentType: 'image/svg+xml', body:
                                `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"><rect width="100%" height="100%" fill="red"/></svg>` });
                        }
                        return realRoute.fulfill({ contentType: 'text/html', body: url.pathname === '/viewer' ? '<link rel="canonical" href="https://8.8.8.8/viewer"><meta property="og:title" content="Photo viewer">' + (mode === 'relative-frame' ? relativeMedia : media)
                            : url.pathname === '/outer' ? '<iframe id="inner" src="https://8.8.8.8/viewer" style="width:1200px;height:850px"></iframe>' : product });
                    };
                    const value = Reflect.get(target, key);
                    return typeof value === 'function' ? value.bind(target) : value;
                },
            }), request));
            return page;
        };
        return context;
    };
    return browser;
};
