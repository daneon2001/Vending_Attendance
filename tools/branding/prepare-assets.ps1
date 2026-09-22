param([Parameter(Mandatory=$true)][string]$DispenserSource)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$sourceDir = Join-Path $root 'docs/branding/source'
$outDir = Join-Path $root 'mobile/public/brand'
New-Item -ItemType Directory -Force -Path $sourceDir,$outDir | Out-Null
$original = Join-Path $sourceDir 'medical-life-dispenser-original.png'
if (!(Test-Path -LiteralPath $original)) { Copy-Item -LiteralPath $DispenserSource -Destination $original }
if ((Get-FileHash -LiteralPath $original).Hash -ne (Get-FileHash -LiteralPath $DispenserSource).Hash) { throw 'Original asset differs; stop.' }
$manifest = @()
function Export-Crop([string]$Source,[string]$Name,[int[]]$Crop,[int]$Width,[int]$Height,[string]$Usage,[bool]$Fit=$false) {
 $img=[Drawing.Image]::FromFile($Source)
 $bmp=New-Object Drawing.Bitmap($Width,$Height)
 $g=[Drawing.Graphics]::FromImage($bmp)
 try {
  $g.Clear([Drawing.Color]::White)
  $g.InterpolationMode=[Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $g.PixelOffsetMode=[Drawing.Drawing2D.PixelOffsetMode]::HighQuality
  $src=New-Object Drawing.Rectangle($Crop[0],$Crop[1],$Crop[2],$Crop[3])
  if($Fit){$scale=[Math]::Min($Width/$Crop[2],$Height/$Crop[3])*0.82;$w=[int]($Crop[2]*$scale);$h=[int]($Crop[3]*$scale);$dst=New-Object Drawing.Rectangle([int](($Width-$w)/2),[int](($Height-$h)/2),$w,$h)}
  else {$dst=New-Object Drawing.Rectangle(0,0,$Width,$Height)}
  $g.DrawImage($img,$dst,$src,[Drawing.GraphicsUnit]::Pixel)
  if($Name -eq 'one-symbol.png') {
   # Remove only the adjacent M fragment in the white margin, left of the 1.
   $g.FillRectangle([Drawing.Brushes]::White,$dst.X,$dst.Y+[int](170*$scale),[int](65*$scale),[int](320*$scale))
  }
  $path=Join-Path $outDir $Name
  $bmp.Save($path,[Drawing.Imaging.ImageFormat]::Png)
  $script:manifest += [pscustomobject]@{file=$Name;source=[IO.Path]::GetFileName($Source);crop=$Crop;width=$Width;height=$Height;bytes=(Get-Item $path).Length;sha256=(Get-FileHash $path -Algorithm SHA256).Hash.ToLower();usage=$Usage}
 } finally {$g.Dispose();$bmp.Dispose();$img.Dispose()}
}
# Source rectangles retain their original aspect ratios. No repainting or product substitution.
Export-Crop $original 'dispenser-portrait.png' @(624,64,882,945) 588 630 'Home and startup portrait'
Export-Crop $original 'dispenser-banner.png' @(630,150,864,480) 720 400 'Home banner, front panel and compartments'
Export-Crop $original 'dispenser-thumb.png' @(624,64,882,945) 168 180 'VM-DEMO-001 only; contextual image'
$logo=Join-Path $root 'public/images/medical-life-one-full.png'
Export-Crop $logo 'one-symbol.png' @(740,250,350,490) 512 512 'Launcher and compact identity' $true
Export-Crop $logo 'one-logo.png' @(0,0,1254,1254) 320 320 'Login and full corporate identity'
$manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $sourceDir '../assets-manifest.json') -Encoding UTF8
Copy-Item -LiteralPath (Join-Path $outDir 'one-symbol.png') -Destination (Join-Path $root 'mobile/public/medical-life-mark.png')
Copy-Item -LiteralPath (Join-Path $outDir 'one-symbol.png') -Destination (Join-Path $root 'mobile/android/app/src/main/res/drawable-nodpi/medical_life_mark.png')
$manifest | Format-Table file,width,height,bytes
