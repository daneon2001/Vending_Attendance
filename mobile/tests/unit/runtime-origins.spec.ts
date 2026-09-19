import { afterEach, describe, expect, it, vi } from 'vitest'
import { deploymentOrigins } from '../../src/config/deployment-origins.mjs'

afterEach(() => { vi.unstubAllEnvs(); vi.resetModules() })

describe('runtime origin startup', () => {
  const invalid = ['', 'http://api.example.test', 'https://192.0.2.10', 'https://127.0.0.1', 'https://[::1]',
    'https://user:pass@api.example.test', 'https://*.example.test', 'https://localhost', 'broken',
    'https://api.example.test/path', 'https://api.example.test?q=1', 'https://api.example.test/#fragment']
  for (const mode of ['beta', 'pilot', 'production']) {
    invalid.forEach((value, index) => it(mode + ' API fails at startup, case ' + index, async () => {
      vi.stubEnv('VITE_DEPLOYMENT_MODE', mode)
      vi.stubEnv('VITE_API_BASE_URL', value)
      vi.stubEnv('VITE_FIELD_IDENTITY_BASE_URL', '')
      await expect(import('../../src/config/runtime')).rejects.toThrow()
    }))
    invalid.filter(Boolean).forEach((value, index) => it(mode + ' explicit identity fails at startup, case ' + index, async () => {
      vi.stubEnv('VITE_DEPLOYMENT_MODE', mode)
      vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test')
      vi.stubEnv('VITE_FIELD_IDENTITY_BASE_URL', value)
      await expect(import('../../src/config/runtime')).rejects.toThrow()
    }))
    it(mode + ' accepts DNS with port and validated API fallback', async () => {
      vi.stubEnv('VITE_DEPLOYMENT_MODE', mode)
      vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test:8443/')
      vi.stubEnv('VITE_FIELD_IDENTITY_BASE_URL', '')
      expect((await import('../../src/config/runtime')).runtimeConfig.apiBaseUrl).toBe('https://api.example.test:8443')
      expect(deploymentOrigins({ VITE_DEPLOYMENT_MODE: mode, VITE_API_BASE_URL: 'https://api.example.test' }).identity).toBe('https://api.example.test')
    })
  }
  it('preserves explicitly configured local HTTPS IP and distinct identity', () => {
    expect(deploymentOrigins({ VITE_DEPLOYMENT_MODE: 'development', VITE_API_BASE_URL: 'https://192.0.2.10:8443', VITE_FIELD_IDENTITY_BASE_URL: 'https://192.0.2.11:8443' }))
      .toEqual({ mode: 'development', api: 'https://192.0.2.10:8443', identity: 'https://192.0.2.11:8443' })
  })
  it('preserves default development without a backend', () => {
    expect(deploymentOrigins({})).toEqual({ mode: 'development', api: '', identity: '' })
  })
})
