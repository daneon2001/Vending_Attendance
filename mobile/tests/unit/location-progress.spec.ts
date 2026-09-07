import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useLocationProgress } from '@/composables/useLocationProgress'
import { assignmentLabel, deviceStatusLabel, syncPhaseLabel } from '@/presentation/operationLabels'

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

function harness() {
  const scope = effectScope()
  return { scope, progress: scope.run(useLocationProgress)! }
}

describe('GPS waiting presentation without changing capture semantics', () => {
  it('starts in Spanish, explains a delay, and resets after a result', async () => {
    const { scope, progress } = harness()
    expect(progress.message.value).toBe('')
    progress.start()
    expect(progress.message.value).toBe('Obteniendo ubicación…')
    await vi.advanceTimersByTimeAsync(9999)
    expect(progress.message.value).toBe('Obteniendo ubicación…')
    await vi.advanceTimersByTimeAsync(1)
    expect(progress.message.value).toBe('Estamos buscando una señal GPS precisa. Esto puede tardar un poco más sin conexión.')
    progress.stop()
    expect(progress.message.value).toBe('')
    expect(vi.getTimerCount()).toBe(0)
    scope.stop()
  })

  it('cleans the pending delay on success or failure without a later message', async () => {
    const { scope, progress } = harness()
    progress.start()
    progress.stop()
    await vi.advanceTimersByTimeAsync(45_000)
    expect(progress.message.value).toBe('')
    expect(vi.getTimerCount()).toBe(0)
    scope.stop()
  })

  it('cleans the timer when the view is disposed', () => {
    const { scope, progress } = harness()
    progress.start()
    scope.stop()
    expect(progress.message.value).toBe('')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not inherit an old delay when another capture starts', async () => {
    const { scope, progress } = harness()
    progress.start()
    await vi.advanceTimersByTimeAsync(9000)
    progress.start()
    await vi.advanceTimersByTimeAsync(1000)
    expect(progress.message.value).toBe('Obteniendo ubicación…')
    expect(vi.getTimerCount()).toBe(1)
    scope.stop()
  })
})

describe('operational labels only', () => {
  it.each([
    ['PENDING', 'Pendiente de activación'], ['ACTIVE', 'Habilitado'],
    ['SUSPENDED', 'Suspendido'], ['REVOKED', 'Acceso revocado'], ['RETIRED', 'Retirado'],
  ])('presents device %s without changing its value', (value, expected) => {
    const input = Object.freeze({ status: value })
    expect(deviceStatusLabel(input.status)).toBe(expected)
    expect(input.status).toBe(value)
  })
  it.each([
    ['IDLE', 'En espera'], ['SYNCING', 'Sincronizando'],
    ['OFFLINE', 'Sin conexión'], ['ERROR', 'Requiere atención'],
  ])('presents sync phase %s', (value, expected) => {
    expect(syncPhaseLabel(value)).toBe(expected)
  })
  it.each([
    ['PRIMARY', 'Asignación principal'], ['TEMPORARY', 'Asignación temporal'],
    ['SUBSTITUTE', 'Suplencia'], ['SUPERVISOR', 'Supervisión'],
    ['TECHNICIAN', 'Servicio técnico'], ['ROUTE', 'Ruta'],
  ])('presents assignment %s', (value, expected) => {
    expect(assignmentLabel(value)).toBe(expected)
  })
  it.each([null, undefined, 'INTERNAL_UNKNOWN', 'constructor', '__proto__'])('never leaks unknown device values', (value) => {
    expect(deviceStatusLabel(value)).toBe('Sin información')
  })
})
