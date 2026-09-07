import type { GeofenceSnapshot } from '@/domain/types'
import type { EdgeStore } from '@/storage/EdgeStore'
import type { LocationProvider } from '@/services/LocationService'
import { GeofenceValidationService } from '@/services/GeofenceValidationService'

/** Read-only local diagnostic. No event, verification, outbox or network API writes. */
export class GeofenceDiagnosticService {
  constructor(
    private readonly store: Pick<EdgeStore, 'getAttendanceContext'>,
    private readonly location: LocationProvider,
    private readonly validator = new GeofenceValidationService(),
  ) {}
  async read(): Promise<GeofenceSnapshot> {
    return (await this.store.getAttendanceContext()).geofence
  }
  async measure() {
    const before = await this.store.getAttendanceContext()
    const location = await this.location.capture()
    const current = await this.store.getAttendanceContext()
    if (before.configurationVersion !== current.configurationVersion || before.geofence.uuid !== current.geofence.uuid) {
      throw new Error('La zona cambió durante la consulta. Vuelve a consultar tu ubicación.')
    }
    return { evaluation: this.validator.validate(current.geofence, location), capturedAt: location.captured_at }
  }
}
