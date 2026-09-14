<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import TechnicalDetails from '@/Components/TechnicalDetails.vue';
import { statusLabel, friendlyError, formatDateTime } from '@/presentation/labels';
import { computed, reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import betaCandidate from '../../../../mobile/internal-beta.json';

const props = defineProps({ releases: Array, policies: Array, platforms: Array, channels: Array, statuses: Array, targetTypes: Array, rolloutPercentages: Array, canManage: Boolean });
const release = useForm({ platform: 'ANDROID', channel: 'DEV', target_type: 'CHANNEL', target_value: '', version: '', build_number: '', status: 'DRAFT', minimum_os: '', artifact_url: '', artifact_sha256: '', mandatory: false, rollout_percentage: 0, released_at: '', notes: '' });
const policy = useForm({ platform: 'ANDROID', channel: 'DEV', current_release_id: '', recommended_release_id: '', minimum_release_id: '' });
const target = useForm({ mobile_release_uuid: '', target_type: 'DEVICE', target_value: '' });
const available = computed(() => props.releases.filter((item) => item.platform === policy.platform && item.channel === policy.channel && item.status === 'PUBLISHED'));
const submitRelease = () => release.post(route('vending-releases.store'), { preserveScroll: true, onSuccess: () => release.reset('version', 'build_number', 'artifact_url', 'artifact_sha256', 'notes') });
const submitPolicy = () => policy.put(route('vending-releases.policy.update'), { preserveScroll: true });
const submitTarget = () => target.post(route('vending-releases.targets.store', target.mobile_release_uuid), { preserveScroll: true, onSuccess: () => target.reset('target_value') });
const blockRelease = (item) => {
    if (!window.confirm(`Bloquear ${item.version} #${item.build_number} y detener su distribución?`)) return;
    useForm({}).patch(route('vending-releases.block', item.uuid), { preserveScroll: true });
};
</script>

<template>
    <Head title="Versiones de aplicación" />
    <AuthenticatedLayout>
        <template #header><div><Link :href="route('vending-fleet.dashboard')" class="text-sm text-indigo-600">← Operación vending</Link><h1 class="text-xl font-semibold text-app">Versiones de aplicación</h1><p class="text-sm text-soft">Administra versiones y criterios de distribución. Esta pantalla no instala ni distribuye archivos de la aplicación.</p></div></template>
        <div class="space-y-6">
            <section class="card space-y-2 p-5" aria-label="Candidata de beta interna">
                <h2 class="font-semibold text-app">{{ betaCandidate.label }} · candidata de revisión</h2>
                <p class="text-sm text-app">Android {{ betaCandidate.version }} · compilación {{ betaCandidate.build }} · DEBUG / DEMO local</p>
                <p class="text-sm text-soft">Metadata preparada, no publicada. Requiere pruebas y revisión visual antes de distribuir. No cambia las políticas vigentes ni confirma la versión instalada de cada teléfono.</p>
            </section>
            <details v-if="canManage" class="card p-4"><summary class="min-h-11 cursor-pointer py-2 font-semibold text-app">Registrar versión</summary><form class="card grid gap-3 p-5 md:grid-cols-4" @submit.prevent="submitRelease">
                <h2 class="font-semibold text-app md:col-span-4">Registrar versión de aplicación</h2>
                <label class="text-sm">Plataforma<select v-model="release.platform" class="mt-1 w-full rounded-xl border-app"><option v-for="value in platforms" :key="value" :value="value">{{ statusLabel(value) }}</option></select></label>
                <label class="text-sm">Canal<select v-model="release.channel" class="mt-1 w-full rounded-xl border-app"><option v-for="value in channels" :key="value" :value="value">{{ statusLabel(value) }}</option></select></label>
                <label class="text-sm">Destino<select v-model="release.target_type" class="mt-1 w-full rounded-xl border-app"><option v-for="value in targetTypes" :key="value" :value="value">{{ statusLabel(value) }}</option></select></label>
                <label class="text-sm">UUID/grupo<input v-model="release.target_value" :disabled="release.target_type === 'CHANNEL'" class="mt-1 w-full rounded-xl border-app" placeholder="Identificador del dispositivo o grupo" /></label>
                <label class="text-sm">Versión<input v-model="release.version" class="mt-1 w-full rounded-xl border-app" placeholder="1.2.0" /></label>
                <label class="text-sm">Compilación<input v-model="release.build_number" type="number" min="1" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm">Estado<select v-model="release.status" class="mt-1 w-full rounded-xl border-app"><option v-for="value in statuses" :key="value" :value="value">{{ statusLabel(value) }}</option></select></label>
                <label class="text-sm">Sistema operativo mínimo<input v-model="release.minimum_os" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm">Distribución gradual<select v-model="release.rollout_percentage" class="mt-1 w-full rounded-xl border-app"><option v-for="value in rolloutPercentages" :key="value" :value="value">{{ value }}%</option></select></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="release.mandatory" type="checkbox" />Obligatoria</label>
                <label class="text-sm md:col-span-2">URL HTTPS del artefacto<input v-model="release.artifact_url" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm md:col-span-2">SHA-256<input v-model="release.artifact_sha256" class="mt-1 w-full rounded-xl border-app font-mono text-xs" /></label>
                <label class="text-sm md:col-span-4">Notas<textarea v-model="release.notes" class="mt-1 w-full rounded-xl border-app" /></label>
                <div class="md:col-span-4"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Registrar</button><span class="ml-3 text-sm text-rose-600">{{ friendlyError(Object.values(release.errors)[0]) }}</span></div>
            </form></details>

            <details v-if="canManage" class="card p-4"><summary class="min-h-11 cursor-pointer py-2 font-semibold text-app">Editar política de versiones</summary><form class="card grid gap-3 p-5 md:grid-cols-5" @submit.prevent="submitPolicy">
                <h2 class="font-semibold text-app md:col-span-5">Política por plataforma y canal</h2>
                <select aria-label="Plataforma" v-model="policy.platform" class="rounded-xl border-app"><option v-for="value in platforms" :key="value" :value="value">{{ statusLabel(value) }}</option></select>
                <select aria-label="Canal" v-model="policy.channel" class="rounded-xl border-app"><option v-for="value in channels" :key="value" :value="value">{{ statusLabel(value) }}</option></select>
                <select aria-label="Versión actual" v-model="policy.current_release_id" class="rounded-xl border-app"><option value="">Actual: ninguna</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <select aria-label="Versión recomendada" v-model="policy.recommended_release_id" class="rounded-xl border-app"><option value="">Recomendada: ninguna</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <select aria-label="Versión mínima" v-model="policy.minimum_release_id" class="rounded-xl border-app"><option value="">Mínima: ninguna</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <div class="md:col-span-5"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar política</button><span class="ml-3 text-sm text-rose-600">{{ friendlyError(Object.values(policy.errors)[0]) }}</span></div>
            </form></details>

            <details v-if="canManage" class="card p-4"><summary class="min-h-11 cursor-pointer py-2 font-semibold text-app">Agregar destinatario</summary><form class="card grid gap-3 p-5 md:grid-cols-4" @submit.prevent="submitTarget">
                <h2 class="font-semibold text-app md:col-span-4">Agregar destinatario</h2>
                <select aria-label="Versión de aplicación" v-model="target.mobile_release_uuid" class="rounded-xl border-app"><option value="">Seleccionar versión</option><option v-for="item in releases" :key="item.uuid" :value="item.uuid">{{ statusLabel(item.platform) }} {{ statusLabel(item.channel) }} · {{ item.version }} #{{ item.build_number }}</option></select>
                <select aria-label="Tipo de destinatario" v-model="target.target_type" class="rounded-xl border-app"><option value="DEVICE">Dispositivo</option><option value="GROUP">Grupo</option></select>
                <input aria-label="Identificador o grupo" v-model="target.target_value" class="rounded-xl border-app" placeholder="UUID del dispositivo o nombre de grupo" />
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Agregar destinatario</button><p v-if="Object.keys(target.errors).length" role="alert" class="text-sm text-rose-700 md:col-span-4">{{ friendlyError(Object.values(target.errors)[0]) }}</p>
            </form></details>

            <section class="card overflow-x-auto"><h2 class="p-5 font-semibold text-app">Políticas vigentes</h2><table class="w-full text-sm"><thead><tr class="text-left text-soft"><th class="p-3">Plataforma</th><th class="p-3">Canal</th><th class="p-3">Actual</th><th class="p-3">Recomendada</th><th class="p-3">Mínima</th></tr></thead><tbody><tr v-for="item in policies" :key="`${item.platform}-${item.channel}`" class="border-t border-app"><td class="p-3">{{ statusLabel(item.platform) }}</td><td class="p-3">{{ statusLabel(item.channel) }}</td><td class="p-3 font-mono">{{ item.current_release?.version || '—' }}</td><td class="p-3 font-mono">{{ item.recommended_release?.version || '—' }}</td><td class="p-3 font-mono">{{ item.minimum_release?.version || '—' }}</td></tr><tr v-if="!policies.length"><td colspan="5" class="p-5 text-center text-soft">Sin políticas configuradas.</td></tr></tbody></table></section>

            <section class="card overflow-x-auto"><h2 class="p-5 font-semibold text-app">Versiones registradas</h2><table class="w-full text-left text-sm"><thead class="text-soft"><tr><th class="p-3">Aplicación</th><th class="p-3">Canal</th><th class="p-3">Estado</th><th class="p-3">Distribución</th><th class="p-3">Publicación</th><th class="p-3">Detalle y acciones</th></tr></thead><tbody><tr v-for="item in releases" :key="item.uuid" class="border-t border-app align-top"><td class="p-3">{{ statusLabel(item.platform) }} · {{ item.version }}<p class="text-xs text-soft">Compilación {{ item.build_number }}</p></td><td class="p-3">{{ statusLabel(item.channel) }}</td><td class="p-3"><StatusBadge :value="item.status" /></td><td class="p-3">{{ item.rollout_percentage }}%<p class="text-xs text-soft">{{ item.mandatory ? 'Actualización obligatoria' : 'Actualización opcional' }}</p></td><td class="p-3">{{ formatDateTime(item.released_at, 'Sin publicar') }}</td><td class="p-3"><TechnicalDetails><p>UUID: {{ item.uuid }}</p><p>SHA-256: {{ item.artifact_sha256 || 'Sin información' }}</p><p>Archivo: {{ item.artifact_url ? 'Archivo registrado' : 'Sin archivo registrado' }}</p><p>Sistema operativo mínimo: {{ item.minimum_os || 'Sin información' }}</p><p>Destinatarios: {{ item.targets?.length ? item.targets.map(value => statusLabel(value.target_type) + ': ' + value.target_value).join(', ') : 'Canal completo' }}</p><p>Notas: {{ item.notes || 'Sin notas' }}</p></TechnicalDetails><button v-if="canManage && item.status === 'PUBLISHED'" type="button" class="min-h-11 font-semibold text-rose-600" @click="blockRelease(item)">Bloquear distribución</button></td></tr><tr v-if="!releases.length"><td colspan="6" class="p-8 text-center text-soft">No hay versiones registradas.</td></tr></tbody></table></section>
        </div>
    </AuthenticatedLayout>
</template>
