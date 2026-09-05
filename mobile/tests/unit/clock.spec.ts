import { describe, expect, it } from 'vitest'
import { ClockService } from '@/services/ClockService'

describe('clock drift evidence', () => {
  const clock = new ClockService()
  it('warns only when the configured absolute threshold is exceeded', () => {
    const server = '2026-09-04T12:00:00.000Z'
    expect(clock.compare(server, Date.parse('2026-09-04T12:04:00.000Z'), 300)).toEqual({ driftSeconds: 240, warning: false })
    expect(clock.compare(server, Date.parse('2026-09-04T12:06:00.000Z'), 300)).toEqual({ driftSeconds: 360, warning: true })
  })

  it('does not rewrite captured timestamps', () => {
    const capturedAt = '2026-09-04T11:59:10.000Z'
    clock.compare('2026-09-04T12:00:00.000Z', Date.parse(capturedAt), 300)
    expect(capturedAt).toBe('2026-09-04T11:59:10.000Z')
  })
})
