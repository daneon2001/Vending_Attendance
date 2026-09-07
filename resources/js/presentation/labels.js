// Presentation only: never use translated labels in requests or persisted values.
export const labels = Object.freeze({
 ACTIVE: 'Activo', A: 'Activo', B: 'Inactivo', INACTIVE: 'Inactivo', PENDING: 'Pendiente',
 RETIRED: 'Retirado', SUSPENDED: 'Suspendido', REVOKED: 'Revocado', DEGRADED: 'Con incidencias',
 UNKNOWN: 'Sin información', STALE: 'Desactualizado', SYNCED: 'Sincronizado', OFFLINE: 'Sin conexión', ONLINE: 'En línea',
 READY: 'Lista para operar', REVIEW: 'Requiere revisión', REVIEW_REQUIRED: 'Requiere revisión',
 INCOMPLETE_LOCATION: 'Ubicación incompleta', IDENTIFIER_CONFLICT: 'Identificador duplicado', SOURCE_MISSING: 'Ya no aparece en SYBI',
 DRAFT: 'Borrador', MAINTENANCE: 'En mantenimiento', INVALID: 'Con errores', PRESENT: 'Presente en SYBI',
 NEVER_SYNCED: 'Sin sincronización', RUNNING: 'En proceso', COMPLETED: 'Completado', COMPLETED_WITH_WARNINGS: 'Completado con observaciones',
 FAILED: 'No completado', ERROR: 'Con error', EXPIRED: 'Vencido', AVAILABLE: 'Disponible', USED: 'Utilizado',
 PRIMARY: 'Principal', TEMPORARY: 'Temporal', SUBSTITUTE: 'Suplente', SUPERVISOR: 'Supervisor', TECHNICIAN: 'Técnico', ROUTE: 'Ruta',
 SUPERSEDED: 'Sustituida', PUBLISHED: 'Publicada', BLOCKED: 'Bloqueada', CURRENT: 'Actual',
 UPDATE_AVAILABLE: 'Actualización disponible', UPDATE_REQUIRED: 'Actualización requerida', UNSUPPORTED: 'No compatible',
 ANDROID: 'Android', IOS: 'iOS', DEV: 'Desarrollo', PILOT: 'Piloto', PRODUCTION: 'Producción',
 CHANNEL: 'Canal', DEVICE: 'Dispositivo', GROUP: 'Grupo', HIGH: 'Alta', MEDIUM: 'Media', LOW: 'Baja',
 LOCAL: 'Local', MANUAL: 'Manual', DEMO: 'Demostración', LEGACY: 'Sistema anterior', FORTIA: 'Fortia', SYBI: 'SYBI',
 IMPORT: 'Importación', GPS_INSTALLATION: 'Ubicación de instalación', GEOCODED: 'Dirección geolocalizada',
 WIFI: 'Wi-Fi', CELLULAR: 'Datos móviles', MOBILE: 'Datos móviles', ETHERNET: 'Red por cable', NONE: 'Sin conexión',
 NETWORK: 'Conexión de red', AUTH: 'Acceso', CLOCK: 'Hora del dispositivo', MANIFEST: 'Sincronización',
 SQLITE: 'Almacenamiento local', GPS: 'Ubicación', GEOFENCE: 'Geocerca', ATTENDANCE: 'Asistencia', UPDATE: 'Actualización',
});
export function statusLabel(value) { return labels[String(value ?? '').toUpperCase()] ?? 'Sin información'; }
export function statusTone(value) {
 const code = String(value ?? '').toUpperCase();
 if (['ACTIVE','A','ONLINE','SYNCED','READY','CURRENT','COMPLETED','PRESENT','PUBLISHED'].includes(code)) return 'success';
 if (['REVOKED','ERROR','FAILED','OFFLINE','BLOCKED','HIGH','INVALID'].includes(code)) return 'danger';
 if (['PENDING','STALE','DEGRADED','REVIEW','REVIEW_REQUIRED','MEDIUM','UPDATE_REQUIRED','INCOMPLETE_LOCATION','IDENTIFIER_CONFLICT'].includes(code)) return 'warning';
 return 'neutral';
}
export const errorLabels = Object.freeze({
 'auth.failed': 'Correo o contraseña incorrectos.',
 'passwords.sent': 'Enviamos el enlace de recuperación a tu correo.', 'passwords.reset': 'Tu contraseña fue restablecida.', 'passwords.throttled': 'Espera un momento antes de intentarlo de nuevo.', 'passwords.user': 'No fue posible completar la solicitud con ese correo.', 'passwords.token': 'El enlace de recuperación no es válido o ya venció.',
 ZERO_COORDINATES: 'La máquina no tiene coordenadas válidas.',
 MISSING_COORDINATES: 'Falta la ubicación de la máquina.', INVALID_COORDINATES: 'Revisa las coordenadas de la máquina.',
 DUPLICATE_VENDING_IDENTIFIER: 'El identificador está asociado a más de una máquina.',
 DUPLICATE_SYBI_ID: 'Hay registros repetidos en SYBI.',
 SOURCE_EMPTY_UNEXPECTED: 'No fue posible obtener información válida de la fuente.',
 MISSING_VENDING_IDENTIFIER: 'Falta el identificador de la máquina.', INVALID_VENDING_IDENTIFIER: 'Revisa el identificador de la máquina.',
 INVALID_LOCATION_STRUCTURE: 'La ubicación recibida está incompleta.', INVALID_SOURCE_FIELD: 'Revisa los datos recibidos de la fuente.',
 INVALID_SYBI_ID: 'El identificador de SYBI no es válido.', DEMO_IDENTIFIER_RESERVED: 'Este identificador está reservado para demostración.',
 SOURCE_NOT_CONFIGURED: 'La integración aún no está configurada.', SYNC_UNAVAILABLE: 'No fue posible consultar los cambios.',
 AUTH_ERROR: 'No fue posible acceder a la fuente.', NETWORK_ERROR: 'No fue posible conectarse a la fuente.',
 SOURCE_ERROR: 'La fuente no está disponible.', VALIDATION_ERROR: 'Revisa la información enviada.',
 INVALID_RESPONSE: 'La fuente devolvió información que no se pudo leer.', SCHEMA_ERROR: 'La información recibida no tiene el formato esperado.',
 SYNC_ERROR: 'No fue posible completar la sincronización.',
 HEARTBEAT_NEVER_RECEIVED: 'Aún no se ha recibido una conexión.', HEARTBEAT_EXPIRED: 'El dispositivo dejó de reportar conexión.',
 HEARTBEAT_DELAYED: 'La conexión se está reportando con demora.', CLOCK_DRIFT: 'La hora del dispositivo requiere revisión.',
 LOW_STORAGE: 'Queda poco espacio en el dispositivo.', OUTBOX_PRESSURE: 'Hay muchos registros pendientes de envío.',
 MANIFEST_PENDING: 'Hay información pendiente de sincronizar.', MANIFEST_ERROR: 'La sincronización requiere revisión.',
 MANIFEST_STALE: 'La información del dispositivo está desactualizada.',
 APP_UPDATE_REQUIRED: 'Es necesario actualizar la aplicación.', APP_UNSUPPORTED: 'La versión de aplicación no es compatible.',
});
export function friendlyError(value) {
 if (!value) return '';
 if (errorLabels[value]) return errorLabels[value];
 if (/^LIFECYCLE_/.test(value)) return statusLabel(value.slice(10));
 if (/^RECENT_/.test(value)) return 'Incidencia reciente: ' + statusLabel(value.slice(7)).toLowerCase() + '.';
 // Unknown diagnostic codes must not leak into the normal presentation.
 if (/^[A-Z][A-Z0-9_]+$/.test(value) || /^[a-z]+\.[a-z_.]+$/.test(value)) return 'No se pudo completar la operación. Solicita apoyo para revisar el detalle.';
 return value;
}
export function formatDateTime(value, empty = 'Sin información') {
 if (!value) return empty;
 // SQL timestamps from existing web props are UTC. Date-only values are not instants.
 const raw = String(value);
 const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/.test(raw) ? raw.replace(' ', 'T') + 'Z' : raw;
 const date = new Date(normalized);
 if (!Number.isFinite(date.getTime())) return empty;
 if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw.split('-').reverse().join('/');
 return new Intl.DateTimeFormat('es-MX', { timeZone: 'America/Mexico_City', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date).replace(', ', ' ');
}
export const advancedDeviceKeys = ['platform','app_version','release_channel','sync_state','last_seen','clock_drift','pending_events','geofence'];
export const activeFilterCount = (filters, keys) => keys.filter((key) => filters[key] !== '' && filters[key] != null).length;
export const isFortiaMock = (fortia) => fortia?.driver === 'mock';
export function paginationLabel(value) {
 const text = String(value).replace(/&laquo;|&raquo;/g, '').trim();
 return /previous/i.test(text) ? 'Anterior' : /next/i.test(text) ? 'Siguiente' : text;
}


/** Format whole-second durations from operational thresholds; never round or change them. */
export function formatDurationSeconds(value) {
 if (typeof value !== 'number' && (typeof value !== 'string' || !/^\d+$/.test(value))) return 'Sin información';
 const seconds = Number(value);
 if (!Number.isSafeInteger(seconds) || seconds < 0) return 'Sin información';
 if (seconds === 0) return '0 segundos';
 const number = new Intl.NumberFormat('es-MX');
 const parts = [
  [Math.floor(seconds / 3600), 'hora', 'horas'],
  [Math.floor((seconds % 3600) / 60), 'minuto', 'minutos'],
  [seconds % 60, 'segundo', 'segundos'],
 ].filter(([amount]) => amount > 0)
  .map(([amount, singular, plural]) => number.format(amount) + ' ' + (amount === 1 ? singular : plural));
 return parts.length === 1 ? parts[0] : parts.slice(0, -1).join(', ') + ' y ' + parts.at(-1);
}
