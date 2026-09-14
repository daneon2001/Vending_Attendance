export interface StartupPorts {
  initializeStorage(): Promise<void>
  hasTerminal(): Promise<boolean>
  navigate(path: string): Promise<unknown>
  startTerminal(): Promise<void>
  startPersonalNetwork(): Promise<void>
  startSupport(): Promise<unknown>
}

/** Personal onboarding does not provision, clone or activate a vending terminal. */
export async function startApplication(ports: StartupPorts): Promise<void> {
  try {
    await ports.initializeStorage()
    const terminal = await ports.hasTerminal()
    await ports.navigate('/home')
    if (terminal) {
      await ports.startTerminal()
      void ports.startSupport().catch(() => undefined)
    } else {
      await ports.startPersonalNetwork()
    }
  } catch {
    await ports.navigate('/startup-error')
  }
}
