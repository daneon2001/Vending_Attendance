export interface ClockState {
  driftSeconds: number
  warning: boolean
}

export class ClockService {
  compare(serverTime: string, localNow = Date.now(), thresholdSeconds = 300): ClockState {
    const server = Date.parse(serverTime)
    if (!Number.isFinite(server)) return { driftSeconds: 0, warning: false }
    const driftSeconds = Math.round((localNow - server) / 1000)
    return { driftSeconds, warning: Math.abs(driftSeconds) > thresholdSeconds }
  }
}
