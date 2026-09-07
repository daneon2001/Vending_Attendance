<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import { statusLabel, friendlyError, formatDateTime } from '@/presentation/labels';
import { auditEventLabel } from '@/presentation/audit';
import { canUse } from '@/presentation/navigation';
import { usePage } from '@inertiajs/vue3';
const page = usePage();
const can = (action) => canUse(page.props.auth?.permissions, 'vending_machines', action);
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';

const props = defineProps({ machine: Object, auditLogs: Array, employees: Array, assignmentTypes: Array, geofenceStatuses: Array });
const assignment = useForm({ employee_id: '', assignment_type: 'PRIMARY', valid_from: new Date().toISOString().slice(0, 16), valid_until: '', attendance_allowed: true, enrollment_allowed: false, maintenance_allowed: false, source: 'MANUAL' });
const geofence = useForm({ center_latitude: props.machine.latitude ?? '', center_longitude: props.machine.longitude ?? '', radius_m: props.machine.default_geofence_radius_m ?? 40, minimum_acceptable_accuracy_m: 25, tolerance_m: 0, valid_from: '', valid_until: '', status: 'DRAFT', source: 'MANUAL' });
const revoke = (item) => useForm({ reason: 'Revocación administrativa' }).patch(route('vending-machines.assignments.revoke', [props.machine.uuid, item.uuid]), { preserveScroll: true });
const activate = (item) => useForm({}).patch(route('vending-machines.geofences.activate', [props.machine.uuid, item.uuid]), { preserveScroll: true });
const employeeName = (item) => item.employee?.full_name || `${item.employee?.name ?? ''} ${item.employee?.last_name ?? ''}`.trim() || '—';
const permissions = (item) => [item.attendance_allowed && 'Asistencia', item.enrollment_allowed && 'Enrolamiento', item.maintenance_allowed && 'Mantenimiento'].filter(Boolean).join(', ') || 'Sin permisos';
const provisioningToken = ref(null);
const provisioningLoading = ref(false);
const provisioningError = ref('');
const generateProvisioningToken = async () => {
    provisioningLoading.value = true;
    provisioningError.value = '';
    try {
        const { data } = await axios.post(route('vending-machines.provisioning-tokens.store', props.machine.uuid), { expires_in_minutes: 30 });
        provisioningToken.value = data;
    } catch (error) {
        provisioningError.value = error.response?.data?.message ?? 'No se pudo generar el código de activación.';
    } finally {
        provisioningLoading.value = false;
    }
};
const updateDeviceStatus = (device, status) => useForm({ status }).patch(route('vending-machines.devices.status', [props.machine.uuid, device.uuid]), { preserveScroll: true });
const revokeProvisioningToken = (token) => useForm({ reason: 'Revocación administrativa' }).patch(route('vending-machines.provisioning-tokens.revoke', [props.machine.uuid, token.uuid]), { preserveScroll: true });
const provisioningState = (token) => token.used_at ? 'USED' : token.revoked_at ? 'REVOKED' : new Date(token.expires_at) <= new Date() ? 'EXPIRED' : 'AVAILABLE';
</script>

