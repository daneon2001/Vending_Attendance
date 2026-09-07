import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Geolocation, type Position } from '@capacitor/geolocation'
import { CapacitorLocationService } from '@/services/LocationService'
import { safeErrorMessage } from '@/domain/errors'

vi.mock('@capacitor/geolocation', () => ({
  Geolocation: { checkPermissions: vi.fn(), requestPermissions: vi.fn(), getCurrentPosition: vi.fn() },
}))

const timeoutMessage = 'No fue posible obtener tu ubicación. Verifica que la ubicación esté activada e inténtalo nuevamente en un lugar con mejor recepción.'
const freshPosition = (): Position => ({
  timestamp: Date.now(),
  coords: { latitude: 19.432608, longitude: -99.133209, accuracy: 14.501,
    altitude: null, altitudeAccuracy: null, speed: null, heading: null },
})

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime('2026-09-07T03:13:00Z')
  vi.mocked(Geolocation.checkPermissions).mockResolvedValue({ location: 'granted', coarseLocation: 'granted' })
  vi.mocked(Geolocation.getCurrentPosition).mockImplementation(async () => freshPosition())
})
afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); vi.resetAllMocks() })

describe('fresh GPS evidence without a network dependency', () => {
  it.each([15_000, 45_000, 90_000])('requests high accuracy, zero cache age and timeout %s', async (timeout) => {
    const position = freshPosition()
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue(position)
    expect(await new CapacitorLocationService(timeout).capture()).toEqual({
      latitude: position.coords.latitude, longitude: position.coords.longitude,
      accuracy_m: position.coords.accuracy, captured_at: new Date(position.timestamp).toISOString(),
    })
    expect(Geolocation.getCurrentPosition).toHaveBeenCalledExactlyOnceWith({
      enableHighAccuracy: true, maximumAge: 0, timeout,
    })
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each([-1, -5000, -60_000, 1, NaN, Infinity])('rejects stale/future/invalid timestamp offset %s', async (offset) => {
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue({ ...freshPosition(), timestamp: Date.now() + offset })
    await expect(new CapacitorLocationService(45_000).capture()).rejects.toMatchObject({ code: 'GPS_UNAVAILABLE' })
    expect(Geolocation.getCurrentPosition).toHaveBeenCalledOnce()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not replace a missing timestamp with the current clock', async () => {
    vi.mocked(Geolocation.getCurrentPosition).mockResolvedValue({ ...freshPosition(), timestamp: undefined } as unknown as Position)
    await expect(new CapacitorLocationService(45_000).capture()).rejects.toMatchObject({ code: 'GPS_UNAVAILABLE' })
  })

  it('can wait past the former 15 second limit for a genuinely fresh fix', async () => {
    vi.mocked(Geolocation.getCurrentPosition).mockImplementation(() => new Promise((resolve) => {
      setTimeout(() => resolve(freshPosition()), 30_000)
    }))
    const capture = new CapacitorLocationService(45_000).capture()
    await vi.advanceTimersByTimeAsync(30_000)
    expect((await capture).captured_at).toBe('2026-09-07T03:13:30.000Z')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('enforces the application deadline even if the plugin never settles', async () => {
    vi.mocked(Geolocation.getCurrentPosition).mockImplementation(() => new Promise(() => undefined))
    const capture = new CapacitorLocationService(45_000).capture()
    const rejection = expect(capture).rejects.toMatchObject({ code: 'GPS_TIMEOUT', message: timeoutMessage })
    await vi.advanceTimersByTimeAsync(44_999)
    expect(vi.getTimerCount()).toBe(1)
    await vi.advanceTimersByTimeAsync(1)
    await rejection
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each([{ code: 'OS-PLUG-GLOC-0010' }, { code: 3 }, new Error('timeout: private provider stack')])(
    'classifies a real native/browser timeout without leaking its details', async (error) => {
      vi.mocked(Geolocation.getCurrentPosition).mockRejectedValue(error)
      const failure = await new CapacitorLocationService(45_000).capture().catch((reason) => reason)
      expect(failure.code).toBe('GPS_TIMEOUT')
      expect(safeErrorMessage(failure)).toBe(timeoutMessage)
      expect(safeErrorMessage(failure)).not.toMatch(/GPS_TIMEOUT|Capacitor|stack|provider|UNCERTAIN/)
      expect(vi.getTimerCount()).toBe(0)
    },
  )

  it('preserves the fine-location permission requirement', async () => {
    vi.mocked(Geolocation.checkPermissions).mockResolvedValue({ location: 'denied', coarseLocation: 'granted' })
    vi.mocked(Geolocation.requestPermissions).mockResolvedValue({ location: 'denied', coarseLocation: 'granted' })
    await expect(new CapacitorLocationService(45_000).capture()).rejects.toMatchObject({ code: 'GPS_PERMISSION_DENIED' })
    expect(Geolocation.getCurrentPosition).not.toHaveBeenCalled()
  })

  it('does not disguise disabled location as a network timeout', async () => {
    vi.mocked(Geolocation.checkPermissions).mockRejectedValue({ code: 'OS-PLUG-GLOC-0007' })
    await expect(new CapacitorLocationService(45_000).capture()).rejects.toMatchObject({ code: 'GPS_DISABLED' })
    expect(Geolocation.getCurrentPosition).not.toHaveBeenCalled()
  })

  it('logs only capture categories and elapsed milliseconds when explicitly enabled', async () => {
    const info = vi.spyOn(console, 'info').mockImplementation(() => undefined)
    await new CapacitorLocationService(45_000, true).capture()
    expect(info.mock.calls).toEqual([
      ['GPS_CAPTURE_STARTED', JSON.stringify({ elapsed_ms: 0 })],
      ['GPS_CAPTURE_SUCCESS', JSON.stringify({ elapsed_ms: 0 })],
    ])
    expect(JSON.stringify(info.mock.calls)).not.toMatch(/latitude|longitude|accuracy|employee|credential|19\.432608/)
  })

  it('does not add diagnostic logs by default', async () => {
    const info = vi.spyOn(console, 'info').mockImplementation(() => undefined)
    await new CapacitorLocationService(45_000).capture()
    expect(info).not.toHaveBeenCalled()
  })

  it('logs timeout duration without native errors or a success category', async () => {
    const info = vi.spyOn(console, 'info').mockImplementation(() => undefined)
    vi.mocked(Geolocation.getCurrentPosition).mockImplementation(() => new Promise(() => undefined))
    const capture = new CapacitorLocationService(45_000, true).capture()
    const rejection = expect(capture).rejects.toMatchObject({ code: 'GPS_TIMEOUT' })
    await vi.advanceTimersByTimeAsync(45_000)
    await rejection
    expect(info.mock.calls).toEqual([
      ['GPS_CAPTURE_STARTED', JSON.stringify({ elapsed_ms: 0 })],
      ['GPS_CAPTURE_TIMEOUT', JSON.stringify({ elapsed_ms: 45_000 })],
    ])
  })
})
