param([switch]$NoPause)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root
$backup = $null
$backupComplete = $false
$transcribing = $false
$locked = $false
$mutex = $null

function Find-Application {
    param([string[]]$Names, [string]$Help)

    foreach ($name in $Names) {
        $command = Get-Command $name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($command) { return $command.Source }
    }
    throw $Help
}

function Invoke-Checked {
    param([string]$File, [string[]]$Arguments, [string]$Label, [switch]$Capture)

    if ($Label) { Write-Host "  $Label" -ForegroundColor Cyan }
    if ($Capture) {
        $result = @(& $File @Arguments)
    } else {
        & $File @Arguments | Out-Host
    }
    if ($LASTEXITCODE -ne 0) {
        throw "$Label — команда завершилась с кодом $LASTEXITCODE. Смотрите сообщение выше."
    }
    if ($Capture) { return ($result -join "`n").Trim() }
}

function Assert-BotStopped {
    # Relative artisan commands cannot reliably be assigned to a checkout on
    # Windows. Refuse ambiguous running processes; never kill another project.
    $processes = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
        $_.ProcessId -ne $PID -and (
            ($_.Name -match '^php(?:-cgi)?\.exe$' -and $_.CommandLine -match 'artisan["'']?\s+(serve|queue:work|queue:listen|schedule:work|schedule:run|telegram:sync-ngrok)\b') -or
            ($_.Name -match '^(powershell|pwsh)\.exe$' -and $_.CommandLine -match '[\\/]start-ningredy\.ps1') -or
            ($_.Name -eq 'node.exe' -and $_.CommandLine -match '(browser-server\.mjs|[\\/]vite[\\/])')
        )
    })
    if ($processes.Count -gt 0) {
        $ids = ($processes | ForEach-Object { $_.ProcessId }) -join ', '
        throw "Обнаружен работающий бот, Laravel или Vite (PID: $ids). Дождитесь окончания задач и закройте окно запуска. Если оно уже закрыто, перезагрузите Windows и повторите обновление."
    }
}

