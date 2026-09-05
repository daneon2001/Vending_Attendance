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
