import { deploymentOrigins } from './deployment-origins.mjs'

export interface RuntimeConfig {
  apiBaseUrl: string
  attendanceBatchSize: number
  clockDriftWarningSeconds: number
  httpTimeoutMs: number
  gpsTimeoutMs: number
  deploymentMode: 'development' | 'beta' | 'pilot' | 'production'
}

function httpTimeoutMs(): number {
  const timeout = Number(import.meta.env.VITE_HTTP_TIMEOUT_MS ?? 15_000)
  if (!Number.isFinite(timeout)) {
    throw new Error('VITE_HTTP_TIMEOUT_MS must be a finite number')
  }

  return Math.min(60_000, Math.max(5_000, timeout))
}

function gpsTimeoutMs(): number {
  const timeout = Number(import.meta.env.VITE_GPS_TIMEOUT_MS ?? 45_000)
  if (!Number.isInteger(timeout) || timeout < 15_000 || timeout > 90_000) {
    throw new Error('VITE_GPS_TIMEOUT_MS must be an integer between 15000 and 90000')
  }
  return timeout
}

const deployment = deploymentOrigins(import.meta.env)

export const runtimeConfig: RuntimeConfig = {
  apiBaseUrl: deployment.api,
  attendanceBatchSize: 50,
  clockDriftWarningSeconds: 300,
  httpTimeoutMs: httpTimeoutMs(),
  gpsTimeoutMs: gpsTimeoutMs(),
  deploymentMode: deployment.mode,
}
