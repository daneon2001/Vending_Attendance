import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onIonViewWillEnter } from '@ionic/vue'
import { credentialStore, connectivityService } from '@/app/services'
import { initializeSupport, supportReady, supportStartupError, supportStore, supportSync } from './services'
import type { LocalTicket, SupportContext, SupportChanges } from './types'
import type { SupportSyncState } from './SupportSyncService'

export function useSupport() {
  const deviceUuid = ref<string | null>(null)
  const context = ref<SupportContext | null>(null)
  const tickets = ref<LocalTicket[]>([])
  const archivedReports = ref(false)
  const notices = ref<{ event: SupportChanges['events'][number]; read: boolean }[]>([])
  const state = ref<SupportSyncState>({ phase: 'IDLE', errorCode: null, completedOperations: 0 })
  const network = ref(connectivityService.current())
  let unsubscribe: (() => void) | null = null
  let unsubscribeNetwork: (() => void) | null = null
  let refreshGeneration = 0
  async function refresh(): Promise<void> {
    if (!supportReady.value) return
    const generation = ++refreshGeneration
    const identity = await credentialStore.get().catch(() => null)
    if (generation !== refreshGeneration) return
    if (deviceUuid.value !== identity?.deviceUuid) { context.value = null; tickets.value = []; notices.value = []; archivedReports.value = false }
    deviceUuid.value = identity?.deviceUuid ?? null
    if (!deviceUuid.value) { context.value = null; tickets.value = []; notices.value = []; archivedReports.value = false; return }
    const [nextContext, nextTickets, nextNotices] = await Promise.all([
      supportStore.getContext(deviceUuid.value), supportStore.listTickets(deviceUuid.value), supportStore.notifications(deviceUuid.value),
    ])
    if (generation !== refreshGeneration) return
    context.value = nextContext; tickets.value = nextTickets.filter(ticket => ticket.machineUuid === nextContext?.machine.uuid); notices.value = nextNotices
    archivedReports.value = Boolean(nextContext && nextTickets.some(ticket => ticket.machineUuid !== nextContext.machine.uuid))
  }
  async function enter(): Promise<void> { await initializeSupport(); await refresh().catch(() => undefined) }
  onMounted(() => {
    unsubscribe = supportSync.subscribe(next => { state.value = next; if (next.phase !== 'SYNCING') void refresh().catch(() => undefined) })
    unsubscribeNetwork = connectivityService.subscribe(next => { network.value = next })
    void enter()
  })
  onIonViewWillEnter(() => { void enter() })
  onBeforeUnmount(() => { unsubscribe?.(); unsubscribeNetwork?.() })
  async function sync(): Promise<void> { await initializeSupport(); if (supportReady.value) await supportSync.syncNow(); await refresh().catch(() => undefined) }
  async function readNotice(uuid: string): Promise<void> { if (deviceUuid.value) { await supportStore.markRead(deviceUuid.value, uuid); await refresh() } }
  return { deviceUuid, context, tickets, archivedReports, notices, state, network, ready: supportReady, startupError: supportStartupError,
    unread: computed(() => notices.value.filter(notice => !notice.read).length), refresh, sync, readNotice }
}
