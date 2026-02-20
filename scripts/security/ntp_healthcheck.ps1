param(
    [int]$MaxHoursSinceSync = 24,
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\\..")).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$logPath = Join-Path $ProjectRoot "storage\\logs\\ntp-health.log"

try {
    $statusRaw = w32tm /query /status 2>&1
    $sourceRaw = w32tm /query /source 2>&1

    $stratumLine = $statusRaw | Where-Object { $_ -match "^\s*Stratum:" } | Select-Object -First 1
    $syncLine = $statusRaw | Where-Object { $_ -match "^\s*Last Successful Sync Time:" } | Select-Object -First 1

    $stratum = $null
    if ($stratumLine -match "Stratum:\s*(\d+)") {
        $stratum = [int]$matches[1]
    }

    $lastSync = $null
    if ($syncLine -match "Last Successful Sync Time:\s*(.+)$") {
        $parsed = $matches[1].Trim()
        try {
            $lastSync = [DateTime]::Parse($parsed).ToUniversalTime()
        } catch {
            $lastSync = $null
        }
    }

    $hoursSinceSync = $null
    if ($lastSync) {
        $hoursSinceSync = [math]::Round(([DateTime]::UtcNow - $lastSync).TotalHours, 2)
    }

    $healthy = $true
    if (-not $lastSync) {
        $healthy = $false
    }
    if ($hoursSinceSync -ne $null -and $hoursSinceSync -gt $MaxHoursSinceSync) {
        $healthy = $false
    }

    $event = [ordered]@{
        ts_utc = [DateTime]::UtcNow.ToString("o")
        action = "ntp_healthcheck"
        healthy = $healthy
        max_hours_since_sync = $MaxHoursSinceSync
        hours_since_sync = $hoursSinceSync
        stratum = $stratum
        source = ($sourceRaw | Out-String).Trim()
    } | ConvertTo-Json -Compress

    Add-Content -Path $logPath -Value $event
    Write-Output $event

    if (-not $healthy) {
        exit 2
    }
}
catch {
    $event = [ordered]@{
        ts_utc = [DateTime]::UtcNow.ToString("o")
        action = "ntp_healthcheck"
        healthy = $false
        error = $_.Exception.Message
    } | ConvertTo-Json -Compress

    Add-Content -Path $logPath -Value $event
    Write-Error $event
    exit 1
}
