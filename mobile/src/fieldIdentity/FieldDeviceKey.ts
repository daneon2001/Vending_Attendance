import { Capacitor, registerPlugin } from '@capacitor/core'

export interface FieldKey {
  deviceUuid: string
  publicKey: string
  keyVersion: number
  keyStore: string
}
export interface FieldDeviceKey {
  inspectKey?(options: { deviceUuid: string; previousOrigin?: string; currentOrigin?: string }): Promise<{
    available: boolean; backing: 'HARDWARE' | 'SOFTWARE' | 'UNKNOWN'; strongBoxAvailable: boolean
    keyVersion?: number; keyFingerprint?: string; originRecoveryAllowed?: boolean
  }>
  createKey(options: { deviceUuid: string }): Promise<FieldKey>
  sign(options: { deviceUuid: string; message: string }): Promise<{ signature: string }>
}
const native = registerPlugin<FieldDeviceKey>('FieldDeviceKey')

/** No WebCrypto/exportable fallback. iOS needs its own native implementation. */
export const fieldDeviceKey: FieldDeviceKey = {
  async inspectKey(options) {
    if (Capacitor.getPlatform() !== 'android') throw new Error('Verificación de dispositivo no disponible.')
    return native.inspectKey!(options)
  },
  async createKey(options) {
    if (Capacitor.getPlatform() !== 'android') throw new Error('Registro de dispositivo no disponible.')
    return native.createKey(options)
  },
  async sign(options) {
    if (Capacitor.getPlatform() !== 'android') throw new Error('Verificación de dispositivo no disponible.')
    return native.sign(options)
  },
}
