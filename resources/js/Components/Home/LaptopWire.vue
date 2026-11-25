<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { laptopLines, project } from '../../laptopWire';

// The canvas behind the start page. The page owns the pose (it ties it to the scroll) and
// calls render() when the pose changed; nothing is redrawn while nothing moves.
const canvas = ref(null);
const pose = {
    open: 0, explode: 0, yaw: -0.6, pitch: 0.34, x: 0, y: 0, scale: 1, alpha: 1,
    // The entrance fades the drawing in and the last scene closes the lid; both are kept
    // apart from the values the scroll writes, so the two never fight over one number.
    fade: 1, close: 0,
    // A small turn after the pointer, added on top of the scroll's pose.
    lookX: 0, lookY: 0,
};
let context;
let width = 0;
let height = 0;
let ratio = 1;
let queued = false;
let observer;

const ACCENT = '128, 255, 219';
const LINE = '214, 220, 236';

const draw = () => {
    queued = false;
    if (!context) return;
    context.clearRect(0, 0, width, height);
    const alpha = pose.alpha * pose.fade;
    if (alpha <= 0.01) return;
    const view = {
        yaw: pose.yaw + pose.lookX * 0.12, pitch: pose.pitch + pose.lookY * 0.08,
        size: Math.min(width, height * 1.5) * 0.17 * pose.scale,
        cx: width * (0.5 + pose.x), cy: height * (0.54 + pose.y),
    };
    const { segments, labels } = laptopLines(pose.open * (1 - pose.close), pose.explode);
    context.lineWidth = ratio;
    context.lineCap = 'round';
    for (const tone of ['line', 'accent']) {
        const color = tone === 'accent' ? ACCENT : LINE;
        for (const segment of segments) {
            if (segment.tone !== tone || segment.alpha <= 0.02) continue;
            const [ax, ay, depthA] = project(segment.a, view);
            const [bx, by, depthB] = project(segment.b, view);
            // Lines farther from the eye are fainter: that alone gives the drawing its depth.
            const depth = 1 - Math.max(0, Math.min(1, ((depthA + depthB) / 2 + 2.2) / 4.4)) * 0.62;
            context.strokeStyle = `rgba(${color}, ${(segment.alpha * depth * alpha * (tone === 'accent' ? 0.9 : 0.5)).toFixed(3)})`;
            context.beginPath();
            context.moveTo(ax, ay);
            context.lineTo(bx, by);
            context.stroke();
        }
    }
    context.font = `600 ${11 * ratio}px "Manrope Variable", sans-serif`;
    context.textBaseline = 'middle';
    for (const label of labels) {
        if (label.alpha <= 0.02) continue;
        const [x, y] = project(label.at, view);
        context.fillStyle = `rgba(${ACCENT}, ${(label.alpha * alpha).toFixed(3)})`;
        context.fillText(label.text, x + 8 * ratio, y - 12 * ratio);
    }
};

const render = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(draw);
};

const resize = () => {
    const element = canvas.value;
    if (!element) return;
    // Sharp on dense screens, but never more than twice the pixels: it is a background.
    ratio = Math.min(window.devicePixelRatio || 1, 2);
    width = Math.round(element.clientWidth * ratio);
    height = Math.round(element.clientHeight * ratio);
    element.width = width;
    element.height = height;
    render();
};

onMounted(() => {
    context = canvas.value.getContext('2d');
    observer = new ResizeObserver(resize);
    observer.observe(canvas.value);
    resize();
});
onBeforeUnmount(() => { observer?.disconnect(); context = undefined; });

defineExpose({ pose, render });
</script>

<template>
    <canvas ref="canvas" class="laptop-wire" aria-hidden="true"></canvas>
</template>
