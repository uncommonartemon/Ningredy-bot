// Deterministic process-protocol fixture: no browser, network or model calls.
const control = process.argv[2]?.endsWith('/control');
process.stdout.write(JSON.stringify({
    images: [],
    product_page_url: control ? 'https://shop.example/control' : 'https://shop.example/model-008it',
    product_identity: { name: 'MODEL-008IT', sku: 'MODEL-008IT' },
    scout: {
        title: 'MODEL-008IT',
        specification_text: 'Actual product SKU: MODEL-008IT',
        fragments: ['<main><h1>MODEL-008IT</h1></main>'],
        access_gate: false,
        rate_limited: false,
    },
    diagnostics: {},
}));
