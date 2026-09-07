import type { GeofenceSnapshot, LocationEvidence } from '@/domain/types'

export interface SupportContext {
  device: { uuid: string; name: string | null }
  machine: { uuid: string; code: string; name: string }
  categories: { value: string; label: string }[]
  evidence_policy: { max_size_bytes: number; max_count: number; allowed_mimes: string[] }
  geofence: GeofenceSnapshot | null
  server_time: string
}

export interface TicketInput {
  category: string
  title: string
  description: string
  reported_at: string
  location?: LocationEvidence | null
}

export interface ServerTicket {
  machine?: SupportContext['machine']
  uuid: string
  folio: string
  title: string
  description: string
  status: string
  category: string
  reported_at: string
  updated_at?: string
}

export interface ServerEvidence {
  uuid: string
  status: 'PENDING' | 'CONFIRMED'
  mime?: string
  size_bytes?: number
  sha256?: string
  captured_at?: string | null
  confirmed_at?: string | null
  thumbnail?: boolean
}

export interface SupportChange {
  uuid: string
  sequence: number
  ticket_uuid: string
  kind: string
  created_at: string
  body?: string | null
  metadata?: Record<string, unknown>
  ticket?: ServerTicket
}

export interface SupportChanges {
  events: SupportChange[]
  next_sequence: number
  has_more: boolean
}

export type OperationKind = 'CREATE_TICKET' | 'COMMENT' | 'RESERVE_EVIDENCE' | 'UPLOAD_EVIDENCE' | 'VERIFICATION'
export type DeliveryStatus = 'PENDING' | 'SENDING' | 'ACKNOWLEDGED' | 'REJECTED' | 'BLOCKED'

export interface SupportOperation {
  uuid: string
  deviceUuid: string
  kind: OperationKind
  ticketLocalUuid: string | null
  evidenceLocalUuid: string | null
  dependsOn: string | null
  payload: Record<string, unknown>
  status: DeliveryStatus
  retryCount: number
  nextAttemptAt: string | null
  errorCode: string | null
  result: Record<string, unknown> | null
}

export interface LocalTicket {
  localUuid: string
  deviceUuid: string
  machineUuid: string
  payload: TicketInput
  status: 'DRAFT' | DeliveryStatus
  serverUuid: string | null
  server: ServerTicket | null
  detail: { events: SupportChange[]; evidence: ServerEvidence[] } | null
  errorCode: string | null
  createdAt: string
}

export interface LocalEvidence {
  localUuid: string
  ticketLocalUuid: string
  deviceUuid: string
  capturedAt: string
  sourcePath: string | null
  path: string | null
  mime: string | null
  sizeBytes: number | null
  uploadSha256: string | null
  state: 'CAPTURING' | 'READY' | 'CONFIRMED' | 'CANCELLED'
  serverUuid: string | null
  purgedAt: string | null
  deliveryStatus?: DeliveryStatus
}

export interface EvidenceFile {
  path: string
  mime: string
  sizeBytes: number
  uploadSha256: string
}

export interface VerificationInput {
  captured_machine_uuid?: string
  started_at: string
  completed_at: string
  app_version?: string
  app_build_number?: number
  checks: { code: string; result: 'PASS' | 'WARNING' | 'FAIL' | 'NOT_AVAILABLE'; observed_at?: string; details?: Record<string, string | number | boolean> }[]
}

export class SupportError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly retryable = false,
    public readonly status: number | null = null,
    public readonly retryAfterSeconds: number | null = null,
  ) { super(message); this.name = 'SupportError' }
}

export class SupportMutex {
  private tail: Promise<unknown> = Promise.resolve()

  run<T>(work: () => Promise<T>): Promise<T> {
    const next = this.tail.then(work, work)
    this.tail = next.catch(() => undefined)
    return next
  }
}

export function assertUuid(value: string): string {
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)) {
    throw new SupportError('INVALID_IDENTIFIER', 'La referencia del reporte no es válida.')
  }
  return value
}
