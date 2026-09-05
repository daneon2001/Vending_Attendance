import type { CapacitorConfig } from '@capacitor/cli'

const apiBaseUrl = process.env.VITE_API_BASE_URL?.trim() ?? ''
const allowLocalHttp = apiBaseUrl.startsWith('http://')

const config: CapacitorConfig = {
  appId: 'com.medicalife.vendingattendance',
  appName: 'Vending Attendance',
  webDir: 'dist',
  server: {
    androidScheme: 'https',
  },
  android: {
    // Required only for an explicit local HTTP API build. HTTPS/release builds remain strict.
    allowMixedContent: allowLocalHttp,
  },
}

export default config
