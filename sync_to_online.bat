@echo off
title VendoLink - Sync Local to Online (vendolink.online)
echo ============================================================
echo   VendoLink - Sync Local Code to vendolink.online
echo ============================================================
echo.
"C:\xampp\php\php.exe" "%~dp0sync_to_online.php" %*
echo.
pause
