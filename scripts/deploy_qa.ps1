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

$hasExistingFiles = (Get-ChildItem -Path $target -Force -ErrorAction SilentlyContinue | Measure-Object).Count -gt 0

if ($hasExistingFiles) {
    Write-Host "Respaldando versión actual..."
    New-Item -ItemType Directory -Path $backupPath -Force | Out-Null
    robocopy $target $backupPath /MIR /XD .git node_modules vendor storage\logs bootstrap\cache | Out-Null
    if ($LASTEXITCODE -gt 7) {
        throw "Error al respaldar con robocopy. Código: $LASTEXITCODE"
    }
}

Write-Host "Copiando archivos nuevos..."
robocopy $source $target /MIR `
    /XD .git .venv node_modules tests .pytest_cache storage\logs bootstrap\cache `
    /XF .env *.log

if ($LASTEXITCODE -gt 7) {
    throw "Error al copiar con robocopy. Código: $LASTEXITCODE"
}

Set-Location $target

Write-Host "Ejecutando tareas Laravel..."

if (Test-Path "composer.json") {
    if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
        throw "composer no está disponible en el PATH."
    }
    composer install --no-dev --optimize-autoloader
}

if (Test-Path "package-lock.json") {
    if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
        throw "npm no está disponible en el PATH."
    }
    npm ci
    npm run build
} elseif (Test-Path "package.json") {
    if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
        throw "npm no está disponible en el PATH."
    }
    npm install
    npm run build
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "php no está disponible en el PATH."
}

php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache

Write-Host "=== Deploy QA finalizado ==="