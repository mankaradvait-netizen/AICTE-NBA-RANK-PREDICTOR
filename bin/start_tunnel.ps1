# AICTE-NBA-NIRF Mobile / Public Tunnel Launcher
Write-Host "==========================================================" -ForegroundColor DarkRed
Write-Host " AICTE-NBA-NIRF - Mobile / Remote Access Tunnel" -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor DarkRed

$ip = (curl.exe -s https://loca.lt/mytunnelpassword).Trim()
Write-Host "Your Tunnel Password (if prompted on mobile): $ip" -ForegroundColor Green
Write-Host "Starting tunnel on https://adypu-rank-predictor.loca.lt ..." -ForegroundColor Cyan
Write-Host ""

npx localtunnel --port 8000 --subdomain adypu-rank-predictor
