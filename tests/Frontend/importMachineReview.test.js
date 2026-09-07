import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { emptyImportState, importSteps } from '../../resources/js/Pages/Employees/importState.js';
import { renderVue } from './vueRender.mjs';

const operator = { employees: ['view', 'sync', 'import'], vending_machines: ['view', 'assign'] };
const words = ['Zürich', 'Ampliación', 'México', 'Ceylán', 'Niño', 'José'];
const machineProps = (auditLogs = []) => ({
 machine: {
  uuid: 'synthetic-machine', machine_code: 'VM-SYNTHETIC', name: words.join(' · '), source: 'SYBI',
  sybi_id: 'source-id-only-in-detail', sybi_city_id: 'city-id-only-in-detail', sybi_state_id: 'state-id-only-in-detail',
  sybi_full_address: words.join(' · '), sybi_sync_status: 'SYNCED', sybi_last_seen_at: '2026-09-05T18:48:23Z',
  geofences: [], devices: [], assignments: [], provisioning_tokens: [],
 },
 auditLogs, employees: [], assignmentTypes: ['PRIMARY'], geofenceStatuses: ['DRAFT', 'ACTIVE'],
});
test('reviewed import copy remains natural, protects Fortia ownership and discloses CSV/XLSX and five steps', async () => {
 const html = await renderVue('resources/js/Pages/Employees/Partials/StagedEmployeeImport.vue', { open: true, limits: { file_kb: 5120, rows: 5000 } }, operator);
 assert.ok(html.includes('Importa empleados desde un archivo CSV o Excel. Los datos administrados por Fortia no serán reemplazados.'));
 assert.ok(!html.includes('Carga controlada de datos locales.'));
 assert.ok(html.includes('Archivo CSV UTF-8 o XLSX de una sola hoja'));
 assert.equal(importSteps.length, 5);
 for (const step of importSteps) assert.ok(html.includes(step));
});
test('reviewed large preview renders only the received page while showing full-dataset counters', async () => {
 const data = Array.from({ length: 50 }, (_, i) => ({
  row_number: i + 2, employee_number: String(i).padStart(5, '0'), full_name: 'Sintético ' + i,
  normalized_status: 'A', classification: 'VALID_NEW', changes: {}, errors: [],
 }));
 const preview = {
  uuid: 'synthetic-preview', preview_hash: 'a'.repeat(64), status: 'PREVIEW', mapping: {},
  summary: { total_rows: 2502, valid_new: 2502, valid_update: 0, unchanged: 0, invalid: 0, duplicates: 0, conflicts: 0 },
  rows: { data, total: 2502, current_page: 1, last_page: 51 },
 };
 const original = JSON.stringify(preview);
 const html = await renderVue('resources/js/Pages/Employees/Partials/StagedEmployeeImport.vue', { open: true, limits: { file_kb: 5120, rows: 5000 } }, operator, {
  modules: { '../importState.js': { emptyImportState: () => ({ ...emptyImportState(), step: 3, preview: structuredClone(preview) }) } },
 });
 const tbody = html.match(/<tbody\b[^>]*>([\s\S]*?)<\/tbody>/)?.[1] || '';
 assert.equal((tbody.match(/<tr\b/g) || []).length, 50);
 assert.ok(html.includes('>2502</dd>'));
 assert.ok(html.includes('Página 1 de 51'));
 assert.ok(html.includes('Anterior') && html.includes('Siguiente'));
 assert.ok(!html.includes('Sintético 50<'));
 assert.equal(JSON.stringify(preview), original);
});
test('reviewed SYBI machine detail prioritizes operational fields and keeps identifiers in a closed technical section', async () => {
 const html = await renderVue('resources/js/Pages/VendingMachines/Show.vue', machineProps(), operator);
 assert.ok(html.includes('Información de origen SYBI'));
 assert.ok(!html.includes('Catálogo maestro SYBIML'));
 const technical = [...html.matchAll(/<details\b([^>]*)>([\s\S]*?)<\/details>/g)]
  .find(match => match[2].includes('Detalle técnico de SYBI'));
 assert.ok(technical);
 assert.ok(!/\bopen(?:\s|=|$)/.test(technical[1]));
 for (const id of ['source-id-only-in-detail', 'city-id-only-in-detail', 'state-id-only-in-detail']) {
  assert.ok(technical[2].includes(id));
  assert.ok(!html.replace(technical[0], '').includes(id));
 }
 for (const label of ['Origen', 'Última sincronización', 'Dirección de origen', 'Estado de sincronización']) assert.ok(html.includes(label));
 assert.ok(html.includes('05/09/2026 12:48'));
 assert.ok(html.includes('No hay empleados asignados.'));
 assert.ok(html.includes('Asignar empleado'));
});
test('reviewed Unicode survives Vue rendering in source catalog and machine detail without reinterpretation', async () => {
 const props = machineProps();
 const original = JSON.stringify(props);
 const html = await renderVue('resources/js/Pages/VendingMachines/Show.vue', props, operator);
 const catalog = await renderVue('resources/js/Pages/VendingMachines/Index.vue', {
  machines: { data: [], links: [] },
  sourceRecords: { data: [{ uuid: 'synthetic-source', name: words.join(' · '), sybi_full_address: words.join(' · '), source_status: 'PRESENT', validation_status: 'INCOMPLETE_LOCATION', validation_codes: ['ZERO_COORDINATES'] }], links: [] },
  filters: { catalog_view: 'sybi' }, statuses: [], coordinateSources: [], catalogSources: [], syncStatuses: [], sourceStatuses: [], validationStatuses: [], municipalities: [], localities: [],
  sybiIntegration: { configured: true },
 }, operator);
 for (const word of words) {
  assert.ok(html.includes(word));
  assert.ok(catalog.includes(word));
 }
 assert.equal(JSON.stringify(props), original);
 const mojibake = 'Lago Z\u00c3\u00barich';
 const sourceEvidence = machineProps();
 sourceEvidence.machine.sybi_full_address = mojibake;
 const rendered = await renderVue('resources/js/Pages/VendingMachines/Show.vue', sourceEvidence, operator);
 assert.ok(rendered.includes(mojibake), 'Do not guess a replacement for upstream source evidence');
});
test('reviewed audit labels use known full event codes and safe fallback, never generic action guesses', async () => {
 const { auditEventLabels, auditEventLabel } = await import('../../resources/js/presentation/audit.js');
 for (const [event, label] of Object.entries(auditEventLabels)) assert.equal(auditEventLabel(event), label);
 assert.equal(auditEventLabel('assignment.created'), 'Empleado asignado');
 assert.equal(auditEventLabel('geofence.updated'), 'Geocerca actualizada');
 assert.equal(auditEventLabel('vending_machine.sybi_created'), 'Máquina incorporada desde SYBI');
 assert.equal(auditEventLabel('device.bootstrap'), 'Configuración inicial consultada');
 assert.equal(auditEventLabel('device.provisioned'), 'Dispositivo activado');
 for (const unknown of ['machine.synced', 'NEW_EVENT', '', null, undefined, 'constructor', '__proto__']) assert.equal(auditEventLabel(unknown), 'Actividad registrada');
});
test('reviewed audit renders human labels with original codes and action only in closed technical details', async () => {
 const events = ['vending_machine.sybi_created', 'assignment.created', 'device.bootstrap', 'unknown.synthetic_event'];
 const logs = events.map((event, index) => ({ id: index + 1, event, action: 'create', description: 'Synthetic audit detail', user_name: 'Operador sintético', created_at: '2026-09-05T18:48:23Z' }));
 const props = machineProps(logs);
 const original = JSON.stringify(props);
 const html = await renderVue('resources/js/Pages/VendingMachines/Show.vue', props, operator);
 for (const label of ['Máquina incorporada desde SYBI', 'Empleado asignado', 'Configuración inicial consultada', 'Actividad registrada']) assert.ok(html.includes(label));
 const technical = [...html.matchAll(/<details\b([^>]*)>([\s\S]*?)<\/details>/g)];
 for (const event of events) {
  const detail = technical.find(match => match[2].includes('Evento: ' + event));
  assert.ok(detail);
  assert.ok(!/\bopen(?:\s|=|$)/.test(detail[1]));
  assert.ok(detail[2].includes('Acción: create'));
 }
 const outside = html.replace(/<details\b[^>]*>[\s\S]*?<\/details>/g, '');
 for (const event of events) assert.ok(!outside.includes(event));
 assert.equal(JSON.stringify(props), original);
});
