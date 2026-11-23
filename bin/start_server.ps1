# AICTE-NBA-NIRF Rank Predictor - Local Server Launcher
Write-Host "==========================================================" -ForegroundColor DarkRed
Write-Host " AICTE-NBA-NIRF Institutional Intelligence & Rank Predictor" -ForegroundColor Yellow
Write-Host " Ajeenkya DY Patil University (ADYPU) Theme Edition" -ForegroundColor DarkYellow
Write-Host "==========================================================" -ForegroundColor DarkRed

$port = 8080
$conn = Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue

if ($conn) {
    Write-Host "Server is ALREADY running on http://localhost:$port (PID: $($conn.OwningProcess))" -ForegroundColor Green
} else {
    Write-Host "Starting PHP built-in web server on http://0.0.0.0:$port..." -ForegroundColor Cyan
    Start-Process -FilePath "php" -ArgumentList "-S 0.0.0.0:$port -t public public/index.php" -WindowStyle Hidden
    Start-Sleep -Seconds 1
    Write-Host "Server started successfully on http://localhost:$port" -ForegroundColor Green
}

Write-Host ""
Write-Host "Access URL:     http://localhost:$port" -ForegroundColor Cyan
Write-Host "Login Email:    admin@adypu.edu.in" -ForegroundColor Yellow
Write-Host "Login Password: Password123!" -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor DarkRed
