import { describe, expect, it } from 'vitest'
import fixtures from '../../../tests/Fixtures/vending-geofence-validation.json'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'

const service = new GeofenceValidationService()

describe('geofence parity fixtures', () => {
  for (const fixture of fixtures) {
    it(fixture.name, () => {
      const result = service.validate(
        { uuid: 'fixture', version: 1, type: 'CIRCLE', ...fixture.geofence },
        { ...fixture.location, captured_at: '2026-09-04T12:00:00.000Z' },
      )
      expect(result.result).toBe(fixture.result)
      expect(result.reason).toBe(fixture.reason)
    })
  }

  it('rejects 0,0 and negative accuracy', () => {
    const geofence = { uuid: 'g', version: 1, type: 'CIRCLE' as const, latitude: 19, longitude: -99, radius_m: 50, minimum_acceptable_accuracy_m: null, tolerance_m: 0 }
    expect(() => service.validate(geofence, { latitude: 0, longitude: 0, accuracy_m: 1, captured_at: '' })).toThrow('0,0')
    expect(() => service.validate(geofence, { latitude: 19, longitude: -99, accuracy_m: -1, captured_at: '' })).toThrow('negativa')
  })
})
