# Android build

Requirements: Node 20+, Android SDK 35, and JDK 21. Java 25 is not supported by this Gradle/Groovy
toolchain. Configure `VITE_API_BASE_URL` through an untracked local `.env`, then run:

```powershell
cd mobile
npm ci
npm run build
npx cap sync android
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
$env:ANDROID_HOME='<local Android SDK path>'
cd android
.\gradlew.bat assembleDebug --no-daemon
```

The debug APK is generated at `mobile/android/app/build/outputs/apk/debug/app-debug.apk` and is ignored
by Git. Release signing is intentionally not configured. Permissions are limited to INTERNET,
ACCESS_COARSE_LOCATION and ACCESS_FINE_LOCATION. Backups are disabled; background location is absent.

## Local debug HTTP / Mixed Content

The WebView origin remains https://localhost. Android cleartext permission and
WebView mixed content are independent controls; allowing the former does not
enable the latter.

The former capacitor.config.ts derived allowMixedContent from process.env.
Vite loaded the local environment for its web build, but a separate cap sync
process did not load that file into process.env. The generated Capacitor asset
therefore contained allowMixedContent=false even for the local HTTP demo.
Conversely, exporting an HTTP URL could relax the shared asset regardless of
the native build type.

The shared Capacitor configuration now always disables mixed content.
MainActivity applies Android's standard WebSettings policy after Capacitor
initializes the WebView, before the UI thread loads the web app:

| APK | Cleartext | WebView mixed content |
| --- | --- | --- |
| Debug / debuggable | Existing debug manifest allows HTTP | MIXED_CONTENT_ALWAYS_ALLOW for the local demo |
| Release / non-debuggable | Main manifest denies HTTP | MIXED_CONTENT_NEVER_ALLOW, even if shared assets were relaxed |

No networkSecurityConfig is needed or added. No trust anchors, certificate
validation, API URL, HMAC, SQLite, Secure Storage or server settings are changed.
The local HTTP allowance is development-only, not a production transport.
Release still requires pilot/production mode, an HTTPS API and explicit signing
through the existing Gradle gate. Do not distribute the debug APK as a release.

References: [Capacitor configuration](https://capacitorjs.com/docs/config),
[Android WebSettings](https://developer.android.com/reference/android/webkit/WebSettings#setMixedContentMode(int)).

### Verification — 2026-09-06 CDMX

- npm run build, npx cap sync android, assembleDebug: PASS.
- Mobile Vitest: 39 PASS, including shared-config and manifest/release guards.
- Android testDebugUnitTest: 4 PASS, covering debuggable/non-debuggable policy
  and proving that a cleartext flag alone cannot enable release mixed content.
- Merged and packaged DEBUG manifests: usesCleartextTraffic=true,
  debuggable=true, no networkSecurityConfig.
- RELEASE source-equivalent review: main usesCleartextTraffic=false, no
  debuggable=true or release relaxation; shared Capacitor asset remains false.
  No release package was built or signed to bypass the existing release gate.
- Reinstalled on the available Android with adb install -r, never uninstall.
  All seven compared SQLite/preference files were byte-identical before launch.
- ANDROID-DEMO-001 retained its identity and authenticated; the server received
  a new heartbeat at 2026-09-07 03:02:59 UTC. Configuration v2 and employees v5
  were SYNCED, pending reported 0.
- Logcat for the new app process: zero Mixed Content blocks, three HTTP
  warnings. Warnings about content that should use HTTPS are expected for this
  local demo and are not blocked requests.
- Fleet health still reported RECENT_NETWORK from a recent error. It was not
  cleared or changed to force ONLINE. This fix does not certify the complete
  Phase 11 online/offline attendance scenario.

### PowerShell: rebuild and reinstall without clearing data

Use the existing local API configuration; do not change VITE_API_BASE_URL.

```powershell
Set-Location 'C:\laragon\www\vending-attendance\mobile'
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Web build failed' }
npx cap sync android
if ($LASTEXITCODE -ne 0) { throw 'Capacitor sync failed' }
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'
Set-Location '.\android'
.\gradlew.bat assembleDebug testDebugUnitTest --console=plain
if ($LASTEXITCODE -ne 0) { throw 'Android build/test failed' }

$androidAdb = "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe"
$androidSerial = 'AX3C026107002120'
& $androidAdb -s $androidSerial get-state
if ($LASTEXITCODE -ne 0) { throw 'Connect and authorize the existing Android first' }
& $androidAdb -s $androidSerial install -r '.\app\build\outputs\apk\debug\app-debug.apk'
if ($LASTEXITCODE -ne 0) { throw 'Do not uninstall or clear data; investigate signing/install failure' }
& $androidAdb -s $androidSerial shell am force-stop 'com.medicalife.vendingattendance'
& $androidAdb -s $androidSerial shell am start -n 'com.medicalife.vendingattendance/.MainActivity'
```

Press Sincronizar. Confirm VM-DEMO-001, the existing identity, configuration v2,
employees v5, and a fresh server heartbeat. Version numbers above describe this
checkpoint; subsequent legitimate configuration changes can advance them.
Verify actual SYNCED state, not just the Android network label “En línea”.
If registration is requested, stop; do not reprovision or clear application data.

Optional sanitized log check, after synchronization:

```powershell
$androidAppPid = (& $androidAdb -s $androidSerial shell pidof com.medicalife.vendingattendance).Trim()
if ($androidAppPid -notmatch '^[0-9]+$') { throw 'App is not running' }
$androidLines = @(& $androidAdb -s $androidSerial logcat --pid=$androidAppPid -d -t 600)
$androidMixed = @($androidLines | Where-Object { $_ -match '(?i)Mixed Content' })
[pscustomobject]@{
    MixedContentBlocks = @($androidMixed | Where-Object { $_ -match '(?i)has been blocked|request.*blocked' }).Count
    CleartextBlocks = @($androidLines | Where-Object { $_ -match 'ERR_CLEARTEXT_NOT_PERMITTED' }).Count
}
```

This prints counts only, not plugin payloads, credentials or complete logcat.
Zero errors alone is not proof of synchronization; corroborate server activity.
