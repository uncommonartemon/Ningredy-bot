// The panel page. Everything it can do goes through window.ningredy (preload.cjs).
const api = window.ningredy;
const $ = (id) => document.getElementById(id);
const log = $('log');
const LOG_LINES = 1500;
let startedAt = null;

function renderState({ mode, startedAt: since }) {
    startedAt = since;
    const running = mode !== null;
    document.body.classList.toggle('running', running);
    $('state').classList.toggle('on', running);
    $('state-label').textContent = running ? (mode === 'bot' ? 'Бот работает' : 'Сайт работает') : 'Остановлен';
    $('start-bot').hidden = running;
    $('start-site').hidden = running;
    $('stop').hidden = !running;
    $('stop').disabled = false;
    tick();
}

function tick() {
    if (startedAt === null) {
        $('clock').textContent = '';
        return;
    }
    const seconds = Math.floor((Date.now() - startedAt) / 1000);
    $('clock').textContent = [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
        .map((part) => String(part).padStart(2, '0')).join(':');
}

function row(label, text, state = '', hint = '') {
    const item = document.createElement('div');
    item.className = `check ${state}`;
    const term = document.createElement('dt');
    term.textContent = label;
    const value = document.createElement('dd');
    value.textContent = text;
    if (hint) {
        const note = document.createElement('span');
        note.className = 'hint';
        note.textContent = hint;
        value.append(note);
    }
    item.append(term, value);

    return item;
}

function renderStatus(status) {
    const rows = status.error
        ? [row('Состояние', `Не удалось узнать: ${status.error}`, 'down')]
        : status.checks.map((check) => row(check.label, check.detail, check.state, check.state === 'up' ? '' : check.hint));
    if (status.active) {
        rows.push(row('Сейчас', `#${status.active.id} «${status.active.text}» · ${status.active.minutes} мин`, 'up'));
    }
    $('checks').replaceChildren(...rows);
}

// concurrently prefixes each process's lines with its name: "[web] ...".
function appendLine(line) {
    const pinned = log.scrollTop + log.clientHeight >= log.scrollHeight - 8;
    const entry = document.createElement('div');
    const named = line.match(/^\[([\w-]+)\]\s?(.*)$/);
    if (named) {
        const name = document.createElement('span');
        name.className = `name ${named[1]}`;
        name.textContent = named[1].padEnd(10, ' ');
        entry.append(name, named[2]);
    } else {
        entry.textContent = line;
        entry.className = /\[X\]|FAIL|ERROR|Exception/.test(line) ? 'problem' : line.startsWith('—') ? 'note' : '';
    }
    log.append(entry);
    while (log.childElementCount > LOG_LINES) log.firstElementChild.remove();
    if (pinned) log.scrollTop = log.scrollHeight;
}

$('start-bot').addEventListener('click', () => api.start('bot').then(renderState));
$('start-site').addEventListener('click', () => api.start('site').then(renderState));
$('stop').addEventListener('click', () => {
    $('stop').disabled = true;
    api.stop();
});
document.querySelectorAll('[data-open]').forEach((link) => link.addEventListener('click', () => api.open(link.dataset.open)));

api.on('state', renderState);
api.on('status', renderStatus);
api.on('log', appendLine);
api.state().then(renderState);
setInterval(tick, 1000);
