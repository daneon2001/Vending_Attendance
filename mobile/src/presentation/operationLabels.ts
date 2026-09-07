function label(values: Record<string, string>, value: string | null | undefined): string {
  return value && Object.hasOwn(values, value) ? values[value] : 'Sin información'
}

export const deviceStatusLabel = (value: string | null | undefined): string => label({
  PENDING: 'Pendiente de activación', ACTIVE: 'Habilitado', SUSPENDED: 'Suspendido',
  REVOKED: 'Acceso revocado', RETIRED: 'Retirado',
}, value)

export const syncPhaseLabel = (value: string): string => label({
  IDLE: 'En espera', SYNCING: 'Sincronizando', OFFLINE: 'Sin conexión', ERROR: 'Requiere atención',
}, value)

export const assignmentLabel = (value: string): string => label({
  PRIMARY: 'Asignación principal', TEMPORARY: 'Asignación temporal',
  SUBSTITUTE: 'Suplencia', SUPERVISOR: 'Supervisión', TECHNICIAN: 'Servicio técnico', ROUTE: 'Ruta',
}, value)
