param([switch]$Start)
$ErrorActionPreference = 'Stop'
$projectRoot = 'C:\laragon\www\vending-attendance'
$tlsRoot = Join-Path $projectRoot 'storage\framework\local-https'
$toolRoot = Join-Path $projectRoot 'tools\local-field-https'
$openssl = 'C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17\bin\openssl.exe'
$httpd = 'C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17\bin\httpd.exe'
if ((Resolve-Path $PSScriptRoot).Path -ne $toolRoot) { throw 'Unexpected project path.' }
if (!(Get-NetIPAddress -AddressFamily IPv4 | Where-Object IPAddress -eq '192.168.101.15')) { throw 'Expected demo LAN address is not present.' }
function Invoke-CertificateTool([string[]]$Arguments) {
    & $openssl @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Local certificate operation failed.' }
}
New-Item -ItemType Directory -Path $tlsRoot -Force | Out-Null
# Private material is accessible only to the current Windows identity and SYSTEM.
$identityName = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $tlsRoot /inheritance:r /grant:r "${identityName}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Cannot secure the private TLS directory.' }
# Required for POST buffering in the isolated FastCGI environment. Inherits ACL.
New-Item -ItemType Directory -Path (Join-Path $tlsRoot 'php-tmp') -Force | Out-Null
$ca = Join-Path $tlsRoot 'ca.pem'
$caKey = Join-Path $tlsRoot 'ca.key'
$leaf = Join-Path $tlsRoot 'server.pem'
$leafKey = Join-Path $tlsRoot 'server.key'
$request = Join-Path $tlsRoot 'server.csr'
$config = Join-Path $toolRoot 'openssl.cnf'
if (!(Test-Path -LiteralPath $ca)) {
    if ((Test-Path -LiteralPath $caKey) -or (Test-Path -LiteralPath $leafKey)) { throw 'Partial key material exists; inspect manually. No overwrite.' }
    Invoke-CertificateTool -Arguments @('req','-x509','-newkey','rsa:3072','-nodes','-sha256','-days','30','-config',$config,'-extensions','ca_extensions','-keyout',$caKey,'-out',$ca)
    Invoke-CertificateTool -Arguments @('req','-new','-newkey','rsa:3072','-nodes','-sha256','-config',$config,'-subj','/CN=Vending LAN Demo','-keyout',$leafKey,'-out',$request)
    Invoke-CertificateTool -Arguments @('x509','-req','-in',$request,'-CA',$ca,'-CAkey',$caKey,'-CAcreateserial','-days','7','-sha256','-extfile',$config,'-extensions','server_extensions','-out',$leaf)
}
Invoke-CertificateTool -Arguments @('verify','-CAfile',$ca,'-verify_ip','192.168.101.15',$leaf)
Invoke-CertificateTool -Arguments @('x509','-in',$leaf,'-checkend','3600','-noout')
Invoke-CertificateTool -Arguments @('x509','-in',$leaf,'-noout','-dates','-fingerprint','-sha256','-ext','subjectAltName')
# The operator explicitly installs only this PUBLIC CA on HONOR after reviewing
# its fingerprint. This script never modifies a system/device trust store.
Write-Output "Public CA for explicit Android trust: $ca"
Invoke-CertificateTool -Arguments @('x509','-in',$ca,'-noout','-fingerprint','-sha256')
& $httpd -f "$toolRoot\httpd.conf" -t
if ($LASTEXITCODE -ne 0) { throw 'Dedicated HTTPS configuration did not validate.' }
if ($Start) {
    if (Get-NetTCPConnection -LocalPort 8443 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 8443 already in use; do not start another listener.' }
    Start-Process -FilePath $httpd -ArgumentList @('-f', "$toolRoot\httpd.conf") -WorkingDirectory $projectRoot -WindowStyle Hidden
}
