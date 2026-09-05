<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({ releases: Array, policies: Array, platforms: Array, channels: Array, statuses: Array, targetTypes: Array, rolloutPercentages: Array, canManage: Boolean });
const release = useForm({ platform: 'ANDROID', channel: 'DEV', target_type: 'CHANNEL', target_value: '', version: '', build_number: '', status: 'DRAFT', minimum_os: '', artifact_url: '', artifact_sha256: '', mandatory: false, rollout_percentage: 0, released_at: '', notes: '' });
const policy = useForm({ platform: 'ANDROID', channel: 'DEV', current_release_id: '', recommended_release_id: '', minimum_release_id: '' });
const target = useForm({ mobile_release_uuid: '', target_type: 'DEVICE', target_value: '' });
const available = computed(() => props.releases.filter((item) => item.platform === policy.platform && item.channel === policy.channel && item.status === 'PUBLISHED'));
const submitRelease = () => release.post(route('vending-releases.store'), { preserveScroll: true, onSuccess: () => release.reset('version', 'build_number', 'artifact_url', 'artifact_sha256', 'notes') });
const submitPolicy = () => policy.put(route('vending-releases.policy.update'), { preserveScroll: true });
const submitTarget = () => target.post(route('vending-releases.targets.store', target.mobile_release_uuid), { preserveScroll: true, onSuccess: () => target.reset('target_value') });
</script>

