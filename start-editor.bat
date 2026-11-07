@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo PHP wurde nicht gefunden. Bitte PHP 8.1+ installieren und in den PATH aufnehmen.
    echo Alternativ: den Pfad in dieser Datei unten fest eintragen.
    pause
    exit /b 1
)

set PORT=8899
echo Website-Editor startet auf http://127.0.0.1:%PORT%/
start "" "http://127.0.0.1:%PORT%/"
php -S 127.0.0.1:%PORT% -t . app\router.php
