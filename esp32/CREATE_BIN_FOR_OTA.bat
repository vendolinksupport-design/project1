@echo off
title VendoLink ESP32 - Compile .INO to .BIN Converter
echo ============================================================
echo   VendoLink ESP32 - Compile .INO to .BIN for OTA Update
echo ============================================================
echo.
echo Compiling vendolink_esp32_heartbeat.ino ...
echo Please wait 10-20 seconds...
echo.

set ARDUINO_CLI="C:\Program Files\Arduino IDE\resources\app\lib\backend\resources\arduino-cli.exe"
set SKETCH_DIR="%~dp0vendolink_esp32_heartbeat"
set OUTPUT_DIR="%~dp0build"

if not exist %ARDUINO_CLI% (
    echo [ERROR] Arduino CLI not found at default location.
    echo Please export binary inside Arduino IDE instead:
    echo   Menu: Sketch ^> Export Compiled Binary (Ctrl + Alt + S)
    echo.
    pause
    exit /b 1
)

%ARDUINO_CLI% compile --fqbn esp32:esp32:esp32 --output-dir %OUTPUT_DIR% %SKETCH_DIR%

if %ERRORLEVEL% equ 0 (
    echo.
    echo ============================================================
    echo   [SUCCESS] Your .BIN file is ready!
    echo ============================================================
    echo File: %~dp0build\vendolink_esp32_heartbeat.ino.bin
    echo.
    echo You can now upload this .BIN file in Admin:
    echo   http://localhost/vendolink_claude_v1/admin/ota/
    echo ============================================================
    echo.
    explorer %OUTPUT_DIR%
) else (
    echo.
    echo [ERROR] Compilation failed. Please check the error messages above.
)

pause
