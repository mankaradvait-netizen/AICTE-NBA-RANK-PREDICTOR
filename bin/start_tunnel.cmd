@echo off
echo ==========================================================
echo  AICTE-NBA-NIRF - Mobile / Remote Access Tunnel
echo ==========================================================
for /f "delims=" %%i in ('curl -s https://loca.lt/mytunnelpassword') do set TUNNEL_PASS=%%i
echo Your Tunnel Password (if prompted on mobile): %TUNNEL_PASS%
echo Opening tunnel...
npx localtunnel --port 8000 --subdomain adypu-rank-predictor
pause
