@echo off
title VendoLink - Revert Online Code to Previous Backup
echo ============================================================
echo   VendoLink - 1-Click Rollback / Revert Online Code
echo ============================================================
echo.
echo Reverting vendolink.online to previous backup...
curl.exe -s -X POST https://vendolink.online/deploy.php -F "secret=vl_deploy_secret_2026_x89q" -F "action=rollback"
echo.
echo ============================================================
pause
