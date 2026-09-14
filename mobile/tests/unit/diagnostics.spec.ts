import { beforeEach, describe, expect, it, vi } from 'vitest'
const f = vi.hoisted(() => ({ request: vi.fn(), app: vi.fn(), network: vi.fn(), gps: vi.fn(), camera: vi.fn(),
  session: vi.fn(), draft: vi.fn(), identity: vi.fn(), terminal: vi.fn(), count: vi.fn() }))
vi.mock('@capacitor/app', () => ({ App: { getInfo: f.app } }))
vi.mock('@capacitor/core', () => ({ Capacitor: { getPlatform: () => 'android' }, CapacitorHttp: { request: f.request } }))
vi.mock('@capacitor/network', () => ({ Network: { getStatus: f.network } }))
vi.mock('@capacitor/geolocation', () => ({ Geolocation: { checkPermissions: f.gps } }))
vi.mock('@capacitor/camera', () => ({ Camera: { checkPermissions: f.camera } }))
vi.mock('@aparajita/capacitor-secure-storage', () => ({ SecureStorage: { get: async () => null } }))
vi.mock('@/app/services', () => ({ credentialStore: { get: f.terminal }, edgeStore: {} }))
vi.mock('@/support/services', () => ({ supportStore: { withDatabase: async (read: (db: unknown) => unknown) => read({ query: async () => ({ values: [{ scope: 'scoped', device_uuid: 'own-device' }] }) }) } }))
vi.mock('@/fieldIdentity/FieldMobileStore', () => ({ FieldMobileStore: class { session = f.session; draft = f.draft; identity = f.identity } }))
vi.mock('@/fieldSupport/FieldOfflineStore', () => ({ fieldDigest: async () => 'fixture-digest', FieldOfflineStore: class {
  cachedScope = async () => ({ key: 'scoped', deviceUuid: 'own-device', origin: 'https://field.test' })
  pendingCount = f.count
  activities = async () => [{ completed_at: '2026-09-10T18:00:00Z' }]
} }))
import { collectDiagnostics } from '@/diagnostics/collect'

beforeEach(() => {
  vi.clearAllMocks(); vi.stubEnv('VITE_FIELD_IDENTITY_BASE_URL', 'https://field.test')
  f.app.mockResolvedValue({ version: '1.0.1-beta.1', build: '4', extra: 'PRIVATE_NATIVE' })
  f.identity.mockResolvedValue(null)
  f.network.mockResolvedValue({ connected: true }); f.gps.mockResolvedValue({ location: 'granted' }); f.camera.mockResolvedValue({ camera: 'prompt' })
  f.terminal.mockResolvedValue(null); f.count.mockResolvedValue(2)
  f.session.mockResolvedValue({ origin: 'https://field.test', token: 'SENSITIVE_SESSION', expires_at: '2099-01-01T00:00:00Z' })
  f.draft.mockResolvedValue({ origin: 'https://field.test', employeeNumber: 'FIXTURE', input: { deviceUuid: 'own-device' } })
  f.request.mockImplementation(async options => ({ status: 200, data: options.url.endsWith('/profile')
    ? { employee: { number: 'FIXTURE' }, phone: 'SENSITIVE_PHONE', private_key: 'SENSITIVE_KEY', devices: [{ device_uuid: 'own-device', status: 'ACTIVE' }] } : 'OK' }))
})
describe('diagnostic adapter without side effects', () => {
  it('uses a proved identity at the new origin while retaining a stale enrollment draft', async () => {
    f.draft.mockResolvedValue({ origin: 'https://old.test', employeeNumber: 'FIXTURE', input: { deviceUuid: 'own-device' } })
    f.identity.mockResolvedValue({ deviceUuid: 'own-device', employeeNumber: 'FIXTURE', verifiedOrigin: 'https://field.test' })
    const rows = await collectDiagnostics()
    expect(rows.find(r => r.label === 'Dispositivo personal')?.value).toBe('Activo')
    expect(rows.find(r => r.label === 'Trabajo de campo pendiente')?.value).toBe('2')
    expect(f.request.mock.calls.map(([r]) => r.url)).toEqual(['https://field.test/up', 'https://field.test/api/v1/field-mobile/profile'])
  })
  it('reads real scoped counters and only projects approved display values', async () => {
    const rows = await collectDiagnostics()
    const value = (label: string) => rows.find(r => r.label === label)?.value
    expect(value('Trabajo de campo pendiente')).toBe('2')
    expect(value('Dispositivo personal')).toBe('Activo')
    expect(value('Asistencias pendientes')).toContain('No aplica')
    expect(value('Servidor de identidad personal')).toBe('Accesible por HTTPS')
    expect(JSON.stringify(rows)).not.toMatch(/SENSITIVE_|PRIVATE_NATIVE|own-device|FIXTURE/)
    expect(f.request.mock.calls.map(([r]) => r.url)).toEqual(['https://field.test/up', 'https://field.test/api/v1/field-mobile/profile'])
    expect(f.request.mock.calls[0][0].headers.Authorization).toBeUndefined()
    expect(f.request.mock.calls.every(([r]) => r.disableRedirects === true)).toBe(true)
  })
  it('does not turn failed storage or transport into zero/active/synced, nor echo exceptions', async () => {
    f.request.mockRejectedValue(new Error('SENSITIVE_SESSION TLS native stack'))
    f.session.mockRejectedValue(new Error('SENSITIVE_PHONE'))
    f.app.mockRejectedValue(new Error('PRIVATE_NATIVE'))
    const rows = await collectDiagnostics()
    expect(rows.find(r => r.label === 'Trabajo de campo pendiente')?.value).toBe('No disponible')
    expect(rows.find(r => r.label === 'Servidor de identidad personal')?.value).toContain('Sin confirmación')
    expect(JSON.stringify(rows)).not.toMatch(/SENSITIVE_|PRIVATE_NATIVE|TLS native stack/)
    expect(f.count).not.toHaveBeenCalled()
  })
  it('requires the current local device, never claiming another phone active', async () => {
    f.draft.mockResolvedValue(null)
    const rows = await collectDiagnostics()
    expect(rows.find(r => r.label === 'Dispositivo personal')?.value).toContain('Sin registro confirmado')
    expect(f.count).not.toHaveBeenCalled()
  })
})
