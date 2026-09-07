import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

beforeEach(() => {
  vi.resetModules()
  vi.stubEnv('VITE_DEPLOYMENT_MODE', 'development')
  vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test')
  vi.stubEnv('VITE_HTTP_TIMEOUT_MS', undefined)
  vi.stubEnv('VITE_GPS_TIMEOUT_MS', undefined)
})
afterEach(() => { vi.unstubAllEnvs(); vi.resetModules() })

describe('independent GPS runtime timeout', () => {
  it('defaults GPS to 45 seconds while HTTP remains 15 seconds', async () => {
    const { runtimeConfig } = await import('@/config/runtime')
    expect(runtimeConfig.gpsTimeoutMs).toBe(45_000)
    expect(runtimeConfig.httpTimeoutMs).toBe(15_000)
  })

  it.each(['15000', '45000', '60000', '90000'])('accepts valid integer milliseconds %s', async (value) => {
    vi.stubEnv('VITE_GPS_TIMEOUT_MS', value)
    const { runtimeConfig } = await import('@/config/runtime')
    expect(runtimeConfig.gpsTimeoutMs).toBe(Number(value))
    expect(runtimeConfig.httpTimeoutMs).toBe(15_000)
  })

  it.each(['14999', '90001', '0', '-1', '45000.5', 'Infinity', 'NaN', 'invalid', '', ' '])(
    'fails closed for invalid GPS timeout %s', async (value) => {
      vi.stubEnv('VITE_GPS_TIMEOUT_MS', value)
      await expect(import('@/config/runtime')).rejects.toThrow('VITE_GPS_TIMEOUT_MS')
    },
  )

  it('does not reuse HTTP configuration for GPS', async () => {
    vi.stubEnv('VITE_HTTP_TIMEOUT_MS', '8000')
    const { runtimeConfig } = await import('@/config/runtime')
    expect(runtimeConfig.httpTimeoutMs).toBe(8000)
    expect(runtimeConfig.gpsTimeoutMs).toBe(45_000)
  })

  it.each(['pilot', 'production'])('still requires HTTPS in %s', async (mode) => {
    vi.stubEnv('VITE_DEPLOYMENT_MODE', mode)
    vi.stubEnv('VITE_API_BASE_URL', 'http://192.0.2.10')
    await expect(import('@/config/runtime')).rejects.toThrow('require an HTTPS API URL')
  })
})
