@echo off
rem Keep this file ASCII; user-facing messages are in the PowerShell script.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\update-and-start.ps1" -NoPause
set "update_exit=%errorlevel%"
if not "%update_exit%"=="0" pause
exit /b %update_exit%
