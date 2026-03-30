$ErrorActionPreference = "Stop"

$source = $env:CI_PROJECT_DIR
$target = "D:\biometrico\biometrico"
$backupRoot = "D:\backups\biometrico_production"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupPath = Join-Path $backupRoot $timestamp

$php = "D:\PHP\php-8.4\php.exe"
$composer = "C:\ProgramData\ComposerSetup\bin\composer.phar"

Write-Host "=== Deploy PRODUCCION iniciado ==="
Write-Host "Origen: $source"
Write-Host "Destino: $target"

if (!(Test-Path $php)) {
    throw "No se encontró PHP 8.4 en la ruta: $php"
}

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

Write-Host "Validando binarios..."
& $php -v
where.exe php
where.exe composer

Write-Host "Ejecutando tareas Laravel..."

if (Test-Path "composer.json") {
    if (Test-Path $composer) {
        & $php $composer install --no-dev --optimize-autoloader
    } else {
        if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
            throw "composer no está disponible en el PATH ni se encontró composer.phar en $composer"
        }
        composer install --no-dev --optimize-autoloader
    }
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

& $php artisan migrate --force
& $php artisan optimize:clear
& $php artisan config:cache
& $php artisan route:cache
& $php artisan view:cache

Write-Host "=== Deploy PRODUCCION finalizado ==="