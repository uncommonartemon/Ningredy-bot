# The launcher the bot is handed over with.
#
# The logic lives here rather than in the .bat because cmd cannot be trusted
# with non-ASCII: `chcp 65001` in a UTF-8 batch file desynchronises its own
# line parsing, and the Russian messages below started being executed as
# commands. The .bat is now three ASCII lines that call this file.
#
# Everything it refuses to do, it explains - the person reading this may not
# have written a line of the code, and the alternative is a bot that simply
# never answers.

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
$Host.UI.RawUI.WindowTitle = 'Ningredy'

function Stop-WithReason {
    param([string]$Problem, [string[]]$WhatToDo)

    Write-Host ''
    Write-Host "  [X] $Problem" -ForegroundColor Red
    foreach ($line in $WhatToDo) {
        Write-Host "      $line" -ForegroundColor Yellow
    }
    Write-Host ''
    Read-Host '  Нажмите Enter, чтобы закрыть'
    exit 1
}

function Test-Command {
    param([string]$Name)

    return [bool] (Get-Command $Name -ErrorAction SilentlyContinue)
}

Write-Host ''
Write-Host '  ============================================' -ForegroundColor DarkGray
Write-Host '    NINGREDY' -ForegroundColor Cyan
Write-Host '  ============================================' -ForegroundColor DarkGray
Write-Host ''

if (-not (Test-Path (Join-Path $root 'artisan'))) {
    Stop-WithReason 'Запущено не из папки бота.' @(
        'Положите этот файл рядом с файлом artisan и запустите снова.'
    )
}
if (-not (Test-Command 'php')) {
    Stop-WithReason 'Не найден PHP.' @(
        'Установите PHP 8.4 или новее и добавьте его в PATH.',
        'Проверить можно так:  php -v'
    )
}
if (-not (Test-Command 'npm')) {
    Stop-WithReason 'Не найден Node.js.' @(
        'Скачайте Node.js 20 или новее с nodejs.org и установите.',
        'Проверить можно так:  node -v'
    )
}
if (-not (Test-Command 'ngrok')) {
    Stop-WithReason 'Не найден ngrok.' @(
        'Он нужен, чтобы Telegram мог достучаться до этого компьютера.',
        'Скачайте с ngrok.com, распакуйте и добавьте папку в PATH.'
    )
}
if (-not (Test-Path (Join-Path $root 'node_modules\concurrently'))) {
    Stop-WithReason 'Не установлены зависимости.' @(
        'Откройте эту папку в терминале и выполните:  npm ci'
    )
}
if (-not (Test-Path (Join-Path $root '.env'))) {
    Stop-WithReason 'Нет файла настроек .env' @(
        'Скопируйте .env.example в .env, впишите токены, затем выполните:',
        '  php artisan key:generate',
        '  php artisan migrate'
    )
}

# A second window is one double-click away, and two bots on one database fight
# over the same queue - this machine has already ended up with three schedulers
# running at once. The helper exits 2 when another launcher window is open, and
# otherwise ends the leftovers of a previous run.
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'clear-leftover-processes.ps1')

if ($LASTEXITCODE -eq 2) {
    Stop-WithReason 'Бот уже запущен в другом окне.' @(
        'Закройте то окно и запустите снова.'
    )
}

# Everything still queued belongs to the previous run: this window is the only
# way the bot is stopped, so those jobs were killed rather than finished, and
# starting fresh workers on them replays yesterday's requests.
#
# This runs BEFORE the checks, and the order is the whole point. It used to run
# after, and the two lines deadlocked the launcher: a job left by the previous
# run is by definition unattended, the queue check calls an unattended job a
# failure and says to start a worker, and the launcher refuses to start the
# worker that would have drained it. The bot could not be started by the one
# thing that starts it. Clearing first means the check sees the queue the
# workers are actually about to be given.
#
# It is safe here: bot:clear-stale refuses to touch anything if it finds signs
# of a bot already working, so a wedged worker still reaches the check and is
# still reported.
& php artisan bot:clear-stale *> $null

Write-Host '  Проверяю компьютер...' -ForegroundColor DarkGray
Write-Host ''

# Only the machine's own checks: a shop being unreachable must never be the
# reason the bot refuses to start.
& php artisan bot:smoke --machine

if ($LASTEXITCODE -ne 0) {
    Stop-WithReason 'Бот не может стартовать.' @(
        'Смотрите строки FAIL выше - в каждой написано, что сделать.'
    )
}

Write-Host ''
Write-Host '  ============================================' -ForegroundColor DarkGray
Write-Host '    Бот запускается' -ForegroundColor Green
Write-Host '  ============================================' -ForegroundColor DarkGray
Write-Host ''
Write-Host '    Панель управления:'
Write-Host '      http://127.0.0.1:8000/admin' -ForegroundColor Cyan
Write-Host ''
Write-Host '    Состояние бота - первый раздел в панели.'
Write-Host '    Там видно, работает ли всё, и что делать, если нет.'
Write-Host ''
Write-Host '    Чтобы остановить бота - закройте это окно.' -ForegroundColor Yellow
Write-Host ''

# The launcher used to open the panel in a browser by itself, twelve seconds in.
# Nobody asked it to. Starting a bot and taking over the screen are two separate
# things, and the second one belongs to whoever is at the keyboard - they may be
# starting it to leave it running. The address is printed above; that is enough
# to describe an interface without seizing focus.

& npm run start

Write-Host ''
Read-Host '  Бот остановлен. Нажмите Enter, чтобы закрыть'
