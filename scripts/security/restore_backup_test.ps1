param(
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\\..")).Path,
    [string]$BackupZip = "",
    [string]$RestoreDbName = "asistencias_restore_test"
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

$envFile = Join-Path $ProjectRoot ".env"
$dbHost = Get-EnvValue -EnvFile $envFile -Key "DB_HOST" -DefaultValue "127.0.0.1"
$dbPort = Get-EnvValue -EnvFile $envFile -Key "DB_PORT" -DefaultValue "3306"
$dbUser = Get-EnvValue -EnvFile $envFile -Key "DB_USERNAME" -DefaultValue ""
$dbPass = Get-EnvValue -EnvFile $envFile -Key "DB_PASSWORD" -DefaultValue ""
$mysqlPath = Get-EnvValue -EnvFile $envFile -Key "MYSQL_PATH" -DefaultValue "mysql"
$auditLogPath = Join-Path $ProjectRoot "storage\\logs\\backup-restore-test.log"
$backupDir = Join-Path $ProjectRoot "storage\\app\\backups\\mysql"

if ([string]::IsNullOrWhiteSpace($dbUser)) {
    throw "DB_USERNAME is required in .env"
}

if ([string]::IsNullOrWhiteSpace($BackupZip)) {
    $BackupZip = Get-ChildItem -Path $backupDir -Filter *.zip | Sort-Object LastWriteTime -Descending | Select-Object -First 1 | ForEach-Object { $_.FullName }
}

if ([string]::IsNullOrWhiteSpace($BackupZip) -or -not (Test-Path $BackupZip)) {
    throw "Backup zip file not found."
}

$tempDir = Join-Path $env:TEMP ("restore-test-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

try {
    Expand-Archive -Path $BackupZip -DestinationPath $tempDir -Force
    $sqlFile = Get-ChildItem -Path $tempDir -Filter *.sql | Select-Object -First 1
    if (-not $sqlFile) {
        throw "No .sql file inside backup zip."
    }

    $mysqlBaseArgs = @(
        "--host=$dbHost",
        "--port=$dbPort",
        "--user=$dbUser"
    )
    if (-not [string]::IsNullOrWhiteSpace($dbPass)) {
        $mysqlBaseArgs = @("--password=$dbPass") + $mysqlBaseArgs
    }

    $createArgs = $mysqlBaseArgs + @("-e", "CREATE DATABASE IF NOT EXISTS `$RestoreDbName`;")
    $createProc = Start-Process -FilePath $mysqlPath -ArgumentList $createArgs -NoNewWindow -PassThru -Wait
    if ($createProc.ExitCode -ne 0) {
        throw "Could not create restore database."
    }

    $importArgs = $mysqlBaseArgs + @($RestoreDbName, "-e", "source $($sqlFile.FullName.Replace('\\','/'));")
    $importProc = Start-Process -FilePath $mysqlPath -ArgumentList $importArgs -NoNewWindow -PassThru -Wait
    if ($importProc.ExitCode -ne 0) {
        throw "Could not import backup into restore database."
    }

    $checkArgs = $mysqlBaseArgs + @($RestoreDbName, "-N", "-e", "SELECT CONCAT('attendance_logs=',COUNT(*)) FROM attendance_logs; SELECT CONCAT('audit_logs=',COUNT(*)) FROM audit_logs;")
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
}
