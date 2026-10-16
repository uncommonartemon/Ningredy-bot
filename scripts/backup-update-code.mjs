import { execFileSync } from 'node:child_process';
import {
    constants, closeSync, existsSync, fstatSync, lstatSync, mkdirSync, openSync,
    readFileSync, readSync, realpathSync, writeFileSync, writeSync,
} from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, isAbsolute, join, parse, relative, resolve, sep } from 'node:path';
import { TextDecoder } from 'node:util';

// This helper only reads the checkout. The caller may reset it only after this
// succeeds AND its separate database/media backup has completed.
const [rootArgument, backupArgument, git, target] = process.argv.slice(2);
const decoder = new TextDecoder('utf-8', { fatal: true });
const maxBuffer = 256 * 1024 * 1024;

try {
    if (!rootArgument || !backupArgument || !git || !/^(?:[a-f\d]{40}|[a-f\d]{64})$/i.test(target ?? '')) {
        throw new Error('Usage: backup-update-code.mjs <project> <existing-backup> <git> <target-commit>');
    }
    const root = resolve(rootArgument);
    const backup = resolve(backupArgument);
    const caseKey = (value) => process.platform === 'win32' ? value.toLowerCase() : value;
    const within = (parent, child) => {
        const difference = relative(parent, child);
        return difference === '' || (!isAbsolute(difference) && difference !== '..' && !difference.startsWith(`..${sep}`));
    };
    if (within(root, backup) || within(backup, root)) throw new Error('The code backup must be outside the checkout.');
    const inspected = new Set();
    function inspectAbsolute(path) {
        const parsed = parse(path);
        let current = parsed.root;
        for (const part of path.slice(parsed.root.length).split(sep).filter(Boolean)) {
            current = join(current, part);
            const info = lstatSync(current);
            if (info.isSymbolicLink()) throw new Error(`Unsafe symbolic link or junction: ${current}`);
            inspected.add(current);
        }
    }
    inspectAbsolute(root);
    inspectAbsolute(backup);
    if (!lstatSync(root).isDirectory() || !lstatSync(backup).isDirectory()) throw new Error('Project and backup must be existing directories.');

    function run(args, options = {}) {
        return execFileSync(git, args, {
            cwd: root, env: { ...process.env, GIT_OPTIONAL_LOCKS: '0' },
            maxBuffer, stdio: ['pipe', 'pipe', 'pipe'], ...options,
        });
    }
    const decode = (bytes) => decoder.decode(bytes);
    const lines = (bytes) => decode(bytes).split('\0').filter(Boolean);
    const repositoryRoot = decode(run(['rev-parse', '--show-toplevel'])).trim();
    if (caseKey(realpathSync(repositoryRoot)) !== caseKey(realpathSync(root))) throw new Error('Project must be the Git checkout root.');
    const before = decode(run(['rev-parse', '--verify', 'HEAD'])).trim();
    const resolvedTarget = decode(run(['rev-parse', '--verify', `${target}^{commit}`])).trim();
    if (resolvedTarget !== target) throw new Error('Target must be the full commit hash.');

    // Validate Windows paths even when tests are executed on another platform.
    const aliases = new Map();
    function checkedPath(path) {
        const parts = path.split('/');
        if (parts.some((part) => !part || part === '.' || part === '..' || /[\\:\x00-\x1f<>"|?*]/.test(part)
            || /[. ]$/.test(part) || /^\.git$/i.test(part) || /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i.test(part))) {
            throw new Error(`Unsafe Git path: ${JSON.stringify(path)}`);
        }
        let prefix = '';
        for (const part of parts) {
            prefix = prefix ? `${prefix}/${part}` : part;
            const key = prefix.toLowerCase();
            if (aliases.has(key) && aliases.get(key) !== prefix) throw new Error(`Case-colliding Git paths: ${prefix}, ${aliases.get(key)}`);
            aliases.set(key, prefix);
        }
        const absolute = resolve(root, ...parts);
        if (!within(root, absolute)) throw new Error(`Path escapes checkout: ${path}`);
        return absolute;
    }
    function entries(args, pattern) {
        return lines(run(args)).map((entry) => {
            const match = pattern.exec(entry);
            if (!match) throw new Error('Cannot parse Git file list.');
            const [, mode, path] = match;
            checkedPath(path);
            if (!['100644', '100755'].includes(mode)) throw new Error(`Unsupported symlink, submodule or file mode ${mode}: ${path}`);
            return path;
        });
    }
    if (run(['ls-files', '--unmerged', '-z']).length) throw new Error('Resolve or abort the existing Git conflict before forcing an update.');
    const index = entries(['ls-files', '--stage', '-z'], /^(\d+) [a-f\d]+ 0\t([\s\S]+)$/);
    const head = entries(['ls-tree', '-r', '-z', 'HEAD'], /^(\d+) blob [a-f\d]+\t([\s\S]+)$/);
    const remote = entries(['ls-tree', '-r', '-z', target], /^(\d+) (?:blob|commit) [a-f\d]+\t([\s\S]+)$/);
    const tracked = new Set([...head, ...index]);
    const untracked = lines(run(['ls-files', '--others', '--exclude-standard', '-z']));
    for (const path of untracked) checkedPath(path);
    const allFiles = [...new Set([...tracked, ...untracked])].sort();

    // The SQLite helper resolves custom and stale-cached database locations.
    // Protect those paths even if their filenames have no .sqlite extension.
    const databasePaths = new Set();
    const databaseManifest = join(backup, 'databases.json');
    if (existsSync(databaseManifest)) {
        const databases = JSON.parse(readFileSync(databaseManifest, 'utf8'));
        if (!Array.isArray(databases) || !databases.length) throw new Error('Invalid database backup manifest.');
        for (const database of databases) {
            if (typeof database.source !== 'string' || !isAbsolute(database.source)) throw new Error('Invalid database source in backup manifest.');
            const source = resolve(database.source);
            for (const suffix of ['', '-wal', '-shm', '-journal']) databasePaths.add(caseKey(`${source}${suffix}`));
        }
    }

    function protectedData(path) {
        const lower = path.toLowerCase();
        if (databasePaths.has(caseKey(checkedPath(path)))) return true;
        if (/^storage\/app\/(?:.*\/)?\.gitignore$/.test(lower) && tracked.has(path)) return false;
        return (/^\.env(?:\.|$)/.test(lower) && lower !== '.env.example')
            || /\.sqlite[^/]*$/.test(lower)
            || /^(?:vendor|node_modules|backups)(?:\/|$)/.test(lower)
            || /^(?:storage\/app|public\/storage)(?:\/|$)/.test(lower);
    }
    for (const path of new Set([...tracked, ...remote])) {
        if (protectedData(path)) throw new Error(`Git must not replace or remove local data: ${path}`);
    }

    function inspectFile(path, missingAllowed) {
        const absolute = checkedPath(path);
        let current = root;
        const parts = path.split('/');
        for (let index = 0; index < parts.length; index++) {
            current = join(current, parts[index]);
            let info;
            try { info = lstatSync(current); } catch (error) {
                if (error.code === 'ENOENT' && missingAllowed) return null;
                throw error;
            }
            if (info.isSymbolicLink()) throw new Error(`Unsafe symbolic link or junction: ${path}`);
            inspected.add(current);
            const last = index === parts.length - 1;
            if (last ? !info.isFile() : !info.isDirectory()) throw new Error(`File/directory collision; move this path before updating: ${current}`);
        }
        return absolute;
    }
    const present = new Map();
    for (const path of allFiles) present.set(path, inspectFile(path, tracked.has(path)));
    const possibleIgnored = [];
    for (const path of remote) {
        if (inspectFile(path, true) && !tracked.has(path)) possibleIgnored.push(path);
    }
    if (possibleIgnored.length) {
        let ignored;
        try {
            ignored = run(['check-ignore', '--no-index', '-z', '--stdin'], { input: Buffer.from(`${possibleIgnored.join('\0')}\0`) });
        } catch (error) {
            if (error.status !== 1) throw error;
            ignored = error.stdout;
        }
        const overwritten = lines(ignored);
        if (overwritten.length) throw new Error(`Update would overwrite ignored local files: ${overwritten.join(', ')}`);
    }
    // Node recognizes normal Windows junctions. Check the native attribute too
    // for other reparse-point types, without interpolating paths into a shell.
    if (process.platform === 'win32') {
        execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
            "$ErrorActionPreference = 'Stop'; [Console]::InputEncoding = [Text.Encoding]::UTF8; $paths = [Console]::In.ReadToEnd() | ConvertFrom-Json; foreach ($path in $paths) { if (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw ('Unsafe reparse point: ' + $path) } }"],
        { input: JSON.stringify([...inspected]), encoding: 'utf8', maxBuffer, stdio: ['pipe', 'pipe', 'pipe'] });
    }

    const destination = join(backup, 'local-code');
    mkdirSync(destination);
    mkdirSync(join(destination, 'files'));
    const save = (name, data) => writeFileSync(join(destination, name), data, { flag: 'wx' });
    save('status.txt', run(['status', '--porcelain=v1', '-z', '--untracked-files=all']));
    save('staged.patch', run(['diff', '--cached', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--']));
    save('unstaged.patch', run(['diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--']));
    const bundle = join(destination, 'repository.bundle');
    if (existsSync(bundle)) throw new Error('Refusing to overwrite an existing Git bundle.');
    run(['bundle', 'create', bundle, '--all', 'HEAD']);
    run(['bundle', 'verify', bundle]);
    const bundleHeads = decode(run(['bundle', 'list-heads', bundle]));
    if (!bundleHeads.split(/\r?\n/).includes(`${before} HEAD`)) throw new Error('Git bundle does not preserve the original HEAD.');

    function hashFile(path) {
        const fd = openSync(path, 'r');
        const buffer = Buffer.allocUnsafe(1024 * 1024);
        const hash = createHash('sha256');
        try {
            let size;
            while ((size = readSync(fd, buffer, 0, buffer.length, null)) > 0) hash.update(buffer.subarray(0, size));
            return hash.digest('hex');
        } finally { closeSync(fd); }
    }
    const manifest = [];
    for (const path of allFiles) {
        const source = present.get(path);
        if (!source) { manifest.push({ path, state: 'deleted', tracked: true }); continue; }
        inspectFile(path, false);
        const output = join(destination, 'files', ...path.split('/'));
        mkdirSync(dirname(output), { recursive: true });
        const inputFd = openSync(source, constants.O_RDONLY | (constants.O_NOFOLLOW ?? 0));
        const original = fstatSync(inputFd);
        if (!original.isFile()) { closeSync(inputFd); throw new Error(`Source is no longer a regular file: ${path}`); }
        let outputFd;
        const hash = createHash('sha256');
        let bytes = 0;
        try {
            outputFd = openSync(output, 'wx', original.mode & 0o777);
            const buffer = Buffer.allocUnsafe(1024 * 1024);
            let size;
            while ((size = readSync(inputFd, buffer, 0, buffer.length, null)) > 0) {
                hash.update(buffer.subarray(0, size));
                let written = 0;
                while (written < size) written += writeSync(outputFd, buffer, written, size - written);
                bytes += size;
            }
            const after = fstatSync(inputFd);
            if (original.size !== bytes || original.size !== after.size || original.mtimeMs !== after.mtimeMs || original.ctimeMs !== after.ctimeMs) {
                throw new Error(`File changed during backup; retry after closing the editor: ${path}`);
            }
        } finally {
            closeSync(inputFd);
            if (outputFd !== undefined) closeSync(outputFd);
        }
        const sha256 = hash.digest('hex');
        if (hashFile(output) !== sha256) throw new Error(`Code backup checksum failed: ${path}`);
        manifest.push({ path, state: 'file', tracked: tracked.has(path), size: bytes, sha256 });
    }
    save('manifest.json', `${JSON.stringify({ version: 1, before, target, files: manifest }, null, 2)}\n`);
    console.log(`Local code and Git history saved: ${destination}`);
} catch (error) {
    console.error(`Code backup refused: ${error.message}`);
    if (error.stderr) console.error(String(error.stderr).trim());
    process.exitCode = 1;
}
