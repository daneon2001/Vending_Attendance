export interface RuntimeConfig {
  apiBaseUrl: string
  attendanceBatchSize: number
  clockDriftWarningSeconds: number
  httpTimeoutMs: number
  deploymentMode: 'development' | 'pilot' | 'production'
}

function deploymentMode(): RuntimeConfig['deploymentMode'] {
  const mode = (import.meta.env.VITE_DEPLOYMENT_MODE ?? 'development').trim().toLowerCase()
  if (!['development', 'pilot', 'production'].includes(mode)) {
    throw new Error('VITE_DEPLOYMENT_MODE must be development, pilot, or production')
  }

  return mode as RuntimeConfig['deploymentMode']
}

function apiBaseUrl(mode: RuntimeConfig['deploymentMode']): string {
  const configured = import.meta.env.VITE_API_BASE_URL?.trim()

  if (!configured) return ''

  const url = new URL(configured)
  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error('VITE_API_BASE_URL must use HTTP or HTTPS')
  }
  if (mode !== 'development' && url.protocol !== 'https:') {
    throw new Error('Pilot and production builds require an HTTPS API URL')
  }

  return url.toString().replace(/\/$/, '')
}

function httpTimeoutMs(): number {
  const timeout = Number(import.meta.env.VITE_HTTP_TIMEOUT_MS ?? 15_000)
  if (!Number.isFinite(timeout)) {
    throw new Error('VITE_HTTP_TIMEOUT_MS must be a finite number')
  }

  return Math.min(60_000, Math.max(5_000, timeout))
}

const mode = deploymentMode()

export const runtimeConfig: RuntimeConfig = {
  apiBaseUrl: apiBaseUrl(mode),
  attendanceBatchSize: 50,
  clockDriftWarningSeconds: 300,
  httpTimeoutMs: httpTimeoutMs(),
  deploymentMode: mode,
}
