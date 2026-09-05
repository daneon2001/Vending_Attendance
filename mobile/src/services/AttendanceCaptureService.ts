import type { EdgeStore, EffectiveEmployee } from '@/storage/EdgeStore'
import type { LocationProvider } from './LocationService'
import type { GeofenceValidationService } from './GeofenceValidationService'
import type { AttendanceEventType, AttendancePayload, GeofenceEvaluation } from '@/domain/types'
import { EdgeError } from '@/domain/errors'
import { isAttendanceAssignmentEffective } from '@/domain/assignment'

export interface AttendanceCaptureResult {
  payload: AttendancePayload
  evaluation: GeofenceEvaluation
}

export class AttendanceCaptureService {
  constructor(
    private readonly store: EdgeStore,
    private readonly location: LocationProvider,
    private readonly geofence: GeofenceValidationService,
  ) {}

  async capture(employee: EffectiveEmployee, eventType: AttendanceEventType): Promise<AttendanceCaptureResult> {
    if (!employee.assignment.attendance_allowed) {
      throw new EdgeError('NO_EFFECTIVE_ASSIGNMENT', 'La asignación no permite asistencia.')
    }
    const [context, location] = await Promise.all([
      this.store.getAttendanceContext(),
      this.location.capture(),
    ])
    if (!isAttendanceAssignmentEffective(employee, new Date(location.captured_at))) {
      throw new EdgeError('NO_EFFECTIVE_ASSIGNMENT', 'La asignación ya no está vigente para esta checada.')
    }
    const evaluation = this.geofence.validate(context.geofence, location)
    const payload: AttendancePayload = {
      event_uuid: crypto.randomUUID(),
      employee_id: employee.employee_id,
      event_type: eventType,
      captured_at: location.captured_at,
      employee_manifest_version: context.employeeManifestVersion,
      configuration_version: context.configurationVersion,
      assignment_uuid: employee.assignment.uuid,
      device_timezone: context.timezone,
      location: {
        latitude: location.latitude,
        longitude: location.longitude,
        accuracy_m: location.accuracy_m,
      },
      geofence: {
        version: context.geofence.version,
        edge_result: evaluation.result,
      },
    }
    await this.store.enqueueAttendance(payload)
    return { payload, evaluation }
  }
}
