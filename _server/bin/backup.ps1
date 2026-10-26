# Mzigo.online — database backup (MariaDB/MySQL)
#
#   powershell -File _server\bin\backup.ps1
#
# Writes a compressed SQL dump to _server\backups\ and keeps the last 14 files.
# Schedule daily with Task Scheduler (or a container cron sidecar) and copy the
# backups off the host (object storage / second disk) for real disaster recovery.

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$config = Get-Content (Join-Path $root '_server\config.php') -Raw
$backupDir = Join-Path $root '_server\backups'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$mysqlDump = 'C:\xampp\mysql\bin\mysqldump.exe'
if (-not (Test-Path $mysqlDump)) {
    # Linux/container fallback
    $mysqlDump = 'mysqldump'
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$file = Join-Path $backupDir "mzigo_online-$stamp.sql"

$env:DB_HOST = if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }
$env:DB_PORT = if ($env:DB_PORT) { $env:DB_PORT } else { '3307' }
$env:DB_NAME = if ($env:DB_NAME) { $env:DB_NAME } else { 'mzigo_online' }
$env:DB_USER = if ($env:DB_USER) { $env:DB_USER } else { 'root' }

$args = @(
    '--protocol=TCP',
    "-h$($env:DB_HOST)",
    "-P$($env:DB_PORT)",
    "-u$($env:DB_USER)",
    '--single-transaction',
    '--routines',
    '--triggers',
    '--databases', $env:DB_NAME
)

if ($env:DB_PASS) { $args += "-p$($env:DB_PASS)" }

& $mysqlDump @args | Out-File -FilePath $file -Encoding utf8
Write-Host "backup written: $file"

# Retention: keep the newest 14 dumps
Get-ChildItem $backupDir -Filter 'mzigo_online-*.sql' |
    Sort-Object LastWriteTime -Descending |
    Select-Object -Skip 14 |
    Remove-Item -Force

Write-Host "retention applied (14 files)"
