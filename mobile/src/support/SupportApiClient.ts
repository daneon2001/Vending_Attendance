import type { CredentialStore } from '@/security/DeviceCredentialStore'
import { canonicalDeviceRequest, signDeviceRequest } from '@/security/hmac'
import { fetchWithTimeout } from '@/api/fetchWithTimeout'
import { assertUuid, SupportError, type ServerEvidence, type ServerTicket, type SupportChange, type SupportChanges, type SupportContext } from './types'

export interface SupportApi {
  context(deviceUuid: string): Promise<SupportContext>
  createTicket(deviceUuid: string, payload: Record<string, unknown>): Promise<{ ticket: ServerTicket }>
  comment(deviceUuid: string, ticketUuid: string, payload: Record<string, unknown>): Promise<Record<string, unknown>>
  reserveEvidence(deviceUuid: string, ticketUuid: string, payload: Record<string, unknown>): Promise<{ evidence: ServerEvidence }>
  uploadEvidence(deviceUuid: string, ticketUuid: string, evidenceUuid: string, bytes: Uint8Array, mime: string): Promise<{ evidence: ServerEvidence }>
  verification(deviceUuid: string, payload: Record<string, unknown>): Promise<Record<string, unknown>>
  changes(deviceUuid: string, sequence: number, limit?: number): Promise<SupportChanges>
}

export async function hashBytes(bytes: Uint8Array): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', Uint8Array.from(bytes).buffer)
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('')
}

export class SupportApiClient implements SupportApi {
  constructor(
    private readonly baseUrl: string,
    private readonly credentials: CredentialStore,
    private readonly fetcher: typeof fetch = (input, init) => globalThis.fetch(input, init),
    private readonly timeoutMs = 30_000,
    private readonly thumbnailLimitBytes = 512_000,
  ) {}

