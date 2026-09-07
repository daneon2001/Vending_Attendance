import { describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { GeofenceDiagnosticService } from '@/services/GeofenceDiagnosticService'
import { attendanceLocationLabel, attendanceDistance } from '@/presentation/attendanceResult'

const context = {
 timezone: 'America/Mexico_City', configurationVersion: 2, employeeManifestVersion: 5,
 geofence: { uuid: 'synthetic-zone', version: 1, type: 'CIRCLE' as const, latitude: 19.4, longitude: -99.1, radius_m: 100, minimum_acceptable_accuracy_m: 30, tolerance_m: 5 },
}
describe('read-only applied geofence diagnostics', () => {
 it('reads offline without obtaining GPS or writing anything', async () => {
  const store = { getAttendanceContext: vi.fn(async () => context) }
  const location = { capture: vi.fn() }
  expect(await new GeofenceDiagnosticService(store, location).read()).toEqual(context.geofence)
  expect(location.capture).not.toHaveBeenCalled()
 })
 it.each([
  [19.4, 5, 'INSIDE'], [19.5, 5, 'OUTSIDE'], [19.4, 40, 'UNCERTAIN'],
 ])('uses the unchanged validator for %s / %s', async (latitude, accuracy, expected) => {
  const store = { getAttendanceContext: vi.fn(async () => context) }
  const location = { capture: vi.fn(async () => ({ latitude: Number(latitude), longitude: -99.1, accuracy_m: Number(accuracy), captured_at: '2026-09-07T12:00:00Z' })) }
  const result = await new GeofenceDiagnosticService(store, location).measure()
  expect(result.evaluation.result).toBe(expected)
  expect(attendanceLocationLabel(result.evaluation.result)).not.toMatch(/INSIDE|OUTSIDE|UNCERTAIN/)
  expect(attendanceDistance(result.evaluation.distance_m)).toMatch(/m/)
  expect(store.getAttendanceContext).toHaveBeenCalledTimes(2)
 })
 it('rejects a configuration changing while obtaining GPS', async () => {
  const store = { getAttendanceContext: vi.fn().mockResolvedValueOnce(context).mockResolvedValueOnce({ ...context, configurationVersion: 3 }) }
  const location = { capture: vi.fn(async () => ({ latitude: 19.4, longitude: -99.1, accuracy_m: 5, captured_at: '2026-09-07T12:00:00Z' })) }
  await expect(new GeofenceDiagnosticService(store, location).measure()).rejects.toThrow('zona cambió')
 })
 it('does not invent a zone or result when local configuration or GPS is unavailable', async () => {
  const unavailable = new GeofenceDiagnosticService({ getAttendanceContext: vi.fn().mockRejectedValue(new Error('missing')) }, { capture: vi.fn() })
  await expect(unavailable.read()).rejects.toThrow()
  const denied = new GeofenceDiagnosticService({ getAttendanceContext: vi.fn(async () => context) }, { capture: vi.fn().mockRejectedValue(new Error('permission')) })
  await expect(denied.measure()).rejects.toThrow()
 })
 it('keeps the consultation in terminal information with no editing or event submission', () => {
  const component = readFileSync('src/components/TerminalGeofence.vue', 'utf8')
  const service = readFileSync('src/services/GeofenceDiagnosticService.ts', 'utf8')
  expect(component).toContain('Zona asignada')
  expect(component).toContain('@click="measure"')
  expect(component).toContain('No es seguimiento en tiempo real')
  expect(service).not.toMatch(/enqueueAttendance|fetch\(|\.post\(|\.patch\(|SupportVerificationService/)
  const home = readFileSync('src/views/HomePage.vue', 'utf8')
  expect(home.indexOf('<TerminalGeofence')).toBeGreaterThan(home.indexOf('Información de la terminal'))
 })
})
