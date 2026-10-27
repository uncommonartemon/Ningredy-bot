@echo off
rem Keep this file ASCII; user-facing messages are in the PowerShell script.
rem The newest updater comes from GitHub first, so an installation of any age
rem runs today's update steps. Without network the local one runs and says why.
setlocal EnableExtensions DisableDelayedExpansion
cd /d "%~dp0"
set "updater=%~dp0scripts\update-and-start.ps1"
set "update_temp=%TEMP%\ningredy-update-%RANDOM%-%RANDOM%"
git fetch origin main
if errorlevel 1 goto run
mkdir "%update_temp%"
if errorlevel 1 goto run
git archive --format=zip --output="%update_temp%\updater.zip" FETCH_HEAD scripts/update-and-start.ps1 scripts/backup-before-update.php scripts/backup-update-code.mjs
if errorlevel 1 goto run
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference = 'Stop'; Expand-Archive -LiteralPath (Join-Path $env:update_temp 'updater.zip') -DestinationPath $env:update_temp"
if errorlevel 1 goto run
set "updater=%update_temp%\scripts\update-and-start.ps1"
:run
powershell -NoProfile -ExecutionPolicy Bypass -File "%updater%" -ProjectRoot "%CD%" -NoPause
set "update_exit=%errorlevel%"
if exist "%update_temp%" rd /s /q "%update_temp%"
if not "%update_exit%"=="0" pause
exit /b %update_exit%