  private async responseBytes(response: Response, maxBytes: number): Promise<Uint8Array> {
    if (Number(response.headers.get('Content-Length') ?? 0) > maxBytes) {
      void response.body?.cancel().catch(() => undefined)
      throw new SupportError('RESPONSE_TOO_LARGE', 'La respuesta supera el tamaño permitido.')
    }
    const reader = response.body?.getReader()
    if (!reader) return new Uint8Array()
    let timer: ReturnType<typeof setTimeout> | undefined
    const parts: Uint8Array[] = []
    let size = 0
    const read = async () => {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        size += value.length
        if (size > maxBytes) throw new SupportError('RESPONSE_TOO_LARGE', 'La respuesta supera el tamaño permitido.')
        parts.push(value)
      }
      const bytes = new Uint8Array(size)
      let offset = 0
      for (const part of parts) { bytes.set(part, offset); offset += part.length }
      return bytes
    }
    try {
      return await Promise.race([read(), new Promise<never>((_resolve, reject) => {
        timer = setTimeout(() => reject(new SupportError('NETWORK_TIMEOUT', 'La respuesta del servidor no se completó.', true)), this.timeoutMs)
      })])
    } finally {
      if (timer) clearTimeout(timer)
      void reader.cancel().catch(() => undefined)
      reader.releaseLock()
    }
  }

  private async responseJson<T>(response: Response): Promise<T> {
    const bytes = await this.responseBytes(response, 2_000_000)
    try { return JSON.parse(new TextDecoder().decode(bytes)) as T }
    catch { throw new SupportError('INVALID_RESPONSE', 'No se recibió una confirmación válida.', true) }
  }

  private async send(deviceUuid: string, path: string, body?: Uint8Array, mime = 'application/json'): Promise<Response> {
    const identity = await this.credentials.get()
    if (!identity) throw new SupportError('NOT_PROVISIONED', 'El equipo no está configurado.')
    if (identity.deviceUuid !== deviceUuid) throw new SupportError('IDENTITY_CHANGED', 'El reporte pertenece a otra identidad del equipo.')
    if (!this.baseUrl || !path.startsWith('/api/v1/device/support/')) throw new SupportError('INVALID_ENDPOINT', 'El servicio de soporte no está configurado.')
    const url = new URL(path, this.baseUrl)
    const origin = new URL(this.baseUrl)
    if (url.origin !== origin.origin || !['https:', 'http:'].includes(url.protocol) || url.username || url.password) {
      throw new SupportError('INVALID_ENDPOINT', 'El servicio de soporte no está configurado.')
    }
    const method = body === undefined ? 'GET' : 'POST'
    const timestamp = String(Math.floor(Date.now() / 1000))
    const nonce = crypto.randomUUID()
    const canonical = canonicalDeviceRequest(method, `${url.pathname}${url.search}`, timestamp, nonce, await hashBytes(body ?? new Uint8Array()))
    const response = await fetchWithTimeout(this.fetcher, url, {
      method, redirect: 'error',
      headers: {
        Accept: 'application/json',
        'X-Device-Id': identity.deviceUuid,
        'X-Timestamp': timestamp, 'X-Nonce': nonce,
        'X-Signature': await signDeviceRequest(identity.credential, canonical),
        ...(body ? { 'Content-Type': mime } : {}),
      },
      body: body === undefined ? undefined : Uint8Array.from(body).buffer,
    }, this.timeoutMs)
    if (!response.ok) {
      const error: Record<string, unknown> = await this.responseJson<Record<string, unknown>>(response).catch(() => ({}))
      const supplied = error.code ?? error.error ?? error.reason
      const code = typeof supplied === 'string' && /^[A-Z][A-Z0-9_]{0,79}$/.test(supplied) ? supplied : `HTTP_${response.status}`
      const retry = response.headers.get('Retry-After')
      const seconds = retry === null ? null : /^\d+$/.test(retry) ? Number(retry) : Math.ceil((Date.parse(retry) - Date.now()) / 1000)
      // Do not expose backend messages, internal paths or provider responses in a shared terminal.
      throw new SupportError(code, response.status === 401 || response.status === 403
        ? 'El equipo no tiene autorización para esta operación.'
        : 'No fue posible completar la operación de soporte.',
      response.status >= 500 || [408, 425, 429].includes(response.status), response.status,
      seconds !== null && Number.isFinite(seconds) ? Math.max(1, seconds) : null)
    }
    return response
  }

  private async json<T>(deviceUuid: string, suffix: string, payload?: Record<string, unknown>): Promise<T> {
    const body = payload === undefined ? undefined : new TextEncoder().encode(JSON.stringify(payload))
    const response = await this.send(deviceUuid, `/api/v1/device/support/${suffix}`, body)
    return this.responseJson<T>(response)
  }

  context(deviceUuid: string): Promise<SupportContext> { return this.json(deviceUuid, 'context') }
  createTicket(deviceUuid: string, payload: Record<string, unknown>): Promise<{ ticket: ServerTicket }> { return this.json(deviceUuid, 'tickets', payload) }
  tickets(deviceUuid: string, page = 1): Promise<{ tickets: ServerTicket[]; pagination: { page: number; last_page: number; total: number; per_page: number } }> {
    return this.json(deviceUuid, `tickets?page=${Math.max(1, Math.floor(page))}`)
  }
  ticket(deviceUuid: string, ticketUuid: string): Promise<{ ticket: ServerTicket; events: SupportChange[]; evidence: ServerEvidence[] }> {
    return this.json(deviceUuid, `tickets/${assertUuid(ticketUuid)}`)
  }
  comment(deviceUuid: string, ticketUuid: string, payload: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.json(deviceUuid, `tickets/${assertUuid(ticketUuid)}/comments`, payload)
  }
  reserveEvidence(deviceUuid: string, ticketUuid: string, payload: Record<string, unknown>): Promise<{ evidence: ServerEvidence }> {
    return this.json(deviceUuid, `tickets/${assertUuid(ticketUuid)}/evidence`, payload)
  }
  async uploadEvidence(deviceUuid: string, ticketUuid: string, evidenceUuid: string, bytes: Uint8Array, mime: string): Promise<{ evidence: ServerEvidence }> {
    const response = await this.send(deviceUuid, `/api/v1/device/support/tickets/${assertUuid(ticketUuid)}/evidence/${assertUuid(evidenceUuid)}/content`, bytes, mime)
    return this.responseJson<{ evidence: ServerEvidence }>(response)
  }
  verification(deviceUuid: string, payload: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.json(deviceUuid, 'verifications', payload)
  }
  changes(deviceUuid: string, sequence: number, limit = 50): Promise<SupportChanges> {
    if (!Number.isSafeInteger(sequence) || sequence < 0) throw new SupportError('INVALID_CURSOR', 'No se pudo leer la posición de sincronización.')
    return this.json(deviceUuid, `changes?after_sequence=${sequence}&limit=${Math.min(100, Math.max(1, Math.floor(limit)))}`)
  }
  async thumbnail(deviceUuid: string, ticketUuid: string, evidenceUuid: string): Promise<Blob> {
    const response = await this.send(deviceUuid, `/api/v1/device/support/tickets/${assertUuid(ticketUuid)}/evidence/${assertUuid(evidenceUuid)}/thumbnail`)
    const mime = response.headers.get('Content-Type')?.split(';')[0] ?? ''
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(mime)) throw new SupportError('INVALID_THUMBNAIL', 'La vista previa no está disponible.')
    if (Number(response.headers.get('Content-Length') ?? 0) > this.thumbnailLimitBytes) {
      await response.body?.cancel()
      throw new SupportError('THUMBNAIL_TOO_LARGE', 'La vista previa supera el tamaño permitido.')
    }
    try {
      return new Blob([Uint8Array.from(await this.responseBytes(response, this.thumbnailLimitBytes)).buffer], { type: mime })
    } catch (error) {
      if (error instanceof SupportError && error.code === 'RESPONSE_TOO_LARGE') throw new SupportError('THUMBNAIL_TOO_LARGE', 'La vista previa supera el tamaño permitido.')
      throw error
    }
  }
}
