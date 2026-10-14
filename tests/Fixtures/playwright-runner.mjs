// Protocol fixture: starts a real Node process, never a browser or network request.
import fs from 'node:fs';
import path from 'node:path';

const url = new URL(process.argv[2]);
const mode = url.pathname.slice(1);
const directory = process.env.PRODUCT_GALLERY_TRANSFER_DIR;
const image = 'https://images.example/front.png';
const bytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0S0AAAAASUVORK5CYII=', 'base64');

if (mode === 'invalid') {
    process.stdout.write('not JSON');
} else if (mode === 'crash') {
    process.stderr.write('fixture failure');
    process.exitCode = 1;
} else if (mode === 'timeout') {
    fs.writeFileSync(path.join(directory, 'checkpoint.json'), JSON.stringify({
        images: [image],
        scout: { title: 'Checkpoint', fixture_directory: directory },
        action_trace: [{ phase: 'next', clicked: true }],
    }));
    fs.writeFileSync(path.join(directory, 'page.png'), bytes);
    await new Promise(resolve => setTimeout(resolve, 30000));
} else {
    const file = path.join(directory, 'frame.png');
    fs.writeFileSync(file, bytes);
    process.stdout.write(JSON.stringify({
        images: [image, 'https://images.example/back.png', 'https://images.example/side.png',
            image, 'javascript:alert(1)', 'not-a-url', null],
        screenshot_path: file,
        transferred_images: [{ url: image, path: file, final_url: image }],
        scout: { title: 'Fixture', specification_text: 'RAM 16GB' },
        fixture: {
            directory, url: process.argv[2], limit: process.argv[3],
            recipe: JSON.parse(process.env.PRODUCT_GALLERY_RECIPE),
            focus: process.env.PRODUCT_GALLERY_OBSERVATION_FOCUS,
            host: process.env.PRODUCT_GALLERY_CONFINE_HOST,
            deadline: process.env.PRODUCT_GALLERY_DEADLINE_MS,
            scout: process.env.PRODUCT_GALLERY_SCOUT_ONLY,
            width: process.env.PRODUCT_GALLERY_MINIMUM_WIDTH,
            height: process.env.PRODUCT_GALLERY_MINIMUM_HEIGHT,
            http11: process.env.PRODUCT_IMAGE_DISABLE_HTTP2,
        },
    }));
}
