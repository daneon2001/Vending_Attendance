import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createRenderer, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import * as Vue from 'vue'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'

const mocks = vi.hoisted(() => ({ employees: vi.fn(), notify: null as any }))
const state = (version: number) => ({ phase: 'IDLE', summary: { deviceStatus: 'ACTIVE', employeeManifestVersion: version } })
vi.mock('@/app/services', () => ({
  credentialStore: { get: async () => ({ synthetic: true }) },
  edgeStore: { getEffectiveEmployees: mocks.employees },
  connectivityService: { current: () => 'ONLINE', subscribe: () => () => {} },
  edgeSyncService: { subscribe: (cb: any) => { mocks.notify = cb; cb(state(1)); return () => {} }, syncNow: vi.fn() },
}))
vi.mock('@/fieldSupport/services', () => ({ fieldActivitiesAvailable: async () => false }))
vi.mock('@ionic/vue', async () => {
  const { defineComponent, h } = await import('vue')
  const component = (tag: string) => defineComponent({ setup: (_, { slots }) => () => h(tag, slots.default?.()) })
  return Object.fromEntries([
    ...['Button', 'Content', 'Header', 'Item', 'Label', 'List', 'Page', 'Title', 'Toolbar'].map(n => ['Ion' + n, component('ion-' + n.toLowerCase())]),
    ['onIonViewWillEnter', () => {}],
  ])
})
vi.mock('@/components/TerminalStatus.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/TerminalGeofence.vue', () => ({ default: { render: () => null } }))
vi.mock('@/components/BrandIdentity.vue', () => ({ default: { render: () => null } }))

