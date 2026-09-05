import { Capacitor, type PluginListenerHandle } from '@capacitor/core'
import { Network } from '@capacitor/network'
import type { ConnectivityState } from '@/domain/types'

export type ConnectivityListener = (state: ConnectivityState) => void

export class ConnectivityService {
  private state: ConnectivityState = 'UNKNOWN'
  private listeners = new Set<ConnectivityListener>()
  private nativeHandle: PluginListenerHandle | null = null
  private browserOnline = () => this.setState('ONLINE')
  private browserOffline = () => this.setState('OFFLINE')

  current(): ConnectivityState {
    return this.state
  }

  subscribe(listener: ConnectivityListener): () => void {
    this.listeners.add(listener)
    listener(this.state)
    return () => this.listeners.delete(listener)
  }

  async start(): Promise<void> {
    if (Capacitor.isNativePlatform()) {
      const status = await Network.getStatus()
      this.setState(status.connected ? 'ONLINE' : 'OFFLINE')
      this.nativeHandle = await Network.addListener('networkStatusChange', (next) => {
        this.setState(next.connected ? 'ONLINE' : 'OFFLINE')
      })
      return
    }
    this.setState(navigator.onLine ? 'ONLINE' : 'OFFLINE')
    window.addEventListener('online', this.browserOnline)
    window.addEventListener('offline', this.browserOffline)
  }

  async stop(): Promise<void> {
    await this.nativeHandle?.remove()
    this.nativeHandle = null
    window.removeEventListener('online', this.browserOnline)
    window.removeEventListener('offline', this.browserOffline)
  }

  private setState(state: ConnectivityState): void {
    if (this.state === state) return
    this.state = state
    for (const listener of this.listeners) listener(state)
  }
}
