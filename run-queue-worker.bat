@echo off
setlocal
cd /d "%~dp0"

set "QUEUE_NAMES=assistant,voice,media,default"
set "RESTART_DELAY=5"

if not "%~1"=="" set "QUEUE_NAMES=%~1"
if not "%~2"=="" set "RESTART_DELAY=%~2"

:restart
echo [%date% %time%] Starting queue worker for: %QUEUE_NAMES%
php artisan queue:work --queue=%QUEUE_NAMES% --sleep=2 --timeout=780 --max-time=86400
set "WORKER_EXIT_CODE=%errorlevel%"
echo [%date% %time%] Queue worker exited with code %WORKER_EXIT_CODE%. Restarting in %RESTART_DELAY% seconds...
timeout /t %RESTART_DELAY% /nobreak >nul
goto restart
