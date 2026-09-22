param([switch]$Start)
$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$tlsRoot = Join-Path $projectRoot 'storage\framework\local-https'
$apacheRoot = 'C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17'
$ssl = Join-Path $apacheRoot 'bin\openssl.exe'
$httpd = Join-Path $apacheRoot 'bin\httpd.exe'
$configuration = Join-Path $PSScriptRoot 'httpd-lan-192.168.1.87.conf'
$leaf = Join-Path $tlsRoot 'server-192.168.1.87.pem'
$request = Join-Path $tlsRoot 'server-192.168.1.87.csr'
$certificateConfig = Join-Path $PSScriptRoot 'openssl-lan-192.168.1.87.cnf'
# Reuse the established CA and server key. Never generate or replace identity keys.
foreach ($name in @('ca.pem','ca.key','server-192.168.1.80.key')) {
    if (!(Test-Path -LiteralPath (Join-Path $tlsRoot $name))) { throw 'Existing local TLS material is required.' }
}
if (!(Get-NetIPAddress -AddressFamily IPv4 | Where-Object IPAddress -eq '192.168.1.87')) {
    throw 'The configured LAN address is not present.'
}
function Invoke-LocalSsl([string[]]$Arguments) {
    & $ssl @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Local TLS verification or issuance failed.' }
}
Invoke-LocalSsl -Arguments @('x509','-in',"$tlsRoot\ca.pem",'-checkend','604800','-noout')
if (!(Test-Path -LiteralPath $leaf)) {
    if (Test-Path -LiteralPath $request) { throw 'Partial LAN87 certificate request exists; inspect before continuing.' }
    Invoke-LocalSsl -Arguments @('req','-new','-key',"$tlsRoot\server-192.168.1.80.key",'-config',$certificateConfig,'-subj','/CN=Vending LAN Demo','-out',$request)
    $serial = '0x'+[guid]::NewGuid().ToString('N')
    Invoke-LocalSsl -Arguments @('x509','-req','-in',$request,'-CA',"$tlsRoot\ca.pem",'-CAkey',"$tlsRoot\ca.key",'-set_serial',$serial,'-days','7','-sha256','-extfile',$certificateConfig,'-extensions','server_extensions','-out',$leaf)
}
Invoke-LocalSsl -Arguments @('verify','-CAfile',"$tlsRoot\ca.pem",'-verify_ip','192.168.1.87',$leaf)
Invoke-LocalSsl -Arguments @('x509','-in',$leaf,'-checkend','3600','-noout')
& $httpd -f $configuration -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration failed validation.' }
if ($Start) {
    if (Get-NetTCPConnection -LocalAddress 192.168.1.87 -LocalPort 8443 -State Listen -ErrorAction SilentlyContinue) {
        Write-Output 'LAN87 listener already exists; no duplicate started.'
    } else {
        Start-Process -FilePath $httpd -ArgumentList @('-f', $configuration) -WorkingDirectory $projectRoot -WindowStyle Hidden
        Write-Output 'LAN87 HTTPS listener started.'
    }
}
