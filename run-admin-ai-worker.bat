@echo off
setlocal
cd /d "%~dp0"

:restart
echo Starting the local Filament AI worker (admin-ai queue only).
php artisan queue:work database --queue=admin-ai --sleep=1 --tries=1 --timeout=360 --max-time=86400
set "admin_worker_exit=%errorlevel%"
echo Admin AI worker exited with code %admin_worker_exit%. Restarting in 5 seconds...
rem concurrently pipes stdin; Windows timeout cannot wait on redirected stdin.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Sleep -Seconds 5"
goto restart
