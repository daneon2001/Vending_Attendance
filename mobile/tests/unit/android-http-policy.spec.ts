import { readFileSync } from 'node:fs'
import { describe, expect, it, vi, afterEach } from 'vitest'

const read = (path: string) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8')

afterEach(() => {
  vi.unstubAllEnvs()
  vi.resetModules()
})

describe('Android local HTTP policy', () => {
  it.each(['', 'http://192.0.2.10', 'https://api.example.test'])(
    'keeps shared Capacitor mixed content disabled even with process API URL %s',
    async (apiUrl) => {
      vi.stubEnv('VITE_API_BASE_URL', apiUrl)
      vi.resetModules()
      const { default: config } = await import('../../capacitor.config')
      expect(config.android?.allowMixedContent).toBe(false)
      expect(config.server?.androidScheme).toBe('https')
      expect(config.server?.cleartext).not.toBe(true)
    },
  )

  it('allows cleartext only in the debug overlay, with a system-only secure base', () => {
    const main = read('android/app/src/main/AndroidManifest.xml')
    const debug = read('android/app/src/debug/AndroidManifest.xml')
    expect(main).toContain('android:usesCleartextTraffic="false"')
    expect(main).not.toContain('android:debuggable="true"')
    expect(main).toContain('@xml/secure_network_policy')
    expect(debug).toContain('android:usesCleartextTraffic="true"')
    expect(debug).toContain('tools:replace="android:usesCleartextTraffic,android:networkSecurityConfig"')
    const secure = read('android/app/src/main/res/xml/secure_network_policy.xml')
    expect(secure).toContain('cleartextTrafficPermitted="false"')
    expect(secure).not.toContain('src="user"')
  })

  it('retains the independent release HTTPS, deployment mode and signing gates', () => {
    const gradle = read('android/app/build.gradle')
    expect(gradle).toContain("verifyDeployment('release')")
    expect(gradle).toContain("file('../../build/verify-deployment.mjs')")
    expect(gradle).toContain('!releaseSigningConfigured')
    expect(gradle).toContain("dependsOn tasks.named('verifyPilotReleaseConfiguration')")
  })
})
