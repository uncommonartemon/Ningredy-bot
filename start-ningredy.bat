@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo PHP was not found in PATH.
    pause
    exit /b 1
)
where ngrok >nul 2>nul
if errorlevel 1 (
    echo ngrok was not found in PATH.
    pause
    exit /b 1
)
where npm >nul 2>nul
if errorlevel 1 (
    echo Node.js/npm was not found in PATH.
    pause
    exit /b 1
)
if not exist "artisan" (
    echo artisan was not found in %CD%.
    pause
    exit /b 1
)
if not exist "node_modules\concurrently" (
    echo Dependencies are missing. Run: npm install
    pause
    exit /b 1
)

title Ningredy

rem A second window is one double-click away, and two bots on one database
rem fight over the same queue - this machine has already ended up with three
rem schedulers running at once.
rem
rem This used to hold an exclusive file handle for the life of the window, which
rem deadlocked: cmd's redirection is inherited by every child, so an orphaned
rem worker from a closed window kept the lock held, and the next start was
rem refused by exactly the leftovers it was about to clean up. What we actually
rem want to prevent is a second launcher window, and that is directly
rem observable - count them instead of locking a file.
rem Both the check and the cleanup live in the script: the same logic written
rem inline here is a quoting trap that fails silently. It exits 2 when another
rem launcher window is open, and otherwise ends the leftovers of a previous run.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\clear-leftover-processes.ps1"

if errorlevel 2 (
    pause
    exit /b 1
)

rem Everything still queued belongs to the previous run: this window is the only
rem way the bot is stopped, so those jobs were killed rather than finished, and
rem starting fresh workers on them replays yesterday's requests.
php artisan bot:clear-stale

echo Starting Ningredy in a single window...
echo   Web:      http://127.0.0.1:8000
echo   Queues:   assistant, voice, media, default
echo   Scheduler: queue health-check every 2 min (Telegram alert if stuck)
echo   Ngrok UI: http://127.0.0.1:4040
echo   Webhook:  automatic ngrok synchronization
echo.
echo Close this window to stop everything. Crashed processes restart automatically.
echo.
call npm run start
pause
