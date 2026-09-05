import { App } from '@capacitor/app'
import type { PluginListenerHandle } from '@capacitor/core'
import { Device } from '@capacitor/device'
import type { EdgeApiService } from '@/api/EdgeApiService'
import type { EdgeStore, LocalSyncSummary } from '@/storage/EdgeStore'
import type { ConnectivityService } from './ConnectivityService'
import { manifestHashMatches } from '@/security/manifestHash'
import { EdgeError } from '@/domain/errors'

export type SyncPhase = 'IDLE' | 'SYNCING' | 'OFFLINE' | 'ERROR'

export interface SyncViewState {
  phase: SyncPhase
  message: string | null
  summary: LocalSyncSummary | null
  clockDriftWarning: boolean
}

export class EdgeSyncService {
  private running: Promise<void> | null = null
  private listeners = new Set<(state: SyncViewState) => void>()
  private unsubscribeConnectivity: (() => void) | null = null
  private appHandle: PluginListenerHandle | null = null
  private heartbeatTimer: ReturnType<typeof setTimeout> | null = null
  private started = false
  private needsFullSync = false
  private recoveryAttempts = 0
  private lastError: { category: string; code: string; at: string } | null = null
  private state: SyncViewState = {
    phase: 'IDLE',
    message: null,
    summary: null,
    clockDriftWarning: false,
  }

  constructor(
    private readonly api: EdgeApiService,
    private readonly store: EdgeStore,
    private readonly connectivity: ConnectivityService,
    private readonly batchSize = 50,
    private readonly heartbeatJitterRatio = 0.2,
  ) {}

  subscribe(listener: (state: SyncViewState) => void): () => void {
    this.listeners.add(listener)
    listener(this.state)
    return () => this.listeners.delete(listener)
  }

  async start(): Promise<void> {
    this.started = true
    await this.connectivity.start()
    this.unsubscribeConnectivity = this.connectivity.subscribe((status) => {
      if (status === 'ONLINE') void this.syncNow('network-restored')
      if (status === 'OFFLINE') this.publish({ phase: 'OFFLINE', message: 'Sin conexión; los eventos quedan en cola.' })
    })
    this.appHandle = await App.addListener('appStateChange', ({ isActive }) => {
      if (isActive) void this.syncNow('foreground')
    })
    await this.refreshSummary()
    if (this.connectivity.current() === 'ONLINE') await this.syncNow('startup')
  }

  async stop(): Promise<void> {
    this.started = false
    if (this.heartbeatTimer) clearTimeout(this.heartbeatTimer)
    this.heartbeatTimer = null
    this.unsubscribeConnectivity?.()
    this.unsubscribeConnectivity = null
    await this.appHandle?.remove()
    this.appHandle = null
    await this.connectivity.stop()
  }

  async syncNow(_trigger: 'startup' | 'network-restored' | 'manual' | 'attendance' | 'foreground' | 'recovery'): Promise<void> {
    if (this.connectivity.current() !== 'ONLINE') {
      this.publish({ phase: 'OFFLINE', message: 'Sin conexión; sincronización pendiente.' })
      await this.refreshSummary()
      return
    }
    if (this.running) return this.running
    this.running = this.performSync().finally(() => { this.running = null })
    return this.running
  }

  private async performSync(): Promise<void> {
    this.publish({ phase: 'SYNCING', message: null })
    try {
      const configApplied = await this.store.getAppliedManifestVersion('CONFIGURATION')
      const bootstrap = await this.api.bootstrap(configApplied)
      await this.store.applyBootstrap(bootstrap)
      await this.flushOutbox()
      const status = await this.api.manifestStatus()
      if (status.configuration.changed) await this.syncConfiguration()
      if (status.employees.changed) await this.syncEmployees()

      const heartbeat = await this.sendHeartbeat()
      this.needsFullSync = false
      this.recoveryAttempts = 0
      this.publish({
        phase: 'IDLE',
        message: 'Sincronización completa.',
        clockDriftWarning: heartbeat.clock_drift_warning,
      })
      await this.refreshSummary()
      this.scheduleHeartbeat(heartbeat.next_heartbeat_seconds)
    } catch (error) {
      this.needsFullSync = true
      this.recoveryAttempts++
      this.lastError = this.classifyError(error)
      this.publish({
        phase: 'ERROR',
        message: error instanceof Error ? error.message : 'Falló la sincronización.',
      })
      await this.refreshSummary()
      this.scheduleHeartbeat(Math.min(300, 15 * (2 ** Math.min(this.recoveryAttempts, 5))))
    }
  }

  private async sendHeartbeat(): Promise<Awaited<ReturnType<EdgeApiService['heartbeat']>>> {
    const summary = await this.store.getSummary()
    const [info, appInfo] = await Promise.all([Device.getInfo(), App.getInfo()])
    const heartbeat = await this.api.heartbeat({
      configVersionApplied: summary.configurationVersion,
      pendingEvents: summary.pendingEvents,
      appVersion: appInfo.version,
      appBuildNumber: Number.parseInt(appInfo.build, 10) || undefined,
      platformVersion: info.osVersion,
      networkState: this.connectivity.current(),
      lastError: this.lastError,
    })
    this.lastError = null
    await this.store.updateClockDrift(heartbeat.clock_drift_seconds)

    return heartbeat
  }

