import { Capacitor, CapacitorHttp } from '@capacitor/core'
import type { HumanIdentityTransport } from './FieldEnrollment'

export class FieldMobileError extends Error {
  constructor(public readonly status: number, public readonly reason = '') {
    super(fieldMobileMessage(status, reason))
  }
}
export function fieldMobileMessage(status: number, reason = ''): string {
  const messages: Record<string, string> = {
    FIELD_OUTSIDE: 'Estás fuera de la zona permitida. Acércate a la máquina para iniciar.',
    FIELD_UNCERTAIN: 'No fue posible confirmar tu ubicación. Intenta nuevamente con mejor recepción GPS.',
    FIELD_NOT_EVALUATED: 'La máquina no tiene una zona vigente disponible. Solicita apoyo.',
    OTP_WAIT: 'Espera antes de solicitar otro código. Hay un límite de envíos por hora.',
    OTP_LOCKED: 'Se alcanzó el límite de intentos. Espera 15 minutos antes de continuar.',
    OTP_INCORRECT: 'El código no es correcto. Revísalo e intenta nuevamente.',
    OTP_EXPIRED: 'El código venció. Solicita uno nuevo.',
    OTP_USED: 'Este código ya se utilizó. Vuelve a consultar el estado antes de continuar.',
    HTTPS_REQUIRED: 'Mi dispositivo requiere HTTPS. Solicita al responsable una conexión segura.',
  }
  if (Object.hasOwn(messages, reason)) return messages[reason]!
  if (status === 401) return 'No fue posible validar tu sesión. Revisa tus datos e inicia sesión nuevamente.'
  if (status === 429) return 'Hay demasiados intentos. Espera unos minutos antes de continuar.'
  if (status === 409) return 'No se puede continuar con este registro. Consulta el estado o solicita apoyo.'
  if (status === 403) return 'No se pudo autorizar esta operación para tu cuenta y dispositivo.'
  if (status === 422) return 'Revisa los datos antes de continuar.'
  return 'No hay confirmación del servidor. Revisa la conexión e intenta nuevamente.'
}
export interface HumanSession { token: string; expires_at: string; origin: string }
export interface HumanSessionStore {
  session(): Promise<HumanSession | null>
  saveSession(value: HumanSession | null): Promise<void>
}
export type NativeRequest = (options: Parameters<typeof CapacitorHttp.request>[0]) => ReturnType<typeof CapacitorHttp.request>

/** No HMAC, cookies, web storage, console logging or HTTP credential fallback. */
export class FieldMobileTransport implements HumanIdentityTransport {
  readonly origin: string
  constructor(baseUrl: string, private readonly store: HumanSessionStore,
    private readonly request: NativeRequest = options => CapacitorHttp.request(options),
    private readonly native = () => Capacitor.getPlatform() === 'android') {
    let url: URL
    try { url = new URL(baseUrl) } catch { throw new FieldMobileError(403, 'HTTPS_REQUIRED') }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
      throw new FieldMobileError(403, 'HTTPS_REQUIRED')
    }
    this.origin = url.origin
  }

  private async call<T>(action: string, data: Record<string, unknown>, token?: string, method = 'POST'): Promise<T> {
    if (!this.native()) throw new FieldMobileError(403)
    try {
      const response = await this.request({
        url: this.origin + '/api/v1/field-mobile/' + action, method, data,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
          ...(token ? { Authorization: 'Bearer ' + token } : {}) },
        connectTimeout: 15000, readTimeout: 15000, disableRedirects: true,
      })
      if (response.status < 200 || response.status >= 300) {
        const zone = response.data?.geofence_result
        throw new FieldMobileError(response.status, ['OUTSIDE', 'UNCERTAIN', 'NOT_EVALUATED'].includes(zone)
          ? 'FIELD_' + zone : typeof response.data?.reason === 'string' ? response.data.reason : '')
      }
      return response.data as T
    } catch (error) {
      if (error instanceof FieldMobileError) throw error
      throw new FieldMobileError(0) // Never echo a native exception (may contain request data).
    }
  }

  async login(email: string, password: string): Promise<void> {
    const session = await this.call<Omit<HumanSession, 'origin'>>('session', { email, password })
    if (!/^fm_[a-f0-9]{64}$/.test(session.token) || !Number.isFinite(Date.parse(session.expires_at))) {
      throw new FieldMobileError(503)
    }
    await this.store.saveSession({ ...session, origin: this.origin })
  }

  async hasSession(): Promise<boolean> {
    const session = await this.store.session()
    return !!session && session.origin === this.origin && Date.parse(session.expires_at) > Date.now()
  }

  async post<T>(action: Parameters<HumanIdentityTransport['post']>[0] | 'activities/capability' | 'activities/challenge' | 'activities/execute', body: Record<string, unknown>): Promise<T> {
    const session = await this.store.session()
    if (!session || session.origin !== this.origin || Date.parse(session.expires_at) <= Date.now()) throw new FieldMobileError(401)
    return this.call<T>(action, body, session.token)
  }

  async logout(): Promise<void> {
    const session = await this.store.session()
    if (session?.origin === this.origin && Date.parse(session.expires_at) > Date.now()) {
      try { await this.call('session', {}, session.token, 'DELETE') }
      catch (error) { if (!(error instanceof FieldMobileError) || error.status !== 401) throw error }
    }
    // Only this human session; retains enrollment draft, terminal credentials and key.
    await this.store.saveSession(null)
  }
}
