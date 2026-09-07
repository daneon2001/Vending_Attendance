import { onScopeDispose, ref, watch } from 'vue'
import type { EdgeSyncService } from '@/services/EdgeSyncService'
import type { AttendanceReceipt, EdgeStore } from '@/storage/EdgeStore'

export function useAttendanceReceipt(
  eventUuid: () => string | null,
  store: Pick<EdgeStore, 'getAttendanceReceipt'>,
  sync: Pick<EdgeSyncService, 'subscribe'>,
) {
  const receipt = ref<AttendanceReceipt | null>(null)
  let request = 0
  let disposed = false

  async function refresh(): Promise<void> {
    const uuid = eventUuid()
    const currentRequest = ++request
    if (!uuid || disposed) return
    const next = await store.getAttendanceReceipt(uuid).catch(() => null)
    // A late read must not confirm a different event, or overwrite a newer read.
    if (!disposed && currentRequest === request && eventUuid() === uuid) receipt.value = next
  }

  watch(eventUuid, () => {
    receipt.value = null
    void refresh()
  }, { flush: 'sync', immediate: true })
  const unsubscribe = sync.subscribe(() => { void refresh() })
  onScopeDispose(() => {
    disposed = true
    request++
    unsubscribe()
  })

  return { receipt, refresh }
}
