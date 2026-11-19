@echo off
echo ==========================================================
echo  AICTE-NBA-NIRF - Android App ^& Live Mobile Demo
echo  Powered by Cloudflare Tunnel (Zero Splash Screen / Direct HTTPS)
echo ==========================================================
if not exist "%~dp0cloudflared.exe" (
    echo Downloading cloudflared.exe...
    curl -L -o "%~dp0cloudflared.exe" https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
)
echo Starting Cloudflare Tunnel to http://127.0.0.1:8000 ...
"%~dp0cloudflared.exe" tunnel --url http://127.0.0.1:8000
pause
