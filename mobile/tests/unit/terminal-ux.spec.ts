import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { readFileSync } from 'node:fs'
import TerminalStatus from '@/components/TerminalStatus.vue'
import type { SyncViewState } from '@/services/EdgeSyncService'
import type { ConnectivityState } from '@/domain/types'

vi.mock('@ionic/vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { IonButton: defineComponent({ setup: (_, { slots }) => () => h('button', slots.default?.()) }) }
})

function state(pending = 0, phase: SyncViewState['phase'] = 'IDLE'): SyncViewState {
  return { phase, message: null, clockDriftWarning: false, summary: {
    machineCode: 'VM-DEMO-001', deviceStatus: 'ACTIVE', configurationVersion: 2,
    employeeManifestVersion: 5, pendingEvents: pending, lastSyncAt: null,
  } }
}
const render = (value: SyncViewState, connectivity: ConnectivityState = 'ONLINE') =>
  renderToString(createSSRApp({ render: () => h(TerminalStatus, { state: value, connectivity }) }))

describe('worker-facing terminal status', () => {
  it.each([['ONLINE', 'En línea'], ['OFFLINE', 'Sin conexión'], ['UNKNOWN', 'Comprobando conexión']] as const)(
    'presents %s without inventing a receipt', async (network, label) => {
      const html = await render(state(), network)
      expect(html).toContain(label)
      expect(html).toContain('0 asistencias pendientes de sincronizar')
      expect(html).not.toMatch(/Todo sincronizado|Asistencia registrada|Terminal lista|SYNCED|ONLINE|OFFLINE/)
    },
  )
  it('keeps the pending count visible offline, with safe natural copy', async () => {
    const html = await render(state(1, 'OFFLINE'), 'OFFLINE')
    expect(html).toContain('1 asistencia pendiente de sincronizar')
    expect(html).toContain('Los registros se guardan en este dispositivo hasta recuperar conexión.')
    expect(html).not.toMatch(/outbox|manifest|network unavailable|retry/)
  })
  it('does not turn unavailable local state into zero pending', async () => {
    const html = await render({ ...state(), summary: null })
    expect(html).toContain('Consultando registros pendientes…')
    expect(html).not.toContain('0 asistencias')
  })
  it('keeps real errors visible without displaying raw service diagnostics', async () => {
    const value = { ...state(2, 'ERROR'), message: 'HMAC INTERNAL_ERROR stack: secret-example' }
    const html = await render(value)
    expect(html).toContain('No se pudo completar la sincronización.')
    expect(html).toContain('role="alert"')
    expect(html).toContain('2 asistencias pendientes')
    expect(html).not.toMatch(/HMAC|INTERNAL_ERROR|secret-example|stack/)
  })
  it('does not hide administrative restrictions or clock warnings', async () => {
    const value = state()
    value.summary!.deviceStatus = 'SUSPENDED'
    value.clockDriftWarning = true
    const html = await render(value)
    expect(html).toContain('Dispositivo suspendido. Solicita apoyo')
    expect(html).toContain('Revisa la fecha y hora')
    expect(html).not.toContain('Terminal lista')
  })
  it('disables manual sync during the existing syncing phase', async () => {
    const html = await render(state(1, 'SYNCING'))
    expect(html).toMatch(/<button[^>]*disabled[^>]*>Sincronizando…<\/button>/)
  })
  it('never changes the supplied state or internal enums', async () => {
    const value = state(1, 'OFFLINE')
    Object.freeze(value.summary); Object.freeze(value)
    const before = JSON.stringify(value)
    await render(value, 'OFFLINE')
    expect(JSON.stringify(value)).toBe(before)
  })
})

describe('small-screen structural safeguards (not a visual certification)', () => {
  const source = (file: string) => readFileSync(new URL(`../../src/${file}`, import.meta.url), 'utf8')
  it('keeps the product title separate from sync and selects employees before diagnostics', () => {
    const home = source('views/HomePage.vue')
    expect(home.slice(0, home.indexOf('</ion-header>'))).not.toContain('Sincronizar')
    expect(home).toContain('Selecciona tu nombre')
    expect(home.indexOf('class="employee-list"')).toBeLessThan(home.indexOf('<details'))
    expect(home).not.toMatch(/<details[^>]*\bopen|status-grid|state.message/)
    expect(home).toContain('ion-text-wrap')
    expect(home).toContain('--min-height: 80px')
    expect(home).toContain('aria-label="Buscar empleado por nombre o número"')
    expect(home).toContain('<input v-model="search" type="search"')
    expect(home).toContain('aria-label="Limpiar búsqueda de empleados"')
    expect(home).not.toContain('IonSearchbar')
    expect(home).toMatch(/\.employee-search button\s*\{[^}]*flex-shrink: 0;[^}]*white-space: nowrap;/)
    expect(home).toContain('font: inherit')
  })
  it('keeps the two capture handlers and busy guards, with full-sized Spanish actions', () => {
    const page = source('views/AttendancePage.vue')
    expect(page).toContain('@click="capture(\'CHECK_IN\')">Registrar entrada')
    expect(page).toContain('@click="capture(\'CHECK_OUT\')">Registrar salida')
    expect(page.match(/:disabled="busy"/g)).toHaveLength(2)
    expect(page).toContain('min-height: 56px')
    expect(page).toContain('aria-busy="busy"')
    expect(page).toContain('ion-spinner')
    expect(page).toContain('v-if="busy && !locationMessage"')
    expect(page).toContain('Podrás registrar otra asistencia al terminar.')
    expect(page).toMatch(/finally\s*\{\s*stopLocationProgress\(\)\s*busy.value = false/)
    expect(page).not.toContain('assignmentLabel')
  })
  it('supports safe-area padding, wrapping and visible keyboard focus', () => {
    const app = source('App.vue')
    expect(app).toContain('env(safe-area-inset-bottom)')
    expect(app).toContain('env(safe-area-inset-left)')
    expect(app).toContain('white-space: normal')
    expect(app).toContain('focus-visible')
    expect(app).toContain('min-height: 48px')
    expect(source('main.ts')).not.toContain('dark.system.css')
  })
  it('keeps the selected text/background color pairs above 4.5:1', () => {
    const css = source('theme/variables.css')
    const value = (name: string) => css.match(new RegExp(`--ion-${name}: (#[a-f0-9]{6})`))![1]
    const luminance = (hex: string) => {
      const rgb = hex.slice(1).match(/../g)!.map(part => parseInt(part, 16) / 255)
        .map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4)
      return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722
    }
    const pairs = [
      ['text-color', 'background-color'], ['color-medium', 'background-color'],
      ['toolbar-color', 'toolbar-background'], ['color-primary', 'color-primary-contrast'],
      ['color-success', 'color-success-contrast'], ['color-warning', 'color-warning-contrast'],
      ['color-danger', 'color-danger-contrast'],
    ]
    for (const [fg, bg] of pairs) {
      const a = luminance(value(fg)), b = luminance(value(bg))
      expect((Math.max(a, b) + .05) / (Math.min(a, b) + .05), `${fg}/${bg}`).toBeGreaterThanOrEqual(4.5)
    }
  })
})
