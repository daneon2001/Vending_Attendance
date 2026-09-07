import type { EvidenceFile, LocalEvidence, LocalTicket, ServerEvidence, ServerTicket, SupportChange, SupportChanges, SupportContext, SupportOperation, TicketInput, VerificationInput } from './types'

export interface SupportStore {
  initialize(): Promise<void>
  saveContext(context: SupportContext): Promise<void>
  getContext(deviceUuid: string): Promise<SupportContext | null>
  createDraft(deviceUuid: string, input: TicketInput): Promise<LocalTicket>
  updateDraft(localUuid: string, deviceUuid: string, input: TicketInput): Promise<void>
  submit(localUuid: string, deviceUuid: string): Promise<void>
  getTicket(localUuid: string, deviceUuid: string): Promise<LocalTicket | null>
  listTickets(deviceUuid: string): Promise<LocalTicket[]>
  cacheTicketDetail(localUuid: string, deviceUuid: string, detail: { ticket: ServerTicket; events: SupportChange[]; evidence: ServerEvidence[] }): Promise<void>
  comments(localUuid: string, deviceUuid: string): Promise<SupportOperation[]>
  queueComment(localUuid: string, deviceUuid: string, body: string): Promise<string>
  queueVerification(deviceUuid: string, input: VerificationInput): Promise<string>
  lastVerification(deviceUuid: string): Promise<SupportOperation | null>
  reserveCapture(localUuid: string, deviceUuid: string): Promise<LocalEvidence>
  pendingCapture(): Promise<LocalEvidence | null>
  saveCaptureSource(evidenceUuid: string, deviceUuid: string, sourcePath: string): Promise<void>
  completeCapture(evidenceUuid: string, deviceUuid: string, file: EvidenceFile): Promise<void>
  cancelCapture(evidenceUuid: string, deviceUuid: string): Promise<void>
  evidenceForTicket(localUuid: string, deviceUuid: string): Promise<LocalEvidence[]>
  getEvidence(evidenceUuid: string, deviceUuid: string): Promise<LocalEvidence | null>
  claim(deviceUuid: string): Promise<SupportOperation | null>
  acknowledge(operation: SupportOperation, result: Record<string, unknown>): Promise<void>
  fail(operation: SupportOperation, code: string, retryable: boolean, retryAfterSeconds?: number | null, blocked?: boolean): Promise<void>
  operation(uuid: string, deviceUuid: string): Promise<SupportOperation | null>
  confirmedFiles(deviceUuid: string): Promise<LocalEvidence[]>
  cameraSources(deviceUuid: string): Promise<LocalEvidence[]>
  markSourceCleaned(evidenceUuid: string, deviceUuid: string): Promise<void>
  markPurged(evidenceUuid: string, deviceUuid: string): Promise<void>
  getCursor(deviceUuid: string): Promise<number>
  applyChanges(deviceUuid: string, page: SupportChanges): Promise<void>
  notifications(deviceUuid: string): Promise<{ event: SupportChanges['events'][number]; read: boolean }[]>
  markRead(deviceUuid: string, eventUuid: string): Promise<void>
}
