@echo off
rem Thin on purpose: cmd cannot be trusted with non-ASCII, so every message the
rem person sees lives in the PowerShell script this calls.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-ningredy.ps1"
