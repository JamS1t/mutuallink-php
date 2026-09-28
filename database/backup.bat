@echo off
REM ============================================================
REM MutualLink logical backup (mysqldump) for WampServer.
REM Run daily (Task Scheduler) or before updates.
REM Restore: mysql -u root -p mutuallink < backups\mutuallink_YYYYMMDD_HHMM.sql
REM ============================================================
setlocal
REM Adjust to the MySQL folder of your WampServer install:
set MYSQL_BIN=C:\wamp64\bin\mysql\mysql8.3.0\bin
set BACKUP_DIR=%~dp0backups
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmm"') do set STAMP=%%i

"%MYSQL_BIN%\mysqldump.exe" -u root -p --single-transaction --routines --databases mutuallink > "%BACKUP_DIR%\mutuallink_%STAMP%.sql"
if errorlevel 1 (
    echo Backup FAILED.
) else (
    echo Backup saved to %BACKUP_DIR%\mutuallink_%STAMP%.sql
)
endlocal
pause
