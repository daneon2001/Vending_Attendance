# Private input only. Does not connect to MySQL or provision anything.
$ErrorActionPreference = 'Stop'
$root = 'C:\laragon\www\vending-attendance'
$privateDir = Join-Path $root 'storage\app\private\beta-onboarding'
if ((Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path -ne $root) { throw 'Unexpected workspace' }
New-Item -ItemType Directory -Path $privateDir -Force | Out-Null
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $privateDir /inheritance:r /grant:r "${identity}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Cannot secure private input directory' }
$credentialsPath = Join-Path $privateDir 'tester12-credentials.clixml'
if (Test-Path -LiteralPath $credentialsPath) { throw 'Private input already exists; no overwrite' }
$email = (Read-Host 'Correo corporativo real de TOTOMOCH IBANEZ ARTURO (Employee 12)').Trim()
if ($email -notmatch '^[^\s@]+@[^\s@]+\.[^\s@]+$') { throw 'Correo invalido' }
$password = Read-Host 'Contrasena privada (no se mostrara)' -AsSecureString
if ($password.Length -lt 12) { throw 'Usa al menos 12 caracteres' }
$credential = [Management.Automation.PSCredential]::new($email, $password)
# Windows DPAPI protects the password for this Windows user on this computer.
$credential | Export-Clixml -LiteralPath $credentialsPath
Write-Host 'Entrada privada guardada. No se creo User, policy, assignment ni dispositivo.'
Write-Host 'Completa pending_input.phone_e164 en storage/app/private/beta-onboarding/testers.json, sin enviarlo al chat.'
