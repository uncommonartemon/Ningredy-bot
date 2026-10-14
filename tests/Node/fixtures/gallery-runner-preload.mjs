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
const counterLabels = {
    'static-counter-specs': 'Portable laptop 2880x1620/ 16GB/ 512GB SSD',
    'static-counter-invalid-ordinal': 'Image 20 / 8',
    'static-counter-zero': 'Image 0 / 16',
    'static-counter-unit': 'Storage 1 / 16GB',
    'static-counter-unicode-unit': 'Storage 1 / 16ГБ',
    'static-counter-slash': 'Image {index} / 8',
    'static-counter-of': 'Image {index} of 8',
};
const counterLabel = (index) => (counterLabels[mode] || '').replace('{index}', String(index + 1));
const staticMedia = `<div id="media" ${Object.hasOwn(counterLabels, mode) ? `class="product-gallery" data-image-count="${count}"` : ''}>${Array.from({ length: count }, (_, i) => `<img alt="${counterLabel(i)}" src="https://8.8.8.8/1600x1200/${i}.jpg">`).join('')}
    ${['static-rendition', 'static-small-failed-original'].includes(mode) ? '<img src="https://8.8.8.8/1600x1200/0.jpg?width=900">' : ''}
    ${mode === 'static-duplicate-thumbnails' ? [200, 300].map(width => `<ul class="thumbnail-slider">${Array.from({ length: count }, (_, i) => `<li><button><img src="https://8.8.8.8/1600x1200/${i}.jpg?width=${width}"></button></li>`).join('')}</ul>`).join('') : ''}</div>`;
// Originals only enter the selected DOM after each element is scrolled into
// view. A fixed list of four scroll addresses cannot load a nine-frame page.
const lazyMedia = `<div id="media" data-image-count="${count}">${Array.from({ length: count }, (_, i) =>
    `<img class="lazy-frame" style="display:block;width:400px;height:300px;margin-bottom:1000px" src="https://8.8.8.8/180x120/${i}.jpg">`).join('')}</div>
    <script>const lazyObserver=new IntersectionObserver(entries=>{for(const entry of entries){if(!entry.isIntersecting)continue;
    const img=entry.target; img.src=img.src.replace('/180x120/','/1600x1200/');lazyObserver.unobserve(img);
    if('${mode}'==='lazy-scroll-shrinking')img.classList.remove('lazy-frame');
    }});document.querySelectorAll('.lazy-frame').forEach(img=>lazyObserver.observe(img));</script>`;
const growingLazyMedia = `<div id="media"></div><script>let added=0;
    const lazyObserver=new IntersectionObserver(entries=>{for(const entry of entries){if(!entry.isIntersecting)continue;
    const img=entry.target;img.src=img.src.replace('/180x120/','/1600x1200/');lazyObserver.unobserve(img);appendFrame();}});
    function appendFrame(){if(added>=${count})return;const img=document.createElement('img');img.className='lazy-frame';
    img.style='display:block;width:400px;height:300px;margin-bottom:1000px';img.src='https://8.8.8.8/180x120/'+added+++'.jpg';
    img.onclick=()=>{img.remove()};media.append(img);lazyObserver.observe(img);}appendFrame();</script>`;
const zoomLazyMedia = `<div id="media"><button id="zoom" style="position:fixed;top:10px;right:10px;z-index:99">Zoom</button>
    ${Array.from({ length: count }, (_, i) => `<img class="lazy-frame" style="display:block;width:400px;height:300px;margin-bottom:1000px" src="https://8.8.8.8/180x120/${i}.jpg">`).join('')}</div>
    <script>let currentImage=null;const lazyObserver=new IntersectionObserver(entries=>{for(const entry of entries){
    if(entry.isIntersecting)currentImage=entry.target;}});document.querySelectorAll('.lazy-frame').forEach(img=>lazyObserver.observe(img));
    zoom.onclick=()=>{if(currentImage)currentImage.src=currentImage.src.replace('/180x120/','/1600x1200/');};</script>`;
const nativeLazyMedia = `<div id="media">${Array.from({ length: count }, (_, i) =>
    `<img class="lazy-frame" loading="lazy" style="display:block;width:400px;height:300px;margin-bottom:2500px" src="https://8.8.8.8/1600x1200/${i}.jpg">`).join('')}</div>`;
const relativeMedia = `<base href="https://8.8.8.8/media-assets/"><div id="media">${Array.from({ length: count }, (_, i) =>
    `<img src="https://8.8.8.8/180x120/${i}.jpg" data-full="1600x1200/${i}.jpg">`).join('')}</div>`;
