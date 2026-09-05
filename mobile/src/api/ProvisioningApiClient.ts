import type { ProvisioningInput, ProvisioningResponse } from '@/domain/types'
import { EdgeError } from '@/domain/errors'

const platformFetch: typeof fetch = (input, init) => globalThis.fetch(input, init)

export class ProvisioningApiClient {
  constructor(
    private readonly baseUrl: string,
    private readonly fetcher: typeof fetch = platformFetch,
  ) {}

  async provision(input: ProvisioningInput): Promise<ProvisioningResponse> {
    if (!this.baseUrl) {
      throw new EdgeError('SERVER_ERROR', 'VITE_API_BASE_URL no está configurada.')
    }
    const response = await this.fetcher(new URL('/api/v1/device/provision', this.baseUrl), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(input),
    })
    const body = await response.json().catch(() => ({}))
    if (!response.ok) {
      throw new EdgeError(
        'INVALID_PROVISIONING_TOKEN',
        body.message ?? 'No fue posible provisionar el dispositivo.',
        response.status >= 500,
      )
    }
    return body as ProvisioningResponse
  }
}
