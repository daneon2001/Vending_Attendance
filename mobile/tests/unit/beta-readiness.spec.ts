import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { startApplication } from '@/app/startup'
import { applicationLabels, fieldStatusLabel, lastConfirmation, pendingLabel, permissionLabel } from '@/diagnostics/presentation'

describe('independent personal startup', () => {
  const ports = (terminal: boolean) => ({ initializeStorage: vi.fn().mockResolvedValue(undefined), hasTerminal: vi.fn().mockResolvedValue(terminal),
    navigate: vi.fn().mockResolvedValue(undefined), startTerminal: vi.fn().mockResolvedValue(undefined), startPersonalNetwork: vi.fn().mockResolvedValue(undefined), startSupport: vi.fn().mockResolvedValue(undefined) })
  it('opens personal Home without provisioning a terminal or starting terminal sync', async () => {
    const p = ports(false); await startApplication(p)
    expect(p.navigate).toHaveBeenCalledExactlyOnceWith('/home')
    expect(p.startPersonalNetwork).toHaveBeenCalledOnce()
    expect(p.startTerminal).not.toHaveBeenCalled(); expect(p.startSupport).not.toHaveBeenCalled()
  })
  it('preserves existing terminal startup and isolates support startup failures', async () => {
    const p = ports(true); p.startSupport.mockRejectedValue(new Error('private native error'))
    await startApplication(p)
    expect(p.startTerminal).toHaveBeenCalledOnce(); expect(p.startSupport).toHaveBeenCalledOnce()
    expect(p.startPersonalNetwork).not.toHaveBeenCalled()
    expect(p.navigate).toHaveBeenCalledExactlyOnceWith('/home')
  })
  it('never treats an unreadable identity as permission to provision', async () => {
    const p = ports(false); p.hasTerminal.mockRejectedValue(new Error('storage error'))
    await startApplication(p)
    expect(p.navigate).toHaveBeenCalledExactlyOnceWith('/startup-error')
    expect(p.startTerminal).not.toHaveBeenCalled()
  })
})
describe('safe diagnostic presentation', () => {
  it('uses installed metadata, not a hardcoded claim of beta', () => {
    expect(applicationLabels({ version: '1.0.1-beta.1', build: '5' }).environment).toContain('Beta interna')
    expect(applicationLabels({ version: '1.0', build: '1' }).environment).toContain('no identificada')
    expect(applicationLabels({ version: 'secret error', build: 'token value' })).toEqual({ version: 'No disponible', build: 'No disponible', environment: 'Compilación no identificada como beta interna' })
  })
  it('does not fabricate zero or print untrusted native fields', () => {
    for (const value of [null, undefined, -1, '0', NaN, 'private-data']) expect(pendingLabel(value)).toBe('No disponible')
    expect(pendingLabel(0)).toBe('0'); expect(pendingLabel(3)).toBe('3')
    expect(permissionLabel('granted')).toBe('Permitido'); expect(permissionLabel('secret')).toBe('No disponible')
    expect(fieldStatusLabel('ACTIVE')).toBe('Activo'); expect(fieldStatusLabel('REVOKED')).toBe('Revocado')
    expect(fieldStatusLabel('private-key')).toBe('Sin confirmación')
    expect(fieldStatusLabel('__proto__')).toBe('Sin confirmación')
    expect(permissionLabel('constructor')).toBe('No disponible')
  })
  it('uses confirmed times in CDMX without inventing a sync', () => {
    expect(lastConfirmation([null, 'invalid'])).toBe('Sin confirmación guardada')
    expect(lastConfirmation(['2026-09-10T18:00:00Z'])).toContain('CDMX')
  })
  it('keeps beta opt-in and explicitly blocks beta metadata in release', () => {
    const gradle = readFileSync('android/app/build.gradle', 'utf8')
    expect(gradle).toContain("gradleProperty('internalBeta')")
    expect(gradle).toContain('versionCode internalBeta ? betaMetadata.build as Integer : 1')
    expect(gradle).toContain('versionName internalBeta ? betaMetadata.version : "1.0"')
    expect(gradle).toContain('Internal beta metadata is DEBUG only')
  })
  it('uses official product metadata, Spanish document language and does not disable zoom', () => {
    const html = readFileSync('index.html', 'utf8')
    expect(html).toContain('lang="es-MX"')
    expect(html).toContain('Vending Attendance · Medical Life')
    expect(html).toContain('/medical-life-mark.png')
    expect(html).not.toMatch(/Ionic App|user-scalable=no|maximum-scale=1/)
  })
  it('does not add sensitive exports, captures, signatures or sync to diagnostics', () => {
    const source = readFileSync('src/diagnostics/collect.ts', 'utf8')
    expect(source).not.toMatch(/console\.|\.sign\(|\.createKey\(|\.sync\(|getCurrentPosition|takePhoto|otp-send|otp-verify/)
    expect(source).toContain('disableRedirects: true')
    expect(source).toContain('checkPermissions()')
    expect(source).not.toMatch(/\.initialize\(|\.cachedScope\(/)
  })
})
