@echo off
rem Keep this file ASCII. This bootstrap also works with an old local updater.
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
if errorlevel 1 goto failed
set "update_temp=%TEMP%\ningredy-update-%RANDOM%-%RANDOM%"
if exist "%update_temp%" (
    echo Temporary folder already exists. Please run this file again.
    pause
    exit /b 1
)
mkdir "%update_temp%"
if errorlevel 1 goto failed
git fetch origin main
if errorlevel 1 goto failed
git archive --format=zip --output="%update_temp%\updater.zip" FETCH_HEAD scripts/update-and-start.ps1 scripts/backup-before-update.php scripts/backup-update-code.mjs
if errorlevel 1 goto failed
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference = 'Stop'; Expand-Archive -LiteralPath (Join-Path $env:update_temp 'updater.zip') -DestinationPath $env:update_temp"
if errorlevel 1 goto failed
powershell -NoProfile -ExecutionPolicy Bypass -File "%update_temp%\scripts\update-and-start.ps1" -ProjectRoot "%CD%" -ForceUpdate -NoPause
set "update_exit=%errorlevel%"
if not "%update_exit%"=="0" pause
exit /b %update_exit%

:failed
echo Update stopped. See the error above. Local project files were not replaced.
pause
exit /b 1
