// Whether GitHub has a newer version than this checkout (operator, 2026-11-11: the panel
// checks when it opens and says so on the button). Only .git is touched: `git fetch`
// changes no file of the project, so it is safe while the bot runs.
import { execFile } from 'node:child_process';

const runGit = (cwd, args) => new Promise((resolve) => {
    // A private repository must never stop the panel on a password prompt.
    execFile('git', args, { cwd, windowsHide: true, timeout: 60_000, env: { ...process.env, GIT_TERMINAL_PROMPT: '0' } },
        (error, stdout) => resolve({ ok: !error, out: String(stdout ?? '').trim() }));
});

/**
 * @returns {Promise<{state: 'available', count: number, date: string}
 *   | {state: 'current'} | {state: 'blocked' | 'unknown', reason: string}>}
 */
export async function checkForUpdate(root, git = runGit) {
    const branch = await git(root, ['branch', '--show-current']);
    if (!branch.ok) return { state: 'unknown', reason: 'Git не отвечает: обновления не проверены.' };
    // The updater only moves main forward; on anything else it refuses, so the button says why.
    if (branch.out !== 'main') {
        return { state: 'blocked', reason: branch.out === ''
            ? 'Открыта отдельная версия, а не ветка main: обновление недоступно.'
            : `Открыта ветка ${branch.out}, а обновления идут в main: обновление недоступно.` };
    }
    const fetched = await git(root, ['fetch', '--quiet', 'origin', 'main']);
    if (!fetched.ok) return { state: 'unknown', reason: 'Нет связи с GitHub: обновления не проверены.' };
    const behind = await git(root, ['rev-list', '--count', 'HEAD..FETCH_HEAD']);
    const count = Number.parseInt(behind.out, 10);
    if (!behind.ok || Number.isNaN(count)) return { state: 'unknown', reason: 'Не удалось сравнить версии: обновления не проверены.' };
    if (count === 0) return { state: 'current' };
    const latest = await git(root, ['log', '-1', '--format=%cd', '--date=format:%d.%m.%Y %H:%M', 'FETCH_HEAD']);

    return { state: 'available', count, date: latest.ok ? latest.out : '' };
}
