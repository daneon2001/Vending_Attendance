import { canUse } from './navigation.js';
import { friendlyError } from './labels.js';

// Presentation labels only. Backend supplies permitted workflow transitions.
const labels = Object.freeze({
    OPEN: 'Abierto', IN_PROGRESS: 'En atención', WAITING: 'En espera',
    RESOLVED: 'Resuelto', CLOSED: 'Cerrado', CANCELLED: 'Cancelado',
    LOW: 'Baja', MEDIUM: 'Media', HIGH: 'Alta', CRITICAL: 'Crítica',
    NORMAL: 'Normal', URGENT: 'Urgente',
    MANUAL_WEB: 'Reporte web', MANUAL_MOBILE: 'Reporte desde el equipo',
    DEVICE_VERIFICATION: 'Verificación del equipo', AUTOMATED_ALERT: 'Alerta automática',
    INTEGRATION: 'Integración', SYSTEM: 'Sistema',
    PASS: 'Correcto', WARNING: 'Advertencia', FAIL: 'Requiere atención',
    NOT_AVAILABLE: 'No disponible', CONFIRMED: 'Confirmada', PENDING: 'Pendiente',
    INSIDE: 'Dentro de la geocerca', OUTSIDE: 'Fuera de la geocerca', UNCERTAIN: 'Ubicación incierta',
});

export const supportStatuses = ['OPEN', 'IN_PROGRESS', 'WAITING', 'RESOLVED', 'CLOSED', 'CANCELLED'];
export const supportSeverities = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
export const supportSources = ['MANUAL_WEB', 'MANUAL_MOBILE', 'DEVICE_VERIFICATION', 'AUTOMATED_ALERT', 'INTEGRATION', 'SYSTEM'];
export const supportAdvancedKeys = ['vending_machine_id', 'device_id', 'category', 'source', 'from', 'to'];
const ownLabel = (map, value, fallback) => Object.hasOwn(map, value) ? map[value] : fallback;
export const supportLabel = (value) => ownLabel(labels, value, 'Sin información');
export const canSupport = (matrix, action = 'view') => canUse(matrix, 'support', action, true);

export function supportBadgeClass(value) {
    if (['RESOLVED', 'CLOSED', 'PASS', 'CONFIRMED'].includes(value)) return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    if (['HIGH', 'CRITICAL', 'FAIL', 'URGENT'].includes(value)) return 'bg-rose-50 text-rose-800 dark:bg-rose-950 dark:text-rose-200';
    if (['WAITING', 'WARNING', 'MEDIUM', 'PENDING'].includes(value)) return 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-200';
    return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200';
}

export function supportEventLabel(kind) {
    return ownLabel({
        'support.ticket.created': 'Reporte creado',
        'support.comment.created': 'Comentario agregado',
        'support.ticket.assigned': 'Responsable actualizado',
        'support.ticket.status_changed': 'Estado actualizado',
        'support.ticket.resolved': 'Incidencia resuelta',
        'support.ticket.closed': 'Reporte cerrado',
        'support.evidence.created': 'Evidencia agregada',
        'support_activity.note_added': 'Nota de actividad agregada',
        'support_activity.assigned': 'Actividad asignada',
        'support_activity.completed': 'Actividad completada',
        'support_activity.cancelled': 'Actividad cancelada',
        'support_activity.evidence_added': 'Evidencia de actividad agregada',
        RECOVERY: 'Recuperación detectada',
        VERIFICATION_LINKED: 'Verificación relacionada',
        AUTOMATION_LINKED: 'Alerta relacionada',
        'support.sla.warning': 'Tiempo objetivo próximo',
        'support.sla.breached': 'Tiempo objetivo excedido',
    }, kind, 'Actividad del reporte');
}

export function supportCheckLabel(key) {
    return ownLabel({
        DEVICE_ACTIVE: 'Estado del equipo', MACHINE_ASSOCIATION: 'Máquina asociada',
        API_REACHABILITY: 'Conexión con el servicio', HEARTBEAT: 'Comunicación reciente',
        API_RECEIPT: 'Recepción de la revisión', NETWORK: 'Conexión de red',
        LOCAL_CONFIGURATION: 'Configuración guardada en el equipo', LOCAL_EMPLOYEES: 'Empleados guardados en el equipo',
        LOCAL_OUTBOX: 'Envíos pendientes en el equipo',
        APP_VERSION: 'Versión de aplicación', CONFIGURATION_MANIFEST: 'Configuración',
        EMPLOYEE_MANIFEST: 'Catálogo de empleados', OUTBOX: 'Reportes pendientes de envío',
        STORAGE: 'Almacenamiento', CLOCK_DRIFT: 'Hora del equipo',
        GPS_PERMISSION: 'Permiso de ubicación', GPS_AVAILABILITY: 'Ubicación disponible',
        CAMERA_PERMISSION: 'Permiso de cámara', CAMERA_AVAILABILITY: 'Cámara disponible',
        NETWORK_STATE: 'Conexión de red',
    }, key, 'Comprobación del equipo');
}

export function supportOperationUuid(cryptoSource = globalThis.crypto) {
    if (typeof cryptoSource?.randomUUID === 'function') return cryptoSource.randomUUID();
    if (typeof cryptoSource?.getRandomValues !== 'function') throw new Error('No fue posible preparar el envío seguro. Abre la aplicación en un navegador compatible.');
    const bytes = cryptoSource.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

export function supportError(error, fallback = 'No fue posible completar la solicitud. Intenta nuevamente.') {
    const status = error?.response?.status;
    if (status === 401 || status === 419) return 'Tu sesión venció. Vuelve a iniciar sesión para continuar.';
    if (status === 413) return 'La imagen excede el tamaño permitido.';
    if (status === 429) return 'Espera un momento antes de intentarlo nuevamente.';
    if (status >= 500) return fallback;
    const response = error?.response?.data;
    const validation = Object.values(response?.errors ?? {}).flat().find((value) => typeof value === 'string');
    const message = validation ?? response?.message;
    return typeof message === 'string' ? friendlyError(message) : fallback;
}

export function supportFileSize(bytes) {
    if (!Number.isFinite(Number(bytes)) || Number(bytes) < 0 || bytes == null) return 'Sin información';
    return Number(bytes) < 1048576 ? `${Math.ceil(Number(bytes) / 1024)} KB` : `${new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 }).format(Number(bytes) / 1048576)} MB`;
}

export function supportPageLinks(pagination, href) {
    const current = pagination?.page ?? 1;
    const last = pagination?.last_page ?? 1;
    if (last <= 1) return [];
    return [
        { label: 'Anterior', url: current > 1 ? href(current - 1) : null, active: false },
        { label: `Página ${current} de ${last}`, url: href(current), active: true },
        { label: 'Siguiente', url: current < last ? href(current + 1) : null, active: false },
    ];
}
