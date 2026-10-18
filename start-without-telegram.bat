@echo off
rem Local website/admin only; never run the bot startup lifecycle.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-ningredy.ps1" -WithoutTelegram
exit /b %errorlevel%
