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

function Resolve-DatabaseTool {
    param([string]$ConfiguredPath, [string]$Name)

    if (-not [string]::IsNullOrWhiteSpace($ConfiguredPath) -and (Test-Path -LiteralPath $ConfiguredPath)) {
        return (Resolve-Path -LiteralPath $ConfiguredPath).Path
    }
    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }
    $laragonRoot = "C:\laragon\bin\mysql"
    if (Test-Path -LiteralPath $laragonRoot) {
        $candidate = Get-ChildItem -LiteralPath $laragonRoot -Filter "$Name.exe" -Recurse -File |
            Sort-Object FullName -Descending | Select-Object -First 1
        if ($candidate) {
            return $candidate.FullName
        }
    }

    throw "$Name executable not found. Configure its path in .env."
}

$envFile = Join-Path $ProjectRoot ".env"
$appName = Get-EnvValue -EnvFile $envFile -Key "APP_NAME" -DefaultValue "vending_attendance"
$dbHost = Get-EnvValue -EnvFile $envFile -Key "DB_HOST" -DefaultValue "127.0.0.1"
$dbPort = Get-EnvValue -EnvFile $envFile -Key "DB_PORT" -DefaultValue "3306"
$dbName = Get-EnvValue -EnvFile $envFile -Key "DB_DATABASE" -DefaultValue ""
$dbUser = Get-EnvValue -EnvFile $envFile -Key "DB_USERNAME" -DefaultValue ""
$dbPass = Get-EnvValue -EnvFile $envFile -Key "DB_PASSWORD" -DefaultValue ""
$mysqldumpPath = Get-EnvValue -EnvFile $envFile -Key "MYSQLDUMP_PATH" -DefaultValue "mysqldump"
$mysqldumpPath = Resolve-DatabaseTool -ConfiguredPath $mysqldumpPath -Name "mysqldump"
$offsitePath = Get-EnvValue -EnvFile $envFile -Key "BACKUP_OFFSITE_PATH" -DefaultValue ""

if ([string]::IsNullOrWhiteSpace($dbName) -or [string]::IsNullOrWhiteSpace($dbUser)) {
    throw "DB_DATABASE and DB_USERNAME are required in .env"
}

$allowedPrefix = Get-EnvValue -EnvFile $envFile -Key "VENDING_BACKUP_ALLOWED_DB_PREFIX" -DefaultValue "vending_attendance_"
if ([string]::IsNullOrWhiteSpace($allowedPrefix) -or -not $dbName.StartsWith($allowedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing backup: DB_DATABASE must start with the configured vending prefix."
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

$clientDefaultsPath = [System.IO.Path]::GetTempFileName()
$clientDefaults = @(
    "[client]",
    "host=$dbHost",
    "port=$dbPort",
    "user=$dbUser",
    "password=$dbPass"
) -join [Environment]::NewLine
[System.IO.File]::WriteAllText($clientDefaultsPath, $clientDefaults, [System.Text.Encoding]::ASCII)

$dumpArgs = @(
    "--defaults-extra-file=$clientDefaultsPath",
    "--single-transaction",
    "--quick",
    "--routines",
    "--events",
    "--triggers",
    "--default-character-set=utf8mb4",
    $dbName
)

$dumpError = ""
$status = "ok"
$offsiteCopied = $false
$stderrFile = [System.IO.Path]::GetTempFileName()

try {
    $dumpProcess = Start-Process -FilePath $mysqldumpPath `
        -ArgumentList $dumpArgs `
        -RedirectStandardOutput $sqlPath `
        -RedirectStandardError $stderrFile `
        -WindowStyle Hidden `
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
    if (Test-Path $clientDefaultsPath) {
        Remove-Item -LiteralPath $clientDefaultsPath -Force -ErrorAction SilentlyContinue
    }
}
