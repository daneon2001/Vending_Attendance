param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\\..")).Path,
    [string]$BackupRoot = "",
    [switch]$SkipOffsite
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-EnvValue {
    param(
        [string]$EnvFile,
        [string]$Key,
        [string]$DefaultValue = ""
    )

    if (-not (Test-Path $EnvFile)) {
        return $DefaultValue
    }

    $line = Get-Content -Path $EnvFile | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) {
        return $DefaultValue
    }

    $value = ($line -replace "^\s*$Key\s*=\s*", "")
    $value = $value.Trim()
    if ($value.StartsWith('"') -and $value.EndsWith('"')) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    if ($value.StartsWith("'") -and $value.EndsWith("'")) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    return $value
}

$envFile = Join-Path $ProjectRoot ".env"
$appName = Get-EnvValue -EnvFile $envFile -Key "APP_NAME" -DefaultValue "asistencias_fortia"
$dbHost = Get-EnvValue -EnvFile $envFile -Key "DB_HOST" -DefaultValue "127.0.0.1"
$dbPort = Get-EnvValue -EnvFile $envFile -Key "DB_PORT" -DefaultValue "3306"
$dbName = Get-EnvValue -EnvFile $envFile -Key "DB_DATABASE" -DefaultValue ""
$dbUser = Get-EnvValue -EnvFile $envFile -Key "DB_USERNAME" -DefaultValue ""
$dbPass = Get-EnvValue -EnvFile $envFile -Key "DB_PASSWORD" -DefaultValue ""
$mysqldumpPath = Get-EnvValue -EnvFile $envFile -Key "MYSQLDUMP_PATH" -DefaultValue "mysqldump"
$offsitePath = Get-EnvValue -EnvFile $envFile -Key "BACKUP_OFFSITE_PATH" -DefaultValue ""

if ([string]::IsNullOrWhiteSpace($dbName) -or [string]::IsNullOrWhiteSpace($dbUser)) {
    throw "DB_DATABASE and DB_USERNAME are required in .env"
}

if ([string]::IsNullOrWhiteSpace($BackupRoot)) {
    $BackupRoot = Join-Path $ProjectRoot "storage\\app\\backups\\mysql"
}

New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null

$utcNow = [DateTime]::UtcNow
$stamp = $utcNow.ToString("yyyyMMdd_HHmmss")
$safeApp = ($appName -replace "[^a-zA-Z0-9_-]", "_")
$baseName = "$safeApp-$dbName-$stamp-utc"
$sqlPath = Join-Path $BackupRoot "$baseName.sql"
$zipPath = Join-Path $BackupRoot "$baseName.zip"
$shaPath = Join-Path $BackupRoot "$baseName.sha256"
$auditLogPath = Join-Path $ProjectRoot "storage\\logs\\backup-audit.log"

$dumpArgs = @(
    "--host=$dbHost",
    "--port=$dbPort",
    "--user=$dbUser",
    "--single-transaction",
    "--quick",
    "--routines",
    "--events",
    "--triggers",
    "--default-character-set=utf8mb4",
    $dbName
)

if (-not [string]::IsNullOrWhiteSpace($dbPass)) {
    $dumpArgs = @("--password=$dbPass") + $dumpArgs
}

$dumpError = ""
$status = "ok"
$offsiteCopied = $false
$stderrFile = [System.IO.Path]::GetTempFileName()

try {
    $dumpProcess = Start-Process -FilePath $mysqldumpPath `
        -ArgumentList $dumpArgs `
        -RedirectStandardOutput $sqlPath `
        -RedirectStandardError $stderrFile `
        -NoNewWindow `
        -PassThru `
        -Wait

    if ($dumpProcess.ExitCode -ne 0) {
        $status = "failed"
        $dumpError = Get-Content -Path $stderrFile -Raw
        throw "mysqldump exited with code $($dumpProcess.ExitCode)"
    }

    Compress-Archive -Path $sqlPath -DestinationPath $zipPath -Force
    Remove-Item -Path $sqlPath -Force

    $hash = Get-FileHash -Path $zipPath -Algorithm SHA256
    "$($hash.Hash.ToLower())  $([System.IO.Path]::GetFileName($zipPath))" | Out-File -FilePath $shaPath -Encoding ascii

    if (-not $SkipOffsite -and -not [string]::IsNullOrWhiteSpace($offsitePath)) {
        New-Item -ItemType Directory -Force -Path $offsitePath | Out-Null
        Copy-Item -Path $zipPath -Destination (Join-Path $offsitePath ([System.IO.Path]::GetFileName($zipPath))) -Force
        Copy-Item -Path $shaPath -Destination (Join-Path $offsitePath ([System.IO.Path]::GetFileName($shaPath))) -Force
        $offsiteCopied = $true
    }

    $event = [ordered]@{
        ts_utc = $utcNow.ToString("o")
        action = "backup_mysql"
        status = $status
        db = $dbName
        file = [System.IO.Path]::GetFileName($zipPath)
        sha256 = $hash.Hash.ToLower()
        offsite_copied = $offsiteCopied
    } | ConvertTo-Json -Compress

    Add-Content -Path $auditLogPath -Value $event
    Write-Output $event
}
catch {
    $event = [ordered]@{
        ts_utc = [DateTime]::UtcNow.ToString("o")
        action = "backup_mysql"
        status = "failed"
        db = $dbName
        error = $_.Exception.Message
        stderr = $dumpError
    } | ConvertTo-Json -Compress

    Add-Content -Path $auditLogPath -Value $event
    Write-Error $event
    exit 1
}
finally {
    if (Test-Path $stderrFile) {
        Remove-Item -Path $stderrFile -Force -ErrorAction SilentlyContinue
    }
}
