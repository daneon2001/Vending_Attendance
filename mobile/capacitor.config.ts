import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'com.medicalife.vendingattendance',
  appName: 'Vending Attendance',
  webDir: 'dist',
  server: {
    androidScheme: 'https',
  },
  android: {
    // Shared assets must stay release-safe. MainActivity enables local HTTP only
    // for a debuggable Android APK, independently of Vite/Capacitor CLI env loading.
    allowMixedContent: false,
  },
}

export default config
