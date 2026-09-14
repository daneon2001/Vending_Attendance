import beta from '../../internal-beta.json'

export const unavailable = 'No disponible'
export const pendingLabel = (count: unknown): string => typeof count === 'number' && Number.isSafeInteger(count) && count >= 0 ? String(count) : unavailable
const mappedLabel = (labels: Record<string, string>, value: unknown, fallback: string): string => typeof value === 'string' && Object.hasOwn(labels, value) ? labels[value] : fallback
export const permissionLabel = (value: unknown): string => mappedLabel({ granted: 'Permitido', denied: 'No permitido', prompt: 'Sin solicitar', 'prompt-with-rationale': 'Sin solicitar' }, value, unavailable)
export const fieldStatusLabel = (value: unknown): string => mappedLabel({ ACTIVE: 'Activo', PENDING: 'Pendiente', REVOKED: 'Revocado', REPLACED: 'Reemplazado' }, value, 'Sin confirmación')
export function applicationLabels(info: { version?: unknown; build?: unknown }) {
  const version = typeof info.version === 'string' && /^\d+\.\d+(?:\.\d+)?(?:-[a-z0-9.-]+)?$/i.test(info.version) ? info.version : unavailable
  const build = typeof info.build === 'string' && /^\d{1,9}$/.test(info.build) ? info.build : unavailable
  return { version, build, environment: version === beta.version && build === String(beta.build) ? beta.label + ' · DEMO local' : 'Compilación no identificada como beta interna' }
}
export function lastConfirmation(values: (string | null | undefined)[]): string {
  const timestamps = values.map(value => value ? Date.parse(value) : NaN).filter(Number.isFinite)
  if (!timestamps.length) return 'Sin confirmación guardada'
  return new Date(Math.max(...timestamps)).toLocaleString('es-MX', { timeZone: 'America/Mexico_City' }) + ' (CDMX)'
}