  private scheduleHeartbeat(seconds: number): void {
    if (!this.started) return
    if (this.heartbeatTimer) clearTimeout(this.heartbeatTimer)
    const baseSeconds = Math.max(15, Number.isFinite(seconds) ? seconds : 60)
    const jitter = 1 + ((Math.random() * 2) - 1) * this.heartbeatJitterRatio
    const delay = Math.max(15, baseSeconds * jitter) * 1000
    this.heartbeatTimer = setTimeout(() => void this.runPeriodicHeartbeat(), delay)
  }

  private async runPeriodicHeartbeat(): Promise<void> {
    if (!this.started) return
    if (this.connectivity.current() !== 'ONLINE' || this.running) {
      this.scheduleHeartbeat(30)
      return
    }
    if (this.needsFullSync) {
      await this.syncNow('recovery')
      return
    }
    try {
      const heartbeat = await this.sendHeartbeat()
      this.scheduleHeartbeat(heartbeat.next_heartbeat_seconds)
    } catch (error) {
      this.lastError = this.classifyError(error)
      this.scheduleHeartbeat(60)
    }
    await this.refreshSummary()
  }

  private classifyError(error: unknown): { category: string; code: string; at: string } {
    const code = error instanceof EdgeError ? error.code : 'UNKNOWN'
    const category = code === 'AUTHENTICATION_FAILED'
      ? 'AUTH'
      : code.startsWith('GPS') || code === 'LOW_ACCURACY'
        ? 'GPS'
        : code.includes('MANIFEST')
          ? 'MANIFEST'
          : code === 'DATABASE_ERROR'
            ? 'SQLITE'
            : code.includes('GEOFENCE')
              ? 'GEOFENCE'
              : (code === 'OFFLINE' || code === 'NETWORK_TIMEOUT')
                ? 'NETWORK'
                : 'ATTENDANCE'

    return { category, code, at: new Date().toISOString() }
  }

  private async syncConfiguration(): Promise<void> {
    const manifest = await this.api.configurationManifest()
    if (!(await manifestHashMatches(manifest))) {
      throw new EdgeError('MANIFEST_REJECTED', 'El hash del manifest de configuración no coincide.')
    }
    await this.store.applyConfigurationManifest(manifest)
    try {
      await this.api.acknowledgeManifest({
        manifestType: 'CONFIGURATION',
        version: manifest.manifest_version,
        hash: manifest.manifest_hash,
        status: 'APPLIED',
      })
      await this.store.markManifestAcknowledged('CONFIGURATION', manifest.manifest_version, manifest.manifest_hash)
    } catch (error) {
      await this.store.markManifestAckFailed('CONFIGURATION', error instanceof Error ? error.message : 'ACK failed')
      throw error
    }
  }

  private async syncEmployees(): Promise<void> {
    // A full fetch also repairs the case where the local commit succeeded but its ACK was lost.
    const manifest = await this.api.employeeManifest(null)
    if (manifest.changed === false || !manifest.employees) return
    if (!(await manifestHashMatches(manifest))) {
      throw new EdgeError('MANIFEST_REJECTED', 'El hash del manifest de empleados no coincide.')
    }
    await this.store.applyEmployeeManifest(manifest)
    try {
      await this.api.acknowledgeManifest({
        manifestType: 'EMPLOYEES',
        version: manifest.manifest_version,
        hash: manifest.manifest_hash,
        status: 'APPLIED',
      })
      await this.store.markManifestAcknowledged('EMPLOYEES', manifest.manifest_version, manifest.manifest_hash)
    } catch (error) {
      await this.store.markManifestAckFailed('EMPLOYEES', error instanceof Error ? error.message : 'ACK failed')
      throw error
    }
  }

  async flushOutbox(): Promise<void> {
    while (this.connectivity.current() === 'ONLINE') {
      const pending = await this.store.getPendingOutbox(this.batchSize)
      if (pending.length === 0) return
      try {
        const results = await this.api.sendAttendanceBatch(pending.map((item) => item.payload))
        await this.store.applyOutboxResults(results)
        const returned = new Set(results.map((result) => result.event_uuid))
        const missing = pending.filter((item) => !returned.has(item.eventUuid)).map((item) => item.eventUuid)
        if (missing.length > 0) await this.store.releaseOutbox(missing, 'INCOMPLETE_BATCH_RESPONSE')
      } catch (error) {
        await this.store.releaseOutbox(pending.map((item) => item.eventUuid), 'TRANSPORT_ERROR')
        throw error
      }
    }
  }

  private publish(patch: Partial<SyncViewState>): void {
    this.state = { ...this.state, ...patch }
    for (const listener of this.listeners) listener(this.state)
  }

  private async refreshSummary(): Promise<void> {
    const summary = await this.store.getSummary().catch(() => null)
    this.publish({ summary })
  }
}
