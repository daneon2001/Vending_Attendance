$ErrorActionPreference = "Stop"

$source = $env:CI_PROJECT_DIR
$target = "D:\biometrico"
$backupRoot = "D:\backups\biometrico"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupPath = Join-Path $backupRoot $timestamp

Write-Host "=== Deploy QA iniciado ==="
Write-Host "Origen: $source"
Write-Host "Destino: $target"

if (!(Test-Path $backupRoot)) {
    New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
}

if (!(Test-Path $target)) {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
}

Write-Host "Respaldando versión actual..."
New-Item -ItemType Directory -Path $backupPath -Force | Out-Null
robocopy $target $backupPath /MIR /XD .git node_modules vendor storage\logs bootstrap\cache | Out-Null

Write-Host "Copiando archivos nuevos..."
robocopy $source $target /MIR `
    /XD .git .venv node_modules tests .pytest_cache storage\logs bootstrap\cache `
    /XF .env *.log

if ($LASTEXITCODE -gt 7) {
    throw "Robocopy devolvió código de error $LASTEXITCODE"
}

Set-Location $target

Write-Host "Ejecutando tareas Laravel..."
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache

Write-Host "=== Deploy QA finalizado ==="