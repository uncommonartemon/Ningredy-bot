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
rem schedulers running at once. Handle 9 stays open for as long as this window
rem lives, so a second instance cannot take it and stops here instead of
rem clearing the running bot's work below.
set "NINGREDY_LOCKED="
2>nul (
    9>"%~dp0.ningredy-running.lock" (
        set "NINGREDY_LOCKED=1"
        call :run
    )
)
if not defined NINGREDY_LOCKED (
    echo Ningredy is already running in another window.
    echo Close that window first, or use it instead of this one.
    pause
    exit /b 1
)
exit /b 0

:run

rem Reaching this line means the lock above was taken, so no other legitimate
rem instance is running and anything still alive is an orphan of a previous
rem start - see the script for why they survive a closed window.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\clear-leftover-processes.ps1"

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