<template>
    <Head title="Releases móviles" />
    <AuthenticatedLayout>
        <template #header><div><Link :href="route('vending-fleet.dashboard')" class="text-sm text-indigo-600">← Operación vending</Link><h1 class="text-xl font-semibold text-app">Releases móviles</h1><p class="text-sm text-soft">Catálogo y política solamente; no aloja ni entrega APK/IPA.</p></div></template>
        <div class="space-y-6">
            <form v-if="canManage" class="card grid gap-3 p-5 md:grid-cols-4" @submit.prevent="submitRelease">
                <h2 class="font-semibold text-app md:col-span-4">Registrar metadata de release</h2>
                <label class="text-sm">Plataforma<select v-model="release.platform" class="mt-1 w-full rounded-xl border-app"><option v-for="value in platforms" :key="value">{{ value }}</option></select></label>
                <label class="text-sm">Canal<select v-model="release.channel" class="mt-1 w-full rounded-xl border-app"><option v-for="value in channels" :key="value">{{ value }}</option></select></label>
                <label class="text-sm">Target<select v-model="release.target_type" class="mt-1 w-full rounded-xl border-app"><option v-for="value in targetTypes" :key="value">{{ value }}</option></select></label>
                <label class="text-sm">UUID/grupo<input v-model="release.target_value" :disabled="release.target_type === 'CHANNEL'" class="mt-1 w-full rounded-xl border-app" placeholder="Sólo DEVICE/GROUP" /></label>
                <label class="text-sm">Versión<input v-model="release.version" class="mt-1 w-full rounded-xl border-app" placeholder="1.2.0" /></label>
                <label class="text-sm">Build<input v-model="release.build_number" type="number" min="1" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm">Estado<select v-model="release.status" class="mt-1 w-full rounded-xl border-app"><option v-for="value in statuses" :key="value">{{ value }}</option></select></label>
                <label class="text-sm">OS mínimo<input v-model="release.minimum_os" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm">Rollout<select v-model="release.rollout_percentage" class="mt-1 w-full rounded-xl border-app"><option v-for="value in rolloutPercentages" :key="value" :value="value">{{ value }}%</option></select></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="release.mandatory" type="checkbox" />Obligatoria</label>
                <label class="text-sm md:col-span-2">URL HTTPS del artefacto<input v-model="release.artifact_url" class="mt-1 w-full rounded-xl border-app" /></label>
                <label class="text-sm md:col-span-2">SHA-256<input v-model="release.artifact_sha256" class="mt-1 w-full rounded-xl border-app font-mono text-xs" /></label>
                <label class="text-sm md:col-span-4">Notas<textarea v-model="release.notes" class="mt-1 w-full rounded-xl border-app" /></label>
                <div class="md:col-span-4"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Registrar</button><span class="ml-3 text-sm text-rose-600">{{ Object.values(release.errors)[0] }}</span></div>
            </form>

            <form v-if="canManage" class="card grid gap-3 p-5 md:grid-cols-5" @submit.prevent="submitPolicy">
                <h2 class="font-semibold text-app md:col-span-5">Política por plataforma y canal</h2>
                <select v-model="policy.platform" class="rounded-xl border-app"><option v-for="value in platforms" :key="value">{{ value }}</option></select>
                <select v-model="policy.channel" class="rounded-xl border-app"><option v-for="value in channels" :key="value">{{ value }}</option></select>
                <select v-model="policy.current_release_id" class="rounded-xl border-app"><option value="">Current: ninguno</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <select v-model="policy.recommended_release_id" class="rounded-xl border-app"><option value="">Recommended: ninguno</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <select v-model="policy.minimum_release_id" class="rounded-xl border-app"><option value="">Minimum: ninguno</option><option v-for="item in available" :key="item.id" :value="item.id">{{ item.version }} #{{ item.build_number }}</option></select>
                <div class="md:col-span-5"><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Guardar política</button><span class="ml-3 text-sm text-rose-600">{{ Object.values(policy.errors)[0] }}</span></div>
            </form>

            <form v-if="canManage" class="card grid gap-3 p-5 md:grid-cols-4" @submit.prevent="submitTarget">
                <h2 class="font-semibold text-app md:col-span-4">Agregar target específico</h2>
                <select v-model="target.mobile_release_uuid" class="rounded-xl border-app"><option value="">Seleccionar release</option><option v-for="item in releases" :key="item.uuid" :value="item.uuid">{{ item.platform }} {{ item.channel }} · {{ item.version }} #{{ item.build_number }}</option></select>
                <select v-model="target.target_type" class="rounded-xl border-app"><option>DEVICE</option><option>GROUP</option></select>
                <input v-model="target.target_value" class="rounded-xl border-app" placeholder="Device UUID o nombre de grupo" />
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Agregar target</button>
            </form>

            <section class="card overflow-x-auto"><h2 class="p-5 font-semibold text-app">Políticas vigentes</h2><table class="w-full min-w-[48rem] text-sm"><thead><tr class="text-left text-soft"><th class="p-3">Plataforma</th><th class="p-3">Canal</th><th class="p-3">Current</th><th class="p-3">Recommended</th><th class="p-3">Minimum</th></tr></thead><tbody><tr v-for="item in policies" :key="`${item.platform}-${item.channel}`" class="border-t border-app"><td class="p-3">{{ item.platform }}</td><td class="p-3">{{ item.channel }}</td><td class="p-3 font-mono">{{ item.current_release?.version || '—' }}</td><td class="p-3 font-mono">{{ item.recommended_release?.version || '—' }}</td><td class="p-3 font-mono">{{ item.minimum_release?.version || '—' }}</td></tr><tr v-if="!policies.length"><td colspan="5" class="p-5 text-center text-soft">Sin políticas configuradas.</td></tr></tbody></table></section>

            <section class="card overflow-x-auto"><table class="w-full min-w-[68rem] text-sm"><thead><tr class="text-left text-soft"><th class="p-3">Plataforma</th><th class="p-3">Canal/targets</th><th class="p-3">Versión/build</th><th class="p-3">Estado</th><th class="p-3">Rollout</th><th class="p-3">Obligatoria</th><th class="p-3">Checksum</th><th class="p-3">Publicada</th></tr></thead><tbody><tr v-for="item in releases" :key="item.uuid" class="border-t border-app"><td class="p-3">{{ item.platform }}</td><td class="p-3">{{ item.channel }}<br><span class="text-xs text-soft">{{ item.targets?.length ? item.targets.map((value) => `${value.target_type}:${value.target_value}`).join(', ') : 'CHANNEL completo' }}</span></td><td class="p-3 font-mono">{{ item.version }} / {{ item.build_number }}</td><td class="p-3">{{ item.status }}</td><td class="p-3">{{ item.rollout_percentage }}%</td><td class="p-3">{{ item.mandatory ? 'Sí' : 'No' }}</td><td class="p-3 font-mono text-xs">{{ item.artifact_sha256 ? `${item.artifact_sha256.slice(0, 12)}…` : '—' }}</td><td class="p-3">{{ item.released_at || '—' }}</td></tr><tr v-if="!releases.length"><td colspan="8" class="p-5 text-center text-soft">Sin releases registradas.</td></tr></tbody></table></section>
        </div>
    </AuthenticatedLayout>
</template>
