<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import { formatDateTime } from '@/presentation/labels';

defineProps({ tab: String, counts: Object, devices: Object, employees: Object, canManage: Boolean });
const tabs = { summary: 'Resumen', devices: 'Dispositivos', enrollments: 'Enrolamientos' };
const statuses = { ACTIVE: 'Activo', PENDING: 'Pendiente', REVOKED: 'Revocado', REPLACED: 'Reemplazado' };
const totals = { ACTIVE: 'Activos', PENDING: 'Pendientes', REVOKED: 'Revocados', REPLACED: 'Reemplazados' };
const selected = ref(null);
const form = useForm({ confirm: false });
function revoke(uuid) {
    form.post(route('field-identity.admin.revoke', uuid), { preserveScroll: true, onSuccess: () => { selected.value = null; form.reset(); } });
}
</script>

<template>
    <Head title="Identidad y biometría" />
    <AuthenticatedLayout>
        <template #header><h1 class="text-2xl font-semibold text-app">Identidad y biometría</h1></template>
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            <p class="text-sm text-soft">Administración de dispositivos personales. No modifica los terminales de las máquinas ni habilita asistencias.</p>
            <nav aria-label="Identidad y biometría" class="flex flex-wrap gap-2">
                <Link v-for="(label, key) in tabs" :key="key" :href="route('field-identity.admin.index', { tab: key })"
                    class="min-h-11 rounded-lg border border-app px-4 py-2 text-sm font-semibold"
                    :class="tab === key ? 'bg-indigo-600 text-white' : 'text-app'" :aria-current="tab === key ? 'page' : undefined">{{ label }}</Link>
            </nav>
            <p v-if="$page.props.flash?.success" role="status" class="card p-4">{{ $page.props.flash.success }}</p>
            <section v-if="tab === 'summary'" class="space-y-6">
                <h2 class="text-lg font-semibold text-app">Dispositivos personales</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="(label, status) in totals" :key="status" class="card p-5"><p class="text-sm text-soft">{{ label }}</p><p class="mt-2 text-3xl font-semibold text-app">{{ counts[status] }}</p></div>
                </div>
                <div class="card space-y-2 p-5"><h2 class="text-lg font-semibold text-app">Biometría no habilitada</h2><p>Motor facial: Pendiente de selección</p><p>Enrolamiento: No disponible todavía</p><p class="text-sm text-soft">La identidad criptográfica del teléfono no es reconocimiento facial.</p></div>
            </section>
            <section v-else-if="tab === 'devices'" class="space-y-4" aria-label="Dispositivos personales">
                <p v-if="!devices.data.length" class="card p-6 text-soft">Todavía no hay dispositivos personales registrados.</p>
                <article v-for="device in devices.data" :key="device.uuid" class="card min-w-0 space-y-4 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words text-lg font-semibold text-app">{{ device.employee }}</h2><p class="text-sm text-soft">Número: {{ device.number }}</p></div><span class="rounded-lg border border-app px-3 py-1 text-sm">{{ statuses[device.status] ?? 'Estado no disponible' }}</span></div>
                    <dl class="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div><dt class="text-soft">Dispositivo</dt><dd class="break-words">{{ device.model }}</dd></div>
                        <div><dt class="text-soft">Teléfono</dt><dd>{{ device.phone }}</dd></div>
                        <div><dt class="text-soft">Modo de verificación</dt><dd>{{ device.simulation ? 'Demo local' : 'Sin verificación telefónica confirmada' }}</dd></div>
                        <div><dt class="text-soft">Identidad criptográfica</dt><dd>{{ device.crypto_verified ? 'Firma comprobada' : 'Pendiente de comprobación' }}</dd></div>
                        <div><dt class="text-soft">Última conexión</dt><dd>{{ formatDateTime(device.last_seen_at) }}</dd></div>
                        <div><dt class="text-soft">Biometría</dt><dd>No habilitada</dd></div>
                    </dl>
                    <div v-if="canManage && ['ACTIVE', 'PENDING'].includes(device.status)">
                        <button v-if="selected !== device.uuid" type="button" class="min-h-11 rounded-lg border border-app px-4 py-2 text-sm" @click="selected = device.uuid; form.reset()">Revocar dispositivo</button>
                        <form v-else class="space-y-3 rounded-lg border border-app p-4" @submit.prevent="revoke(device.uuid)">
                            <p>Este teléfono dejará de acreditar identidad. Se conservará su historial.</p>
                            <label class="flex min-h-11 items-center gap-2"><input v-model="form.confirm" type="checkbox" required :disabled="form.processing" /> Confirmo la revocación de este dispositivo.</label>
                            <p v-if="form.errors.confirm" role="alert">{{ form.errors.confirm }}</p>
                            <div class="flex flex-wrap gap-2"><button class="btn-primary min-h-11" :disabled="!form.confirm || form.processing">{{ form.processing ? 'Guardando…' : 'Confirmar revocación' }}</button><button type="button" class="min-h-11 rounded-lg border border-app px-4 py-2" :disabled="form.processing" @click="selected = null">Cancelar</button></div>
                        </form>
                    </div>
                </article>
                <RecordPagination v-if="devices.data.length" :links="devices.links" />
                <details class="card p-4"><summary class="cursor-pointer font-semibold">Reemplazo de un teléfono</summary><p class="mt-3 text-sm text-soft">El titular debe verificar el nuevo teléfono con un código y una nueva firma. La administración no puede fabricar una clave ni activar un reemplazo sin esa prueba. Revocar conserva el historial; no equivale a completar un reemplazo.</p></details>
            </section>
            <section v-else class="space-y-4" aria-label="Enrolamientos">
                <div class="card p-5"><h2 class="font-semibold">Motor: No habilitado</h2><p class="mt-2 text-sm text-soft">La captura y la administración de plantillas estarán disponibles en una fase posterior. No se crean registros biométricos desde esta pantalla.</p></div>
                <p v-if="!employees.data.length" class="card p-6">No hay empleados para mostrar.</p>
                <ul v-else class="space-y-3"><li v-for="employee in employees.data" :key="employee.id" class="card flex flex-wrap justify-between gap-3 p-4"><div class="min-w-0"><p class="break-words font-semibold">{{ employee.name }}</p><p class="text-sm text-soft">{{ employee.number }}</p></div><p class="text-sm">{{ employee.legacy_enrollment ? 'Registro previo; no habilitado en dispositivos personales' : 'No enrolado' }}</p></li></ul>
                <RecordPagination v-if="employees.data.length" :links="employees.links" />
            </section>
        </div>
    </AuthenticatedLayout>
</template>