// Real Vue template events and refs with a Node host: no simulated layout claims.
type N = { type: string; text: string; props: Record<string, any>; children: N[]; parent: N | null; focus: any; scrollIntoView: any; addEventListener: any }
const node = (type: string, text = ''): N => ({ type, text, props: {}, children: [], parent: null, focus: vi.fn(), scrollIntoView: vi.fn(), addEventListener: vi.fn() })
const renderer = createRenderer<N, N>({
  createElement: t => node(t), createText: t => node('#text', t), createComment: t => node('#comment', t),
  setText: (n, t) => { n.text = t }, setElementText: (n, t) => { n.text = t; n.children = [] },
  patchProp: (n, k, _p, v) => { n.props[k] = v },
  parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] ?? null,
  insert(n, p, anchor = null) {
    if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1)
    n.parent = p
    const at = anchor ? p.children.indexOf(anchor) : -1
    if (at < 0) p.children.push(n); else p.children.splice(at, 0, n)
  },
  remove(n) { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null },
})
const all = (n: N): N[] => [n, ...n.children.flatMap(all)]
const text = (n: N): string => n.text + n.children.map(text).join('')
const flush = async () => { for (let i = 0; i < 8; i++) await nextTick() }
let app: ReturnType<typeof renderer.createApp>, root: N
const heading = () => all(root).find(n => n.props.id === 'attendance-heading')
const button = () => all(root).find(n => n.type === 'ion-button' && text(n) === 'Registrar asistencia')!
async function mount() {
  // Node-mode Vite compiles SFCs for SSR. Compile the real client template here
  // to exercise events and DOM refs without adding a browser/DOM dependency.
  const source = readFileSync(new URL('../../src/views/HomePage.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const compiled = compileScript(descriptor, { id: 'ux-home', inlineTemplate: true })
  const code = ts.transpileModule(compiled.content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
  const modules: Record<string, any> = { vue: Vue }
  for (const name of ['@ionic/vue', '@/app/services', '@/fieldSupport/services']) modules[name] = await vi.importMock(name)
  modules['@/presentation/operationLabels'] = await import('@/presentation/operationLabels')
  for (const name of ['TerminalStatus', 'TerminalGeofence', 'BrandIdentity']) modules['@/components/' + name + '.vue'] = { default: { render: () => null } }
  const exports: any = {}
  new Function('require', 'exports', code)((name: string) => { if (!(name in modules)) throw new Error('Unexpected import: ' + name); return modules[name] }, exports)
  root = node('root'); app = renderer.createApp(exports.default); app.mount(root); await flush()
}
beforeEach(() => {
  vi.stubGlobal('document', { activeElement: null })
  vi.stubGlobal('window', { innerWidth: 320, matchMedia: () => ({ matches: false }) })
  mocks.employees.mockResolvedValue([{ employee_id: 1, name: 'Fixture', employee_number: 'TEST', assignment: { uuid: 'fixture' } }])
})
afterEach(() => { app?.unmount(); vi.unstubAllGlobals(); vi.clearAllMocks() })

describe('attendance selector discoverability', () => {
  it('click reveals selector after render, scrolls and focuses heading without opening keyboard', async () => {
    await mount()
    expect(heading()).toBeUndefined()
    expect(button().props['aria-expanded']).toBe(false)
    const opening = button().props.onClick()
    expect(heading()).toBeUndefined()
    await opening
    expect(button().props['aria-expanded']).toBe(true)
    expect(text(heading()!)).toBe('Selecciona tu nombre')
    expect(heading()!.props.tabindex).toBe('-1')
    expect(heading()!.focus).toHaveBeenCalledWith({ preventScroll: true })
    expect(heading()!.scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start', inline: 'nearest' })
    expect(all(root).find(n => n.type === 'input')!.focus).not.toHaveBeenCalled()
  })
  it('closes without scrolling and avoids stale focus on rapid open/close', async () => {
    await mount(); await button().props.onClick()
    const old = heading()!
    await button().props.onClick()
    expect(heading()).toBeUndefined()
    expect(old.scrollIntoView).toHaveBeenCalledTimes(1)
    const toggle = button().props.onClick
    await Promise.all([toggle(), toggle()])
    expect(heading()).toBeUndefined()
    await toggle()
    expect(heading()!.scrollIntoView).toHaveBeenCalledTimes(1)
  })
  it('respects reduced motion', async () => {
    vi.stubGlobal('window', { matchMedia: () => ({ matches: true }) })
    await mount(); await button().props.onClick()
    expect(heading()!.scrollIntoView).toHaveBeenCalledWith({ behavior: 'auto', block: 'start', inline: 'nearest' })
  })
  it.each(['empty', 'error'])('preserves initial %s state and action eligibility', async kind => {
    if (kind === 'error') mocks.employees.mockRejectedValue(new Error('synthetic'))
    else mocks.employees.mockResolvedValue([])
    await mount()
    expect(button()).toBeUndefined()
    expect(text(root)).toContain('No hay empleados disponibles para asistencia.')
    expect(heading()).toBeUndefined()
  })
  it.each(['empty', 'error'])('keeps open selector readable after %s refresh', async kind => {
    await mount(); await button().props.onClick()
    const current = heading()!
    if (kind === 'error') mocks.employees.mockRejectedValue(new Error('synthetic'))
    else mocks.employees.mockResolvedValue([])
    mocks.notify(state(2)); await flush()
    expect(heading()).toBe(current)
    expect(text(root)).toContain('No hay empleados disponibles. Sincroniza o solicita apoyo al responsable.')
    expect(current.scrollIntoView).toHaveBeenCalledTimes(1)
  })
  it('retains branding, banner and one selector inside Ionic content on narrow screens', async () => {
    await mount(); await button().props.onClick()
    const content = all(root).find(n => n.type === 'ion-content')!
    const nodes = all(content)
    expect(nodes.filter(n => n.props.id === 'attendance-employees')).toHaveLength(1)
    expect(nodes.findIndex(n => n.type === 'aside')).toBeLessThan(nodes.indexOf(heading()!))
    expect(text(root)).toContain('Asistencia MDM')
    const source = readFileSync(new URL('../../src/views/HomePage.vue', import.meta.url), 'utf8')
    expect(source).toMatch(/\.dispenser-banner img\s*\{\s*contain: size;/)
    expect(source).toContain('scroll-margin-top: 16px')
    expect(all(root).find(n => n.type === 'img')!.props.width).toBe('720')
    expect(heading()!.scrollIntoView).toHaveBeenCalledTimes(1)
  })
})