try {
    Write-Host "`n  NINGREDY — обновление и запуск`n" -ForegroundColor Cyan
    if (-not (Test-Path -LiteralPath (Join-Path $root 'artisan'))) {
        throw 'Файл обновления должен находиться в установленном проекте рядом с artisan.'
    }

    $hash = [System.Security.Cryptography.SHA256]::Create()
    try {
        $key = [BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant()))).Replace('-', '')
    } finally { $hash.Dispose() }
    $mutex = New-Object System.Threading.Mutex($false, "Local\NingredyUpdate-$key")
    try { $locked = $mutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $locked = $true }
    if (-not $locked) { throw 'Обновление уже запущено в другом окне.' }

    $logDirectory = Join-Path $root 'storage\logs'
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
    $log = Join-Path $logDirectory ('update-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
    Start-Transcript -LiteralPath $log | Out-Null
    $transcribing = $true

    $git = Find-Application @('git.exe', 'git') 'Не найден Git. Установите Git for Windows и запустите файл снова.'
    $php = Find-Application @('php.exe', 'php') 'Не найден PHP. Установите PHP 8.4.1 или новее в ветке 8.x и добавьте его в PATH.'
    $node = Find-Application @('node.exe', 'node') 'Не найден Node.js. Установите Node.js 22.12 или новее.'
    $npm = Find-Application @('npm.cmd', 'npm') 'Не найден npm. Переустановите Node.js вместе с npm.'
    $composer = Find-Application @('composer.bat', 'composer.cmd', 'composer.exe', 'composer') 'Не найден Composer. Установите Composer для вашего PHP.'
    $null = Find-Application @('ngrok.exe', 'ngrok') 'Не найден ngrok. Установите его и добавьте в PATH, как при первом запуске бота.'
    Invoke-Checked $php @('-r', 'exit(PHP_VERSION_ID >= 80401 && PHP_MAJOR_VERSION === 8 ? 0 : 1);') 'Проверяю PHP: требуется 8.4.1+ (8.x)'
    $nodeVersion = Invoke-Checked $node @('--version') 'Проверяю Node.js' -Capture
    if ($nodeVersion -notmatch '^v(\d+)\.(\d+)\.' -or -not (
        ([int]$Matches[1] -eq 20 -and [int]$Matches[2] -ge 19) -or
        ([int]$Matches[1] -eq 22 -and [int]$Matches[2] -ge 12) -or [int]$Matches[1] -gt 22
    )) { throw "Установлен Node.js $nodeVersion. Для обновления установите Node.js 22.12 или новее." }

    foreach ($required in @('.env', 'vendor\autoload.php', 'composer.lock', 'package-lock.json')) {
        if (-not (Test-Path -LiteralPath (Join-Path $root $required))) {
            throw "Не найден $required. Этот файл обновляет уже установленный проект; сначала требуется первоначальная настройка."
        }
    }
    Assert-BotStopped

    $branch = Invoke-Checked $git @('branch', '--show-current') 'Проверяю ветку Git' -Capture
    if ($branch -ne 'main') { throw "Открыта ветка '$branch', а обновление рассчитано на main. Автоматическое переключение отменено, чтобы сохранить вашу работу." }
    $changes = Invoke-Checked $git @('status', '--porcelain', '--untracked-files=normal') 'Проверяю локальные изменения' -Capture
    if ($changes) {
        Write-Host $changes -ForegroundColor Yellow
        throw 'В папке есть локальные изменения. Они сохранены; автоматическое обновление остановлено. Пришлите список файлов выше, чтобы перенести изменения без потери работы.'
    }
    $before = Invoke-Checked $git @('rev-parse', 'HEAD') '' -Capture
    Invoke-Checked $git @('fetch', 'origin', 'main') '1/6 — Получаю обновление из GitHub'
    $target = Invoke-Checked $git @('rev-parse', 'FETCH_HEAD') '' -Capture
    & $git merge-base --is-ancestor $before $target
    if ($LASTEXITCODE -ne 0) {
        throw 'Локальная история отличается от GitHub. Автоматическое слияние отменено; ваши коммиты сохранены.'
    }

    Assert-BotStopped
    Write-Host '  2/6 — Сохраняю настройки, базу и загруженные файлы...' -ForegroundColor Cyan
    $backupName = (Split-Path $root -Leaf) + '-backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 6)
    $backup = Join-Path (Split-Path $root -Parent) $backupName
    New-Item -ItemType Directory -Path $backup | Out-Null
    Copy-Item -LiteralPath (Join-Path $root '.env') -Destination (Join-Path $backup '.env')
    Invoke-Checked $php @((Join-Path $PSScriptRoot 'backup-before-update.php'), $root, $backup) 'Снимок SQLite с проверкой целостности'
    if (Test-Path -LiteralPath (Join-Path $root 'storage\app')) {
        Copy-Item -LiteralPath (Join-Path $root 'storage\app') -Destination (Join-Path $backup 'storage-app') -Recurse
    }
    $before | Set-Content -LiteralPath (Join-Path $backup 'git-commit-before.txt') -Encoding ASCII
    'Backup completed.' | Set-Content -LiteralPath (Join-Path $backup 'COMPLETE.txt') -Encoding ASCII
    $backupComplete = $true
    Write-Host "  Резервная копия: $backup" -ForegroundColor Green

    Assert-BotStopped
    Invoke-Checked $git @('-c', 'merge.autostash=false', 'merge', '--ff-only', $target) '3/6 — Обновляю код'
    # Do not run Composer's Laravel hooks against the old cached configuration
    # or package manifest. Artisan is available after dependencies are installed.
    Invoke-Checked $composer @('install', '--no-interaction', '--prefer-dist', '--no-scripts') '4/6 — Устанавливаю PHP-зависимости'
    # Old manifests can name providers removed by composer install, preventing
    # even config:clear from booting. These are generated files, not user data.
    foreach ($cacheName in @('config.php', 'packages.php', 'services.php')) {
        $cacheFile = Join-Path $root "bootstrap\cache\$cacheName"
        if (Test-Path -LiteralPath $cacheFile -PathType Leaf) { Remove-Item -LiteralPath $cacheFile }
    }
    Invoke-Checked $composer @('run-script', 'post-autoload-dump', '--no-interaction') 'Обновляю пакеты Laravel и Filament'
    Invoke-Checked $npm @('ci', '--no-audit', '--no-fund') 'Устанавливаю зависимости интерфейса'
    Invoke-Checked $php @('artisan', 'migrate', '--force') '5/6 — Применяю миграции базы'
    Invoke-Checked $php @('artisan', 'optimize:clear') 'Очищаю кэш приложения'
    if (-not (Test-Path -LiteralPath (Join-Path $root 'public\storage'))) {
        Invoke-Checked $php @('artisan', 'storage:link') 'Подключаю загруженные фотографии'
    }
    Invoke-Checked $npm @('run', 'build') '6/6 — Собираю интерфейс'
    # A stale Vite marker otherwise points the browser at a stopped dev server.
    $hotFile = Join-Path $root 'public\hot'
    if (Test-Path -LiteralPath $hotFile -PathType Leaf) { Remove-Item -LiteralPath $hotFile }

    Write-Host "`n  Обновление завершено. Запускаю проверку компьютера и бота..." -ForegroundColor Green
    Write-Host "  Резервная копия: $backup"
    Write-Host "  Журнал обновления: $log`n"
    Stop-Transcript | Out-Null
    $transcribing = $false
    # Reuse the standard launcher: it clears stale jobs BEFORE machine checks.
    # Calling smoke here first would prevent recovery after a previous crash.
    Invoke-Checked 'powershell.exe' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'start-ningredy.ps1')) ''
} catch {
    Write-Host "`n  Обновление/запуск остановлены: $($_.Exception.Message)" -ForegroundColor Red
    if ($backupComplete) { Write-Host "  Ваша резервная копия: $backup" -ForegroundColor Yellow }
    elseif ($backup) { Write-Host "  Копия в $backup НЕ завершена. Код и миграции ещё не обновлялись." -ForegroundColor Yellow }
    if ($log) { Write-Host "  Журнал: $log" }
    Write-Host '  Исправьте указанную причину и запустите update-and-start.bat снова.'
    if (-not $NoPause) { $null = Read-Host '  Нажмите Enter, чтобы закрыть' }
    exit 1
} finally {
    if ($transcribing) { Stop-Transcript | Out-Null }
    if ($locked) { $mutex.ReleaseMutex() }
    if ($mutex) { $mutex.Dispose() }
}