const digestMedia = `<div id="media" data-image-count="${count}">${Array.from({ length: count }, (_, i) => {
    const url = `https://8.8.8.8/images/a${String(i).padStart(31, '0')}`;
    const srcset = [256, 384, 640, 750, 828, 1080, 1200].map(x => `${url}?version=0&x=${x}&quality=75 ${x}w`).join(', ');
    return `<img src="${url}?version=0&x=750&quality=75" srcset="${srcset}">`;
}).join('')}</div>`;
const openedFrame = `<button id="launch"><img src="https://8.8.8.8/180x120/0.jpg"></button>
    <script>launch.onclick=()=>{const f=document.createElement('iframe');f.id='viewer';f.src='https://8.8.8.8/viewer';
    f.style='width:1300px;height:950px';document.body.append(f);launch.remove();};</script>`;
const consent = ['shadow-consent', 'blocked-overlay'].includes(mode) ? `<script>setTimeout(()=>{
    const host=document.createElement('consent-widget');document.body.append(host);
    const root=host.attachShadow({mode:'open'});
    root.innerHTML='<div role="dialog" style="position:fixed;inset:0;background:white;z-index:99999"><h2>Privacy settings</h2>${mode === 'shadow-consent' ? '<button id="consent-ok">Accept all</button>' : '<p>Media is covered by a non-dismissible obstacle</p>'}</div>';
    const close=root.querySelector('button');if(close)close.onclick=()=>host.remove();
},500)</script>` : '';
const canvasMedia = `<div id="media"><canvas width="800" height="600"></canvas>
    <button id="mixedmedia_fullScreenButton">⛶</button><button id="addToCart">Buy</button></div>
    <script>const reveal=()=>{media.innerHTML=${JSON.stringify(staticMedia)}};
    mixedmedia_fullScreenButton.onclick=reveal;addToCart.onclick=reveal;</script>`;
const product = `<html><head>${consent}<title>Fixture laptop</title><link rel="canonical" href="https://8.8.8.8/product">
    <script type="application/ld+json">{"@type":"Product","name":"Fixture laptop","sku":"TEST123"}</script></head>
    <body>${['frame', 'nested', 'relative-frame'].includes(mode) ? `<iframe id="viewer" src="https://8.8.8.8/${mode === 'nested' ? 'outer' : 'viewer'}" style="width:1300px;height:950px"></iframe>` : mode === 'canvas-fullscreen' ? canvasMedia : mode === 'opened-frame' ? openedFrame : mode === 'lazy-scroll-native' ? nativeLazyMedia : mode === 'lazy-scroll-zoom' ? zoomLazyMedia : mode === 'lazy-scroll-growing' ? growingLazyMedia : mode.startsWith('lazy-scroll') ? lazyMedia : mode === 'static-digest' ? digestMedia : mode.startsWith('static') ? staticMedia : media}</body></html>`;
const launch = chromium.launch.bind(chromium);
const tabPage = (gallery) => `<html><head><title>Fixture laptop${gallery ? ' - slim sharp stylish' : ''}</title>
    <script type="application/ld+json">${JSON.stringify({ '@type': 'Product',
        name: gallery ? 'Fixture laptop - slim sharp stylish' : 'Fixture laptop',
        ...(['tab-title', 'tab-inline-title'].includes(mode) ? {} : { sku: gallery && mode === 'tab-other-sku' ? 'TEST999' : 'TEST123' }),
    })}</script>${gallery && mode === 'tab-inline-title' ? `<script>history.replaceState({}, '', '/shop/model/specification');</script>` : ''}</head><body>${gallery ? media : '<a id="gallery-tab" href="/shop/model/gallery">Gallery</a>'}</body></html>`;
chromium.launch = async (options) => {
    const browser = await launch({ ...options, channel: undefined, headless: true });
    const newContext = browser.newContext.bind(browser);
    browser.newContext = async (options) => {
        const context = await newContext(options);
        const route = context.route.bind(context);
        context.route = (pattern, handler) => route(pattern, (realRoute, request) => handler(new Proxy(realRoute, {
                get(target, key) {
                    if (key === 'continue') return async () => {
                        const url = new URL(request.url());
                        if (url.hostname !== '8.8.8.8') throw new Error('Unexpected fixture request: ' + url.href);
                        if (mode === 'static-digest' && /^\/images\/[a-f0-9]{32}$/.test(url.pathname)) {
                            const size = Number(url.searchParams.get('x'));
                            return realRoute.fulfill({ contentType: 'image/svg+xml', body:
                                `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"><rect width="100%" height="100%" fill="red"/></svg>` });
                        }
                        if (url.pathname.endsWith('.jpg')) {
                            if (mode === 'static-blocked'
                                || (mode === 'static-partial' && url.pathname.endsWith('/2.jpg'))
                                || (['static-rendition', 'static-small-failed-original'].includes(mode) && url.searchParams.has('width'))) {
                                return realRoute.fulfill({ status: 403, contentType: 'text/html', body: 'Image temporarily unavailable' });
                            }
                            if (['broken', 'lazy-scroll-broken'].includes(mode) && url.pathname === '/1600x1200/2.jpg') {
                                return realRoute.fulfill({ status: 503, body: '' });
                            }
                            const size = mode.startsWith('static-small') ? 180 : url.pathname.includes('1600x1200') ? 1600 : 180;
                            await new Promise((resolve) => setTimeout(resolve, 100));
                            return realRoute.fulfill({ contentType: 'image/svg+xml', body:
                                `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"><rect width="100%" height="100%" fill="red"/></svg>` });
                        }
                        if (mode.startsWith('tab-')) return realRoute.fulfill({ contentType: 'text/html', body: tabPage(url.pathname.endsWith('/gallery')) });
                        return realRoute.fulfill({ contentType: 'text/html', body: url.pathname === '/viewer' ? '<link rel="canonical" href="https://8.8.8.8/viewer"><meta property="og:title" content="Photo viewer">' + (mode === 'relative-frame' ? relativeMedia : media)
                            : url.pathname === '/outer' ? '<iframe id="inner" src="https://8.8.8.8/viewer" style="width:1200px;height:850px"></iframe>' : product });
                    };
                    const value = Reflect.get(target, key);
                    return typeof value === 'function' ? value.bind(target) : value;
                },
            }), request));
        return context;
    };
    return browser;
};