<template>
    <Head :title="`Máquina ${machine.machine_code}`" />
    <AuthenticatedLayout>
        <template #header><div><Link :href="route('vending-machines.index')" class="text-sm text-indigo-600">← Máquinas vending</Link><h1 class="text-xl font-semibold text-app">{{ machine.machine_code }} · {{ machine.name || 'Sin nombre' }}</h1></div></template>
        <div class="space-y-6">
            <section class="grid gap-4 lg:grid-cols-2">
                <article class="card p-5"><h2 class="font-semibold text-app">Datos generales</h2><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><template v-for="row in [['Código',machine.machine_code],['Estado',statusLabel(machine.status)],['Dirección',machine.address_line],['Municipio',machine.municipality],['Localidad',machine.locality],['CP',machine.postal_code]]" :key="row[0]"><dt class="text-soft">{{ row[0] }}</dt><dd class="text-app">{{ row[1] || '—' }}</dd></template></dl></article>
                <article class="card p-5"><h2 class="font-semibold text-app">Ubicación</h2><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><template v-for="row in [['Latitud',machine.latitude],['Longitud',machine.longitude],['Origen',statusLabel(machine.coordinate_source)],['Verificada',machine.coordinates_verified ? 'Sí':'No'],['Fecha verificación',formatDateTime(machine.coordinates_verified_at)]]" :key="row[0]"><dt class="text-soft">{{ row[0] }}</dt><dd class="text-app">{{ row[1] || '—' }}</dd></template></dl></article>
            </section>

            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-app">Información de origen SYBI</h2><p class="text-sm text-soft">Identidad, dirección y coordenadas de origen son de sólo lectura cuando la fuente es SYBI.</p></div><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-200">Origen · {{ statusLabel(machine.source) }}</span></div>
                <div v-if="machine.geofence_review_required" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">SYBI reportó un cambio de coordenadas. La geocerca activa no fue desplazada y requiere revisión administrativa.</div>
                <dl class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                    <div><dt class="text-soft">Estado de sincronización</dt><dd class="text-app">{{ statusLabel(machine.sybi_sync_status) }}</dd></div>
                    <div><dt class="text-soft">Última sincronización</dt><dd class="text-app" title="Última presencia reportada por SYBI">{{ formatDateTime(machine.sybi_last_seen_at) }}</dd></div>
                    <div><dt class="text-soft">Dirección de origen</dt><dd class="break-words text-app">{{ machine.sybi_full_address ?? '—' }}</dd></div>
                </dl>
                <details class="mt-3 text-xs text-soft">
                    <summary class="min-h-11 cursor-pointer py-2 font-medium">Detalle técnico de SYBI</summary>
                    <dl class="grid gap-3 rounded-lg border border-app p-3 sm:grid-cols-2 md:grid-cols-3"><template v-for="row in [['SYBI ID',machine.sybi_id],['Identificador vending',machine.machine_code],['ID ciudad',machine.sybi_city_id],['ID estado',machine.sybi_state_id],['Cambio de coordenadas',formatDateTime(machine.sybi_coordinates_changed_at)]]" :key="row[0]"><div><dt>{{ row[0] }}</dt><dd class="break-words text-app">{{ row[1] ?? '—' }}</dd></div></template></dl>
                </details>
            </section>

            <section class="card p-5"><div class="flex items-center justify-between"><h2 class="font-semibold text-app">Geocercas</h2><span class="text-sm text-soft">Configuración {{ machine.config_version }}</span></div>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-soft"><th class="p-2">Versión</th><th class="p-2">Centro</th><th class="p-2">Radio</th><th class="p-2">Precisión requerida</th><th class="p-2">Tolerancia</th><th class="p-2">Estado</th><th class="p-2"></th></tr></thead><tbody><tr v-for="item in machine.geofences" :key="item.uuid" class="border-t border-app"><td class="p-2">{{ item.version }}</td><td class="p-2 font-mono text-xs">{{ item.center_latitude }}, {{ item.center_longitude }}</td><td class="p-2">{{ item.radius_m }} m</td><td class="p-2">{{ item.minimum_acceptable_accuracy_m ?? '—' }}</td><td class="p-2">{{ item.tolerance_m }} m</td><td class="p-2"><StatusBadge :value="item.status" /></td><td class="p-2"><button v-if="can('geofence') && ['DRAFT', 'INACTIVE'].includes(item.status)" class="text-indigo-600" @click="activate(item)">Activar</button></td></tr><tr v-if="!machine.geofences?.length"><td colspan="7" class="p-5 text-soft">No hay geocercas registradas.</td></tr></tbody></table></div>
                <details v-if="can('geofence')" class="mt-4"><summary class="min-h-11 cursor-pointer py-2 font-semibold text-indigo-600">Crear geocerca</summary><form class="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800 md:grid-cols-4" @submit.prevent="geofence.post(route('vending-machines.geofences.store', machine.uuid), { preserveScroll: true, onSuccess: () => geofence.reset() })"><h3 class="font-semibold text-app md:col-span-4">Nueva geocerca circular</h3><p class="text-xs text-soft md:col-span-4">El radio define la distancia permitida al centro. La precisión indica el margen aceptable del GPS; la tolerancia agrega el margen configurado a la evaluación. Una nueva versión no borra las anteriores.</p><label v-for="field in [{k:'center_latitude',l:'Latitud'},{k:'center_longitude',l:'Longitud'},{k:'radius_m',l:'Radio (m)'},{k:'minimum_acceptable_accuracy_m',l:'Precisión requerida (m)'},{k:'tolerance_m',l:'Tolerancia (m)'}]" :key="field.k" class="text-sm">{{ field.l }}<input v-model="geofence[field.k]" type="number" step="0.01" class="mt-1 w-full rounded-xl border-app" /><span class="text-xs text-rose-600">{{ friendlyError(geofence.errors[field.k]) }}</span></label><label class="text-sm">Estado<select v-model="geofence.status" class="mt-1 w-full rounded-xl border-app"><option v-for="status in geofenceStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label><div class="flex items-end"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Crear versión</button></div></form></details>
            </section>

            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold text-app">Dispositivos</h2><p class="text-sm text-soft">Consulta la conexión de los dispositivos asociados a esta máquina.</p></div><button v-if="can('manage')" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" :disabled="provisioningLoading" @click="generateProvisioningToken">Generar código de activación</button></div>
                <div v-if="provisioningToken" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"><p class="font-semibold">Código de activación: visible una sola vez. No lo compartas durante la demostración.</p><code class="mt-2 block break-all rounded-lg bg-white p-3 text-xs text-slate-900">{{ provisioningToken.token }}</code><p class="mt-2">Expira: {{ formatDateTime(provisioningToken.expires_at) }}</p><button class="mt-2 text-xs font-semibold underline" @click="provisioningToken = null">Ocultar definitivamente</button></div>
                <p v-if="provisioningError" class="mt-3 text-sm text-rose-600">{{ friendlyError(provisioningError) }}</p>
                <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="text-soft"><th class="p-2">Dispositivo</th><th class="p-2">Estado</th><th class="p-2">Última conexión</th><th class="p-2">Sincronización</th><th class="p-2">Detalle y acciones</th></tr></thead><tbody>
