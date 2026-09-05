export type EdgeErrorCode =
  | 'NOT_PROVISIONED'
  | 'INVALID_PROVISIONING_TOKEN'
  | 'AUTHENTICATION_FAILED'
  | 'OFFLINE'
  | 'GPS_PERMISSION_DENIED'
  | 'GPS_DISABLED'
  | 'GPS_TIMEOUT'
  | 'GPS_UNAVAILABLE'
  | 'LOW_ACCURACY'
  | 'NO_EFFECTIVE_ASSIGNMENT'
  | 'INVALID_GEOFENCE'
  | 'DATABASE_ERROR'
  | 'MANIFEST_REJECTED'
  | 'SERVER_ERROR'

export class EdgeError extends Error {
  constructor(
    public readonly code: EdgeErrorCode,
    message: string,
    public readonly retryable = false,
    public readonly details?: Record<string, unknown>,
  ) {
    super(message)
    this.name = 'EdgeError'
  }
}

export function safeErrorMessage(error: unknown): string {
  return error instanceof EdgeError ? error.message : 'Ocurrió un error inesperado.'
}
