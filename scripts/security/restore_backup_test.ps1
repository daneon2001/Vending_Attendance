param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\\..")).Path,
    [string]$BackupZip = "",
    [string]$RestoreDbName = ""
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

    $value = ($line -replace "^\s*$Key\s*=\s*", "").Trim()
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
$dbHost = Get-EnvValue -EnvFile $envFile -Key "DB_HOST" -DefaultValue "127.0.0.1"
$dbPort = Get-EnvValue -EnvFile $envFile -Key "DB_PORT" -DefaultValue "3306"
$dbUser = Get-EnvValue -EnvFile $envFile -Key "DB_USERNAME" -DefaultValue ""
$dbPass = Get-EnvValue -EnvFile $envFile -Key "DB_PASSWORD" -DefaultValue ""
$mysqlPath = Get-EnvValue -EnvFile $envFile -Key "MYSQL_PATH" -DefaultValue "mysql"
$mysqlPath = Resolve-DatabaseTool -ConfiguredPath $mysqlPath -Name "mysql"
$auditLogPath = Join-Path $ProjectRoot "storage\\logs\\backup-restore-test.log"
$backupDir = Join-Path $ProjectRoot "storage\\app\\backups\\mysql"

if ([string]::IsNullOrWhiteSpace($dbUser)) {
    throw "DB_USERNAME is required in .env"
}

if ([string]::IsNullOrWhiteSpace($RestoreDbName)) {
    $RestoreDbName = "vending_attendance_restore_test_" + [DateTime]::UtcNow.ToString("yyyyMMddHHmmss")
}
$sourceDbName = Get-EnvValue -EnvFile $envFile -Key "DB_DATABASE" -DefaultValue ""
if ($RestoreDbName -notmatch '^vending_attendance_[a-z0-9_]*restore[a-z0-9_]*test[a-z0-9_]*$') {
    throw "Restore database must be a dedicated vending_attendance_*restore*test* database."
}
if ($RestoreDbName.Equals($sourceDbName, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing restore over the configured source database."
}

if ([string]::IsNullOrWhiteSpace($BackupZip)) {
    $BackupZip = Get-ChildItem -Path $backupDir -Filter *.zip | Sort-Object LastWriteTime -Descending | Select-Object -First 1 | ForEach-Object { $_.FullName }
}

if ([string]::IsNullOrWhiteSpace($BackupZip) -or -not (Test-Path $BackupZip)) {
    throw "Backup zip file not found."
}

$checksumFile = [System.IO.Path]::ChangeExtension($BackupZip, ".sha256")
if (-not (Test-Path -LiteralPath $checksumFile)) {
    throw "Matching SHA-256 file not found."
}
$expectedChecksum = ((Get-Content -LiteralPath $checksumFile -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
$actualChecksum = (Get-FileHash -LiteralPath $BackupZip -Algorithm SHA256).Hash.ToLowerInvariant()
if ($expectedChecksum -notmatch '^[a-f0-9]{64}$' -or $actualChecksum -ne $expectedChecksum) {
    throw "Backup checksum validation failed."
}

$tempDir = Join-Path $env:TEMP ("restore-test-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
$clientDefaultsPath = [System.IO.Path]::GetTempFileName()
$clientDefaults = @(
    "[client]",
    "host=$dbHost",
    "port=$dbPort",
    "user=$dbUser",
    "password=$dbPass"
) -join [Environment]::NewLine
[System.IO.File]::WriteAllText($clientDefaultsPath, $clientDefaults, [System.Text.Encoding]::ASCII)

try {
    Expand-Archive -Path $BackupZip -DestinationPath $tempDir -Force
    $sqlFile = Get-ChildItem -Path $tempDir -Filter *.sql | Select-Object -First 1
    if (-not $sqlFile) {
        throw "No .sql file inside backup zip."
    }

    $mysqlBaseArgs = @("--defaults-extra-file=$clientDefaultsPath")

    $createSql = "CREATE DATABASE ``$RestoreDbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    & $mysqlPath @mysqlBaseArgs -e $createSql
    if ($LASTEXITCODE -ne 0) {
        throw "Could not create restore database."
    }

    $importSql = "source $($sqlFile.FullName.Replace('\','/'));"
    & $mysqlPath @mysqlBaseArgs $RestoreDbName -e $importSql
    if ($LASTEXITCODE -ne 0) {
        throw "Could not import backup into restore database."
    }

    $checksSql = @(
        "SELECT CONCAT('vending_machines=',COUNT(*)) FROM vending_machines;",
        "SELECT CONCAT('devices=',COUNT(*)) FROM devices;",
        "SELECT CONCAT('employee_machine_assignments=',COUNT(*)) FROM employee_machine_assignments;",
        "SELECT CONCAT('vending_attendance_events=',COUNT(*)) FROM vending_attendance_events;",
        "SELECT CONCAT('device_manifest_states=',COUNT(*)) FROM device_manifest_states;",
        "SELECT CONCAT('audit_logs=',COUNT(*)) FROM audit_logs;"
    ) -join " "
    $checkArgs = $mysqlBaseArgs + @($RestoreDbName, "-N", "-e", $checksSql)
    $checkOutput = & $mysqlPath @checkArgs

    $event = [ordered]@{
        ts_utc = [DateTime]::UtcNow.ToString("o")
        action = "restore_backup_test"
        status = "ok"
        backup_file = [System.IO.Path]::GetFileName($BackupZip)
        restore_db = $RestoreDbName
        checks = ($checkOutput | Out-String).Trim()
    } | ConvertTo-Json -Compress

    Add-Content -Path $auditLogPath -Value $event
    Write-Output $event
}
catch {
    $event = [ordered]@{
        ts_utc = [DateTime]::UtcNow.ToString("o")
        action = "restore_backup_test"
        status = "failed"
        backup_file = [System.IO.Path]::GetFileName($BackupZip)
        restore_db = $RestoreDbName
        error = $_.Exception.Message
    } | ConvertTo-Json -Compress

    Add-Content -Path $auditLogPath -Value $event
    Write-Error $event
    exit 1
}
finally {
    if (Test-Path $tempDir) {
        Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path $clientDefaultsPath) {
        Remove-Item -LiteralPath $clientDefaultsPath -Force -ErrorAction SilentlyContinue
    }
}
