import type { AttendanceSyncResult, OutboxStatus } from './types'

export function terminalOutboxStatus(result: AttendanceSyncResult): OutboxStatus {
  return result.status === 'STORED' || result.status === 'DUPLICATE' ? 'SYNCED' : 'REJECTED'
}
