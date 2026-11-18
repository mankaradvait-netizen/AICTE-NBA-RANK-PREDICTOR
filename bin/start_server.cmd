@echo off
echo ==========================================================
echo  AICTE-NBA-NIRF Institutional Intelligence ^& Rank Predictor
echo  Ajeenkya DY Patil University (ADYPU) Theme Edition
echo ==========================================================
echo Starting server on http://localhost:8000 ...
start /B php -S 0.0.0.0:8000 -t public public/index.php
echo Server running at http://localhost:8000
echo Credentials: admin@adypu.edu.in / Password123!
pause
