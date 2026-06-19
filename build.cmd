@echo off
REM Double-click this file (or run `build` in cmd) to build the plugin ZIP.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build.ps1" %*
pause
