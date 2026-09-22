param([switch]$Start)
$ErrorActionPreference = 'Stop'
$projectRoot = 'C:\laragon\www\vending-attendance'
$tlsRoot = Join-Path $projectRoot 'storage\framework\local-https'
$apacheRoot = 'C:\laragon\bin\apache\httpd-2.4.62-240904-win64-VS17'
$ssl = Join-Path $apacheRoot 'bin\openssl.exe'
$httpd = Join-Path $apacheRoot 'bin\httpd.exe'
$configuration = Join-Path $projectRoot 'tools\local-field-https\httpd-lan-192.168.1.82.conf'
# This entrypoint never issues certificates, changes trust or touches old files.
& $ssl verify -CAfile "$tlsRoot\ca.pem" -verify_ip 192.168.1.82 "$tlsRoot\server-192.168.1.82.pem"
if ($LASTEXITCODE -ne 0) { throw 'Existing LAN server certificate failed verification.' }
& $ssl x509 -in "$tlsRoot\server-192.168.1.82.pem" -checkend 3600 -noout
if ($LASTEXITCODE -ne 0) { throw 'Server certificate expires within one hour; authorized renewal required.' }
& $httpd -f $configuration -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration failed validation.' }
if ($Start) {
    $occupied = Get-NetTCPConnection -LocalAddress 192.168.1.82 -LocalPort 8443 -State Listen -ErrorAction SilentlyContinue
    if ($occupied) { throw 'Listener already exists; do not start a duplicate.' }
    Start-Process -FilePath $httpd -ArgumentList @('-f', $configuration) -WorkingDirectory $projectRoot -WindowStyle Hidden
}
