import { describe, it, expect } from 'vitest'
import { betaOrigins } from './beta-config'

describe('beta origins', () => {
  it('normalizes concrete DNS origins and preserves explicit ports', () => {
    expect(betaOrigins({ BETA_API_BASE_URL: 'https://API.example.test:8443/', BETA_FIELD_IDENTITY_BASE_URL: 'https://identity.example.test/' }))
      .toEqual({ mode: 'beta', api: 'https://api.example.test:8443', identity: 'https://identity.example.test' })
  })
  const invalid = ['', 'http://api.example.test', 'https://*.example.test', 'https://192.0.2.10', 'https://127.0.0.1',
    'https://[::1]', 'https://localhost', 'https://api.localhost', 'https://api.local', 'https://api.invalid',
    'https://user:pass@api.example.test', 'https://api.example.test/path', 'https://api.example.test?q=1',
    'https://api.example.test/#fragment', 'not-an-origin', 'https://api.example.test/../', 'https://api.example.test#',
    'https://api.example.test?', 'https://@api.example.test', 'https://api_.example.test']
  for (const key of ['BETA_API_BASE_URL', 'BETA_FIELD_IDENTITY_BASE_URL']) {
    invalid.forEach((value, index) => it(key + ' rejects invalid case ' + index, () => {
      expect(() => betaOrigins({ BETA_API_BASE_URL: 'https://api.example.test', BETA_FIELD_IDENTITY_BASE_URL: 'https://identity.example.test', [key]: value })).toThrow()
    }))
  }
})
