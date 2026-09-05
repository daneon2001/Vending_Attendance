import type { CredentialStore } from '@/security/DeviceCredentialStore'
import { createDeviceHmacHeaders } from '@/security/hmac'
import { EdgeError } from '@/domain/errors'

const platformFetch: typeof fetch = (input, init) => globalThis.fetch(input, init)

export class DeviceApiClient {
  constructor(
    private readonly baseUrl: string,
    private readonly credentials: CredentialStore,
    private readonly fetcher: typeof fetch = platformFetch,
  ) {}

  async request<T>(method: 'GET' | 'POST', path: string, payload?: unknown): Promise<T> {
    if (!this.baseUrl) {
      throw new EdgeError('SERVER_ERROR', 'VITE_API_BASE_URL no está configurada.')
    }
    const identity = await this.credentials.get()
    if (!identity) throw new EdgeError('NOT_PROVISIONED', 'Este dispositivo no está provisionado.')

    const url = new URL(path, `${this.baseUrl}/`)
    const rawBody = payload === undefined ? '' : JSON.stringify(payload)
    const headers = await createDeviceHmacHeaders({
      deviceUuid: identity.deviceUuid,
      credential: identity.credential,
      method,
      requestTarget: `${url.pathname}${url.search}`,
      rawBody,
    })

    const response = await this.fetcher(url, {
      method,
      headers: {
        Accept: 'application/json',
        ...(payload === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...headers,
      },
      body: payload === undefined ? undefined : rawBody,
    })

    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      const code = response.status === 401 || response.status === 403
        ? 'AUTHENTICATION_FAILED'
        : 'SERVER_ERROR'
      throw new EdgeError(code, body.message ?? `El servidor respondió ${response.status}.`, response.status >= 500)
    }

    return body as T
  }
}
