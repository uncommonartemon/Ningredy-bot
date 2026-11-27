// The row of new products on the start page is an ordinary slider in the page's flow
// (decision of 2026-11-27): the browser scrolls it (a finger swipes it, the keyboard brings a
// focused card into view), the mouse drags it with a short glide after letting go, and a pointer
// resting near an edge moves it slowly toward that side. With reduced motion nothing moves by
// itself: no glide and no edge movement, only dragging, swiping and the keyboard.
const EDGE = 1 / 8; // the outer eighth of the row on each side
const EDGE_SPEED = 2.4; // pixels per frame with the pointer at the very edge
const FRICTION = 0.93; // what is left of the glide's speed after each frame
const THRESHOLD = 6; // pixels a press moves before it is a drag and not a click

export function dragRow(track, { reduced = false, onScroll = () => {} } = {}) {
    let press;
    let swallow = false;
    let velocity = 0;
    let lean = 0;
    let frame = 0;
    let position = 0;
    const end = () => Math.max(0, track.scrollWidth - track.clientWidth);
    const report = () => onScroll({ start: track.scrollLeft <= 1, end: track.scrollLeft >= end() - 1, progress: end() ? track.scrollLeft / end() : 1 });

    const step = () => {
        frame = 0;
        if (press) return;
        // The browser keeps whole pixels; the slow movements are summed here so they still move.
        if (Math.abs(track.scrollLeft - position) > 2) position = track.scrollLeft;
        const speed = Math.abs(velocity) > 0.25 ? velocity : lean;
        velocity = Math.abs(velocity) > 0.25 ? velocity * FRICTION : 0;
        position = Math.min(end(), Math.max(0, position + speed));
        track.scrollLeft = position;
        const stopped = speed === 0 || (speed < 0 && position <= 0) || (speed > 0 && position >= end());
        if (stopped) velocity = 0;
        else frame = requestAnimationFrame(step);
    };
    const run = () => {
        if (frame) return;
        position = track.scrollLeft;
        frame = requestAnimationFrame(step);
    };
    // Anything else that scrolls the row (a button, a wheel, a finger) takes over from the glide and the edge.
    const halt = () => {
        velocity = 0;
        lean = 0;
        cancelAnimationFrame(frame);
        frame = 0;
    };

    const down = (event) => {
        if (event.pointerType !== 'mouse' || event.button !== 0) return;
        halt();
        swallow = false;
        press = { id: event.pointerId, x: event.clientX, scroll: track.scrollLeft, moved: false, lastX: event.clientX, lastAt: event.timeStamp, speed: 0 };
    };
    const move = (event) => {
        if (event.pointerType !== 'mouse') return;
        if (press) {
            const shift = event.clientX - press.x;
            if (!press.moved && Math.abs(shift) < THRESHOLD) return;
            if (!press.moved) {
                press.moved = true;
                track.setPointerCapture(press.id);
                track.classList.add('is-dragging');
            }
            track.scrollLeft = press.scroll - shift;
            const elapsed = Math.max(1, event.timeStamp - press.lastAt);
            press.speed = 0.7 * (((press.lastX - event.clientX) / elapsed) * 16) + 0.3 * press.speed;
            press.lastX = event.clientX;
            press.lastAt = event.timeStamp;
            return;
        }
        if (reduced) return;
        const box = track.getBoundingClientRect();
        const zone = box.width * EDGE;
        const fromLeft = event.clientX - box.left;
        const fromRight = box.right - event.clientX;
        lean = fromLeft < zone ? -EDGE_SPEED * (1 - fromLeft / zone) : fromRight < zone ? EDGE_SPEED * (1 - fromRight / zone) : 0;
        if (lean) run();
    };
    const up = (event) => {
        if (!press || event.pointerId !== press.id) return;
        if (press.moved) {
            // The click that ends a drag opens nothing; a plain click still opens the product.
            swallow = true;
            setTimeout(() => { swallow = false; });
            track.classList.remove('is-dragging');
            if (track.hasPointerCapture(press.id)) track.releasePointerCapture(press.id);
            if (!reduced && event.timeStamp - press.lastAt < 90 && Math.abs(press.speed) > 1) {
                velocity = press.speed;
                run();
            }
        }
        press = undefined;
    };
    const leave = () => { if (!velocity) halt(); else lean = 0; };
    const click = (event) => {
        if (!swallow) return;
        event.preventDefault();
        event.stopPropagation();
        swallow = false;
    };
    // Links and photos are draggable by default; the browser's own drag would take the press.
    const dragstart = (event) => event.preventDefault();
    // The browser leaves a half-visible card where it is when it gets the focus; a card reached
    // by the keyboard is brought whole into view. A click's focus is left alone: moving the row
    // under a pressed pointer would lose the click.
    const focus = (event) => {
        if (!event.target.matches?.(':focus-visible')) return;
        let item = event.target;
        while (item && item.parentElement !== track) item = item.parentElement;
        if (!item) return;
        halt();
        item.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduced ? 'auto' : 'smooth' });
    };

    track.addEventListener('pointerdown', down);
    track.addEventListener('pointermove', move);
    track.addEventListener('pointerup', up);
    track.addEventListener('pointercancel', up);
    track.addEventListener('pointerleave', leave);
    track.addEventListener('wheel', halt, { passive: true });
    track.addEventListener('touchstart', halt, { passive: true });
    track.addEventListener('click', click, true);
    track.addEventListener('dragstart', dragstart);
    track.addEventListener('focusin', focus);
    track.addEventListener('scroll', report, { passive: true });
    report();

    return {
        // One press of the previous or next button: most of a screen of cards.
        page(direction) {
            halt();
            track.scrollBy({ left: direction * track.clientWidth * 0.8, behavior: reduced ? 'auto' : 'smooth' });
        },
        measure: report,
        stop() {
            cancelAnimationFrame(frame);
            track.removeEventListener('pointerdown', down);
            track.removeEventListener('pointermove', move);
            track.removeEventListener('pointerup', up);
            track.removeEventListener('pointercancel', up);
            track.removeEventListener('pointerleave', leave);
            track.removeEventListener('wheel', halt);
            track.removeEventListener('touchstart', halt);
            track.removeEventListener('click', click, true);
            track.removeEventListener('dragstart', dragstart);
            track.removeEventListener('focusin', focus);
            track.removeEventListener('scroll', report);
        },
    };
}