<tr v-for="device in machine.devices" :key="device.uuid" class="border-t border-app align-top"><td class="p-2">{{ device.device_serial || 'Sin número de serie' }}<p class="text-xs text-soft">{{ statusLabel(device.platform) }} · {{ device.app_version || 'Sin versión' }}</p></td><td class="p-2"><StatusBadge :value="device.status" /></td><td class="p-2">{{ formatDateTime(device.last_seen_at) }}</td><td class="p-2"><StatusBadge :value="device.manifest_sync?.sync_state" /></td><td class="p-2"><TechnicalDetails><p>UUID: {{ device.uuid }}</p><p>Sistema operativo: {{ device.platform_version || 'Sin información' }}</p><p>Configuración servidor / dispositivo: {{ device.manifest_sync?.configuration?.server_version ?? '—' }} / {{ device.manifest_sync?.configuration?.applied_version ?? '—' }}</p><p>Empleados servidor / dispositivo: {{ device.manifest_sync?.employees?.server_version ?? '—' }} / {{ device.manifest_sync?.employees?.applied_version ?? '—' }}</p><p>Diferencia de hora: {{ device.clock_drift_seconds == null ? 'Sin información' : device.clock_drift_seconds + ' s' }}</p>
<div v-if="can('manage') && !['REVOKED','RETIRED'].includes(device.status)" class="flex flex-wrap gap-3"><button v-if="device.status === 'ACTIVE'" class="min-h-11 text-amber-700" @click="updateDeviceStatus(device,'SUSPENDED')">Suspender</button><button class="min-h-11 text-rose-600" @click="updateDeviceStatus(device,'REVOKED')">Revocar acceso</button><button class="min-h-11 text-slate-600" @click="updateDeviceStatus(device,'RETIRED')">Retirar</button></div>
</TechnicalDetails></td></tr><tr v-if="!machine.devices?.length"><td colspan="5" class="p-5 text-center text-soft">No hay dispositivos activados para esta máquina.</td></tr></tbody></table></div>
                <details class="mt-6"><summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold text-app">Detalle técnico · Códigos de activación recientes</summary><div class="mt-2 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-soft"><th class="p-2">UUID</th><th class="p-2">Expira</th><th class="p-2">Estado</th><th class="p-2"></th></tr></thead><tbody><tr v-for="token in machine.provisioning_tokens" :key="token.uuid" class="border-t border-app"><td class="p-2 font-mono text-xs">{{ token.uuid }}</td><td class="p-2">{{ formatDateTime(token.expires_at) }}</td><td class="p-2">{{ statusLabel(provisioningState(token)) }}</td><td class="p-2"><button v-if="can('manage') && provisioningState(token) === 'AVAILABLE'" class="text-rose-600" @click="revokeProvisioningToken(token)">Revocar</button></td></tr></tbody></table></div></details>
            </section>

            <section class="card p-5"><h2 class="font-semibold text-app">Empleados asignados</h2><div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-soft"><th class="p-2">Empleado</th><th class="p-2">Número</th><th class="p-2">Tipo</th><th class="p-2">Desde / hasta</th><th class="p-2">Permisos</th><th class="p-2">Estado</th><th class="p-2"></th></tr></thead><tbody><tr v-for="item in machine.assignments" :key="item.uuid" class="border-t border-app"><td class="p-2">{{ employeeName(item) }}</td><td class="p-2">{{ item.employee?.employee_number ?? item.employee?.fortia_employee_id ?? 'Sin número' }}</td><td class="p-2">{{ statusLabel(item.assignment_type) }}</td><td class="p-2">{{ formatDateTime(item.valid_from) }}<br>{{ formatDateTime(item.valid_until, 'Sin fin') }}</td><td class="p-2">{{ permissions(item) }}</td><td class="p-2"><StatusBadge :value="item.status" /></td><td class="p-2"><button v-if="can('assign') && item.status === 'ACTIVE'" class="text-rose-600" @click="revoke(item)">Revocar</button></td></tr><tr v-if="!machine.assignments?.length"><td colspan="7" class="p-5 text-soft">No hay empleados asignados.</td></tr></tbody></table></div>
                <details v-if="can('assign')" class="mt-4"><summary class="min-h-11 cursor-pointer py-2 font-semibold text-indigo-600">Asignar empleado</summary><form class="mt-5 grid gap-3 rounded-xl bg-slate-50 p-4 dark:bg-slate-800 md:grid-cols-4" @submit.prevent="assignment.post(route('vending-machines.assignments.store', machine.uuid), { preserveScroll: true, onSuccess: () => assignment.reset() })"><h3 class="font-semibold text-app md:col-span-4">Asignar empleado</h3><label class="text-sm md:col-span-2">Empleado<select v-model="assignment.employee_id" class="mt-1 w-full rounded-xl border-app"><option value="">Seleccionar</option><option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.fortia_employee_id }} · {{ employee.full_name || `${employee.name} ${employee.last_name}` }}</option></select><span class="text-xs text-rose-600">{{ friendlyError(assignment.errors.employee_id) }}</span></label><label class="text-sm">Tipo<select v-model="assignment.assignment_type" class="mt-1 w-full rounded-xl border-app"><option v-for="type in assignmentTypes" :key="type" :value="type">{{ statusLabel(type) }}</option></select></label><label class="text-sm">Desde<input v-model="assignment.valid_from" type="datetime-local" class="mt-1 w-full rounded-xl border-app" /></label><label class="text-sm">Hasta<input v-model="assignment.valid_until" type="datetime-local" class="mt-1 w-full rounded-xl border-app" /></label><label v-for="permission in [{k:'attendance_allowed',l:'Asistencia'},{k:'enrollment_allowed',l:'Enrolamiento'},{k:'maintenance_allowed',l:'Mantenimiento'}]" :key="permission.k" class="flex items-center gap-2 text-sm"><input v-model="assignment[permission.k]" type="checkbox" />{{ permission.l }}</label><div><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Asignar</button></div></form></details>
            </section>

            <section class="card p-5"><h2 class="font-semibold text-app">Auditoría reciente</h2><ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800"><li v-for="log in auditLogs" :key="log.id" class="py-3 text-sm"><span class="font-semibold text-app">{{ auditEventLabel(log.event) }}</span><span class="ml-2 text-soft">{{ log.user_name || log.actor_identifier || 'Sistema' }} · {{ formatDateTime(log.created_at) }}</span><TechnicalDetails><p>Evento: {{ log.event }}</p><p>Acción: {{ log.action || 'Sin información' }}</p><p>{{ log.description }}</p></TechnicalDetails></li><li v-if="!auditLogs.length" class="py-4 text-soft">Sin eventos todavía.</li></ul></section>
        </div>
    </AuthenticatedLayout>
</template>
