// The desktop panel the bot is run from: one window that starts and stops it
// through the same scripts/start-ningredy.ps1 the .bat files call, so the
// checks before a start and the cleanup of a previous run exist only once.
import { app, BrowserWindow, dialog, ipcMain, shell } from 'electron';
import { execFile, execFileSync, spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = (name) => path.join(root, 'scripts', name);
const links = { site: 'http://127.0.0.1:8000', admin: 'http://127.0.0.1:8000/admin' };
const STATUS_EVERY_MS = 15_000;

let window = null;
let bot = null; // { mode, child, startedAt }

// Two panels would be two bots on one database; the second one only brings
// the first back to the front.
if (!app.requestSingleInstanceLock()) {
    app.quit();
}
app.on('second-instance', () => {
    if (window?.isMinimized()) window.restore();
    window?.focus();
});

const send = (channel, payload) => window?.webContents.send(channel, payload);
const state = () => (bot ? { mode: bot.mode, startedAt: bot.startedAt } : { mode: null, startedAt: null });

function forwardLines(stream) {
    let rest = '';
    stream.setEncoding('utf8');
    stream.on('data', (chunk) => {
        const lines = (rest + chunk).split(/\r?\n/);
        rest = lines.pop();
        lines.forEach((line) => line.trim() !== '' && send('log', line));
    });
}

function start(mode) {
    if (bot || !['bot', 'site'].includes(mode)) return state();
    const args = ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script('start-ningredy.ps1'), '-NoPause'];
    if (mode === 'site') args.push('-WithoutTelegram');
    const child = spawn('powershell.exe', args, { cwd: root, windowsHide: true });
    bot = { mode, child, startedAt: Date.now() };
    forwardLines(child.stdout);
    forwardLines(child.stderr);
    child.on('exit', (code) => {
        if (bot?.child === child) bot = null;
        send('log', `— остановлено${code ? `, код ${code}` : ''}`);
        send('state', state());
        refreshStatus();
    });
    send('state', state());

    return state();
}

// Killing the tree leaves nothing for a normal exit, but concurrently can
// orphan a grandchild; the launcher's own sweep ends whatever is left.
function stop() {
    if (!bot) return Promise.resolve(state());
    const { child } = bot;
    send('log', '— останавливаю…');

    return new Promise((resolve) => {
        execFile('taskkill', ['/PID', String(child.pid), '/T', '/F'], { windowsHide: true }, () => {
            execFile('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script('clear-leftover-processes.ps1')],
                { cwd: root, windowsHide: true }, () => resolve(state()));
        });
    });
}

let statusRunning = false;
function refreshStatus() {
    if (statusRunning) return;
    statusRunning = true;
    execFile('php', ['artisan', 'bot:status'], { cwd: root, windowsHide: true, timeout: 30_000 }, (error, stdout) => {
        statusRunning = false;
        try {
            send('status', JSON.parse(stdout));
        } catch {
            send('status', { error: (error?.message || stdout || '').trim().split('\n')[0] || 'Нет ответа' });
        }
    });
}

ipcMain.handle('start', (_event, mode) => start(mode));
ipcMain.handle('stop', () => stop());
ipcMain.handle('state', () => state());
ipcMain.handle('refresh', () => refreshStatus());
ipcMain.handle('open', (_event, target) => links[target] && shell.openExternal(links[target]));

app.whenReady().then(() => {
    window = new BrowserWindow({
        width: 880,
        height: 640,
        minWidth: 640,
        minHeight: 480,
        title: 'Ningredy',
        icon: path.join(root, 'desktop', 'icon.ico'),
        backgroundColor: '#080a10',
        titleBarStyle: 'hidden',
        titleBarOverlay: { color: '#080a10', symbolColor: '#8c93a7', height: 36 },
        webPreferences: { preload: path.join(root, 'desktop', 'preload.cjs'), contextIsolation: true, sandbox: true },
    });
    window.removeMenu();
    window.loadFile(path.join(root, 'desktop', 'index.html'));
    window.webContents.on('did-finish-load', refreshStatus);
    setInterval(refreshStatus, STATUS_EVERY_MS);

    // Closing the window is how the bot has always been stopped, so a running
    // bot is never stopped by it without saying so.
    window.on('close', (event) => {
        if (!bot) return;
        event.preventDefault();
        dialog.showMessageBox(window, {
            type: 'question',
            title: 'Ningredy',
            message: bot.mode === 'bot' ? 'Бот работает' : 'Сайт работает',
            detail: 'Если закрыть окно, он остановится.',
            buttons: ['Остановить и закрыть', 'Отмена'],
            defaultId: 1,
            cancelId: 1,
        }).then(({ response }) => {
            if (response === 0) stop().then(() => window.destroy());
        });
    });
});

app.on('window-all-closed', () => app.quit());

// Shutdown or logoff does not wait for the dialog above.
app.on('will-quit', () => {
    if (bot) {
        try {
            execFileSync('taskkill', ['/PID', String(bot.child.pid), '/T', '/F'], { windowsHide: true });
        } catch {
            // Already gone.
        }
    }
});
