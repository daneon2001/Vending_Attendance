import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h, ref } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { readFileSync } from 'node:fs'
import SupportTicketSummary from '@/support/SupportTicketSummary.vue'
import SupportHomePage from '@/support/SupportHomePage.vue'
import type { LocalTicket } from '@/support/types'
import { deliveryLabel, supportMessage, verificationResult } from '@/support/presentation'
import { context, DEVICE, input, MACHINE, serverTicket } from './support-fixtures'

vi.mock('@ionic/vue', async () => {
  const { defineComponent, h } = await import('vue')
  const component = (tag: string) => defineComponent({ setup: (_, { attrs, slots }) => () => h(tag, attrs, slots.default?.()) })
  return { IonButton: component('button'), IonPage: component('div'), IonHeader: component('header'), IonToolbar: component('div'),
    IonButtons: component('div'), IonBackButton: component('button'), IonTitle: component('span'), IonContent: component('div') }
})
vi.mock('@/support/useSupport', () => ({ useSupport: () => ({
  context: ref(context), ready: ref(true), startupError: ref(false), network: ref('OFFLINE'),
  state: ref({ phase: 'OFFLINE' }), notices: ref([]), unread: ref(0), readNotice: vi.fn(), sync: vi.fn(),
}) }))

function local(status: LocalTicket['status']): LocalTicket {
  return { localUuid: 'internal-uuid-must-not-render', deviceUuid: DEVICE, machineUuid: MACHINE, payload: input,
    status, serverUuid: status === 'ACKNOWLEDGED' ? serverTicket.uuid : null, server: status === 'ACKNOWLEDGED' ? serverTicket : null,
    detail: null, errorCode: 'INTERNAL_PRIVATE_CODE', createdAt: input.reported_at }
}
const render = (ticket: LocalTicket) => renderToString(createSSRApp({ render: () => h(SupportTicketSummary, { ticket }) }))

describe('Spanish support UI and confirmed receipts', () => {
  it.each(['PENDING', 'SENDING'] as const)('does not imply a server ticket from a local %s report', async status => {
    const html = await render(local(status))
    expect(html).toContain('Reporte guardado')
    expect(html).not.toContain('Reporte enviado')
    expect(html).not.toMatch(/internal-uuid|INTERNAL_PRIVATE_CODE|PENDING|SENDING/)
    expect(html).toContain('Ubicación no disponible')
  })
  it('shows the human folio only after the real server acknowledgement', async () => {
    const html = await render(local('ACKNOWLEDGED'))
    expect(html).toContain('Reporte enviado. Ticket INC-2026-000001.')
    expect(html).toContain('Abierto')
    expect(html).toContain('aria-live="polite"')
    expect(html).not.toContain(serverTicket.uuid)
  })
  it('retains and explains rejected reports, and escapes untrusted titles/descriptions', async () => {
    const ticket = local('REJECTED')
    ticket.payload = { ...input, title: '<script>alert(1)</script>', description: '<img src=x onerror=alert(1)>' }
    const html = await render(ticket)
    expect(html).toContain('requiere revisión')
    expect(html).toContain('&lt;script&gt;')
    expect(html).not.toContain('<script>')
    expect(html).not.toContain('<img src=x')
  })
  it('renders secondary support actions in the established shell with clear offline expectations', async () => {
    const html = await renderToString(createSSRApp(SupportHomePage))
    expect(html).toContain('Reportar incidencia')
    expect(html).toContain('Mis reportes')
    expect(html).toContain('Verificar este equipo')
    expect(html).toContain('se guardan sin conexión')
    expect(html).not.toContain(DEVICE)
  })
  it('keeps raw diagnostics out of UI and labels unavailable checks honestly', () => {
    expect(supportMessage(new Error('token=private-secret /private/path'))).not.toMatch(/token|private-secret|private\/path/)
    expect(verificationResult('NOT_AVAILABLE')).toBe('No disponible')
    expect(deliveryLabel('BLOCKED')).toBe('Envío detenido')
  })
  it('retains existing attendance startup and adds lazy support startup without awaiting it', () => {
    const source = (file: string) => readFileSync(new URL(`../../src/${file}`, import.meta.url), 'utf8')
    const home = source('views/HomePage.vue'); const main = source('main.ts')
    expect(home.indexOf('router-link="/support"')).toBeGreaterThan(home.indexOf('</ion-list>'))
    expect(home).toContain('fill="outline" router-link="/support"')
    expect(main).toContain("void import('./support/services')")
    expect(main).toContain('await edgeSyncService.start()')
    expect(source('support/SupportReportPage.vue')).toContain('Enviar sin ubicación')
    expect(source('support/SupportReportPage.vue')).toContain('for="support-description"')
    expect(source('support/SupportTicketPage.vue')).toContain('URL.revokeObjectURL')
    expect(source('support/support.css')).toContain('min-height: 48px')
  })
})
