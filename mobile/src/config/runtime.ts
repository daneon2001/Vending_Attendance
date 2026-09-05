export interface RuntimeConfig {
  apiBaseUrl: string
  attendanceBatchSize: number
  clockDriftWarningSeconds: number
}

function apiBaseUrl(): string {
  const configured = import.meta.env.VITE_API_BASE_URL?.trim()

  if (!configured) return ''

  const url = new URL(configured)
  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error('VITE_API_BASE_URL must use HTTP or HTTPS')
  }

  return url.toString().replace(/\/$/, '')
}

export const runtimeConfig: RuntimeConfig = {
  apiBaseUrl: apiBaseUrl(),
  attendanceBatchSize: 50,
  clockDriftWarningSeconds: 300,
}
