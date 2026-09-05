# iOS build

The Capacitor project exists at `mobile/ios`. Windows can generate and synchronize it but cannot run
Xcode, CocoaPods signing, simulator, archive, or IPA validation. On a controlled macOS workstation:

```bash
cd mobile
npm ci
npm run build
npx cap sync ios
npx cap open ios
```

Run `pod install` if required by the local CocoaPods setup, choose the Medical Life development team,
and validate on a real device. Bundle id is `com.medicalife.vendingattendance`.
`NSLocationWhenInUseUsageDescription` explains attendance geofence validation. No background location
mode is enabled. Signing identities and provisioning profiles must never be committed.
