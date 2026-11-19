# AICTE-NBA-NIRF Android & Mobile Cloudflare Tunnel Launcher
Write-Host "==========================================================" -ForegroundColor DarkRed
Write-Host " AICTE-NBA-NIRF - Android App & Live Mobile Demo" -ForegroundColor Yellow
Write-Host " Powered by Cloudflare Tunnel (Zero Splash Screen / Direct HTTPS)" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor DarkRed

$binPath = "$PSScriptRoot\cloudflared.exe"
if (-not (Test-Path $binPath)) {
    Write-Host "Downloading cloudflared.exe..." -ForegroundColor Yellow
    curl.exe -L -o $binPath https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe
}

Write-Host "Starting Cloudflare Tunnel to http://127.0.0.1:8000 ..." -ForegroundColor Green
Write-Host "Look for the https://*.trycloudflare.com URL below:" -ForegroundColor Yellow
Write-Host ""

& $binPath tunnel --url http://127.0.0.1:8000
