import type { DeliveryStatus, LocalTicket, SupportChange } from './types'

const statuses: Record<string, string> = { OPEN: 'Abierto', IN_PROGRESS: 'En atención', WAITING: 'En espera', RESOLVED: 'Resuelto', CLOSED: 'Cerrado', CANCELLED: 'Cancelado' }
export function ticketStatus(value: string | null | undefined): string { return value ? statuses[value] ?? 'Sin información' : 'Pendiente de envío' }
export function deliveryLabel(value: DeliveryStatus | 'DRAFT'): string {
  return { DRAFT: 'Borrador guardado', PENDING: 'Pendiente de envío', SENDING: 'Enviando', ACKNOWLEDGED: 'Confirmado', REJECTED: 'Requiere revisión', BLOCKED: 'Envío detenido' }[value]
}
export function ticketMessage(ticket: LocalTicket): string {
  if (ticket.serverUuid) return `Reporte enviado. Ticket ${ticket.server?.folio ?? 'confirmado'}.`
  if (ticket.status === 'REJECTED' || ticket.status === 'BLOCKED') return 'El reporte sigue guardado en este equipo y requiere revisión del responsable.'
  if (ticket.status === 'DRAFT') return 'Puedes continuar este borrador y agregar fotografías antes de enviarlo.'
  if (ticket.status === 'SENDING') return 'Enviando reporte. Esperando confirmación del servidor.'
  return 'Reporte guardado. Se enviará automáticamente cuando recuperes conexión.'
}
export function eventLabel(event: Pick<SupportChange, 'kind'>): string {
  const kind = event.kind.toLowerCase().replaceAll('.', '_')
  const labels: Record<string, string> = {
    ticket_created: 'Reporte recibido', created: 'Reporte recibido', ticket_assigned: 'Responsable asignado', assigned: 'Responsable asignado',
    status_changed: 'Estado actualizado', ticket_status_changed: 'Estado actualizado', comment_added: 'Nuevo comentario', comment: 'Nuevo comentario',
    evidence_added: 'Fotografía agregada', evidence_confirmed: 'Fotografía confirmada', resolved: 'Reporte resuelto', closed: 'Reporte cerrado',
    recovery: 'Recuperación registrada', sla_warning: 'Aviso de tiempo de atención', sla_breached: 'Tiempo de atención superado',
    verification_linked: 'Verificación vinculada',
  }
  return labels[kind] ?? 'Novedad del reporte'
}
export function dateLabel(value?: string | null): string {
  if (!value || !Number.isFinite(Date.parse(value))) return 'Sin fecha disponible'
  return new Date(value).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
}
export function supportMessage(error: unknown): string {
  const code = typeof error === 'object' && error !== null && 'code' in error ? String(error.code) : ''
  const messages: Record<string, string> = {
    CONTEXT_UNAVAILABLE: 'Conecta el equipo una vez para preparar soporte.',
    INVALID_REPORT: 'Selecciona una categoría y completa el título y la descripción.',
    EVIDENCE_LIMIT: 'El reporte ya tiene el máximo de fotografías permitido.',
    EVIDENCE_TOO_LARGE: 'La fotografía supera el tamaño permitido. Intenta con otra fotografía.',
    CAPTURE_PENDING: 'Hay una fotografía pendiente de recuperar. Retómala o cancela la captura pendiente.',
    CAMERA_CANCELLED: 'No se agregó una fotografía. Puedes intentarlo nuevamente.',
    IDENTITY_CHANGED: 'Este reporte pertenece a otra identidad del equipo. Solicita apoyo al responsable.',
    MACHINE_CHANGED: 'La máquina asociada cambió. El reporte original sigue guardado; solicita revisión al responsable.',
    REPORT_IMMUTABLE: 'El reporte ya fue enviado. Puedes agregar un comentario en su detalle.',
    REPORT_NOT_FOUND: 'No se encontró un reporte autorizado en este equipo.',
    NOT_PROVISIONED: 'El equipo no está configurado. Solicita apoyo al responsable.',
  }
  return messages[code] ?? 'No se pudo completar la operación. Los reportes guardados permanecen en el equipo.'
}
export function verificationResult(value: string): string {
  return ({ PASS: 'Correcto', WARNING: 'Advertencia', FAIL: 'Requiere atención', NOT_AVAILABLE: 'No disponible' } as Record<string, string>)[value] ?? 'Sin resultado'
}
export function checkLabel(value: string): string {
  return ({ DEVICE_ACTIVE: 'Estado del equipo', MACHINE_ASSOCIATION: 'Máquina asociada', HEARTBEAT: 'Comunicación reciente', APP_VERSION: 'Versión de aplicación',
    CONFIGURATION_MANIFEST: 'Configuración del servidor', EMPLOYEE_MANIFEST: 'Catálogo de empleados del servidor', OUTBOX: 'Registros pendientes del servidor',
    CLOCK_DRIFT: 'Fecha y hora', STORAGE: 'Espacio disponible', API_RECEIPT: 'Comunicación confirmada', NETWORK: 'Conexión de red',
    API_REACHABILITY: 'Acceso al servicio de soporte', LOCAL_CONFIGURATION: 'Configuración guardada', LOCAL_EMPLOYEES: 'Empleados guardados', LOCAL_OUTBOX: 'Asistencias pendientes en este equipo',
    GPS_PERMISSION: 'Permiso de ubicación', GPS_AVAILABILITY: 'Ubicación actual', CAMERA_PERMISSION: 'Permiso de cámara', CAMERA_AVAILABILITY: 'Disponibilidad de cámara',
  } as Record<string, string>)[value] ?? 'Comprobación del equipo'
}
