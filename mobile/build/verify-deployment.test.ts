import { describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

function gate(variant: string, overrides: Record<string, string> = {}, metadata: unknown = {mode: variant === 'beta' ? 'beta' : 'production', api: 'https://api.example.test', identity: 'https://identity.example.test'}) {
  const dir = mkdtempSync(join(tmpdir(), 'origin-gate-test-'))
  try {
    const file = join(dir, 'deployment.json')
    if (metadata !== null) writeFileSync(file, JSON.stringify(metadata))
    // Do not inherit pilot credentials or environment-dependent endpoints.
    const result = spawnSync(process.execPath, [resolve('build/verify-deployment.mjs'), variant, file], {
      encoding: 'utf8', env: { VITE_DEPLOYMENT_MODE: 'production', VITE_API_BASE_URL: 'https://api.example.test',
        VITE_FIELD_IDENTITY_BASE_URL: 'https://identity.example.test', BETA_API_BASE_URL: 'https://api.example.test',
        BETA_FIELD_IDENTITY_BASE_URL: 'https://identity.example.test', ...overrides }
    })
    return result
  } finally { rmSync(dir, {recursive: true, force: true}) }
}
describe('native deployment gate CLI', () => {
  for (const variant of ['beta', 'release']) {
    it(variant + ' accepts matching metadata', () => expect(gate(variant).status).toBe(0))
    for (const key of ['mode', 'api', 'identity']) {
      it(variant + ' rejects stale ' + key, () => {
        const data = {mode: variant === 'beta' ? 'beta' : 'production', api: 'https://api.example.test', identity: 'https://identity.example.test', [key]: key === 'mode' ? 'development' : 'https://other.example.test'}
        expect(gate(variant, {}, data).status).toBe(1)
      })
    }
    it(variant + ' rejects missing metadata', () => expect(gate(variant, {}, null).status).toBe(1))
    const prefix = variant === 'beta' ? 'BETA_' : 'VITE_'
    for (const suffix of ['API_BASE_URL', 'FIELD_IDENTITY_BASE_URL']) {
      for (const [index, value] of ['', 'http://api.example.test', 'https://*.example.test', 'https://192.0.2.10', 'https://user:pass@api.example.test'].entries()) {
        // Release identity has an intentional validated API fallback for empty input.
        if (variant === 'release' && suffix === 'FIELD_IDENTITY_BASE_URL' && !value) continue
        it(variant + ' rejects ' + suffix + ' case ' + index, () => expect(gate(variant, {[prefix + suffix]: value}).status).toBe(1))
      }
    }
  }
  it('release rejects development mode even with matching metadata', () => {
    expect(gate('release', {VITE_DEPLOYMENT_MODE: 'development'}, {mode: 'development', api: 'https://api.example.test', identity: 'https://identity.example.test'}).status).toBe(1)
  })
  it('release accepts validated API fallback only with matching identity metadata', () => {
    expect(gate('release', {VITE_FIELD_IDENTITY_BASE_URL: ''}, {mode: 'production', api: 'https://api.example.test', identity: 'https://api.example.test'}).status).toBe(0)
  })
  it('errors do not echo invalid input', () => {
    const result = gate('release', {VITE_API_BASE_URL: 'https://user:pass@api.example.test'})
    expect(result.status).toBe(1)
    expect(result.stderr).not.toContain('user:pass')
  })
})
