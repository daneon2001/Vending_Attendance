<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdvancedFilters from '@/Components/AdvancedFilters.vue';
import EmptyState from '@/Components/EmptyState.vue';
import RecordPagination from '@/Components/RecordPagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SupportActivityList from '@/Components/SupportActivityList.vue';
import SupportActivityPicker from '@/Components/SupportActivityPicker.vue';
import { activeFilterCount } from '@/presentation/labels';
import { activityAdvancedKeys, activityEmptyMessage, activityLabel, activityStatuses } from '@/presentation/supportActivities';
const props = defineProps({ activities: Object, filters: Object, types: Array, canCreate: Boolean, unavailable: String });
const keys = ['search', 'status', ...activityAdvancedKeys];
const filters = reactive(Object.fromEntries(keys.map(key => [key, props.filters?.[key] ?? ''])));
const loading = ref(false);
const error = ref('');
const rows = computed(() => props.activities?.data ?? []);
const count = computed(() => activeFilterCount(filters, activityAdvancedKeys));
function apply() {
    loading.value = true; error.value = '';
    router.get(route('support.activities.index'), filters, { preserveState: true, replace: true,
        onError: () => { error.value = 'Revisa los filtros y el intervalo de fechas.'; },
        onFinish: () => { loading.value = false; },
    });
}
function clear() { keys.forEach(key => { filters[key] = ''; }); apply(); }
</script>
<template>
    <Head title="Actividades de soporte" />
    <AuthenticatedLayout>
        <template #header><div class="flex flex-wrap items-center justify-between gap-3"><div class="min-w-0"><h1 class="text-xl font-semibold text-app">Actividades de soporte</h1><p class="mt-1 text-sm text-soft">Seguimiento de instalaciones, mantenimiento y trabajos en máquinas vending.</p></div><Link v-if="canCreate && !unavailable" :href="route('support.activities.create')" class="btn-primary inline-flex min-h-11 items-center px-4">Nueva actividad</Link></div></template>
        <div class="space-y-5">
            <p v-if="unavailable" class="card p-5 text-sm text-soft" role="status">{{ unavailable }}</p>
            <template v-else>
                <form class="card grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                    <label class="text-sm">Buscar<input v-model="filters.search" maxlength="160" class="mt-1 w-full border-app" placeholder="Folio, actividad, máquina o empleado" /></label>
                    <label class="text-sm">Estado<select v-model="filters.status" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="status in activityStatuses" :key="status" :value="status">{{ activityLabel(status) }}</option></select></label>
                    <AdvancedFilters :count="count">
                        <label class="text-sm">Tipo<select v-model="filters.activity_type" class="mt-1 w-full border-app"><option value="">Todos</option><option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                        <label class="text-sm">Ticket relacionado visible<select v-model="filters.has_ticket" class="mt-1 w-full border-app"><option value="">Todos</option><option value="yes">Sí</option><option value="no">No</option></select></label>
                        <label class="text-sm">Creada desde<input v-model="filters.from" type="date" class="mt-1 w-full border-app" /></label><label class="text-sm">Creada hasta<input v-model="filters.to" type="date" class="mt-1 w-full border-app" /></label>
                        <SupportActivityPicker v-model="filters.vending_machine_id" class="sm:col-span-2" kind="machines" label="Máquina" />
                        <SupportActivityPicker v-model="filters.employee_id" class="sm:col-span-2" kind="employees" label="Empleado" />
                        <p v-if="filters.ticket_uuid" class="col-span-full text-sm text-soft">Consulta limitada al ticket seleccionado. Usa Limpiar para quitar este filtro.</p>
                    </AdvancedFilters>
                    <div class="col-span-full flex flex-wrap gap-2"><button class="btn-primary min-h-11 px-4" :disabled="loading">{{ loading ? 'Buscando…' : 'Aplicar filtros' }}</button><SecondaryButton :disabled="loading" @click="clear">Limpiar</SecondaryButton></div>
                    <p v-if="error" class="col-span-full text-sm text-rose-700 dark:text-rose-300" role="alert">{{ error }}</p>
                </form>
                <section v-if="rows.length" class="card min-w-0 overflow-hidden" aria-label="Listado de actividades"><SupportActivityList :activities="rows" /></section>
                <EmptyState v-else :title="activityEmptyMessage(props.filters)" message="La consulta está limitada a tu acceso de soporte." />
                <p class="text-sm text-soft">{{ activities?.total ?? 0 }} actividades en esta consulta · Horarios de Ciudad de México.</p>
                <RecordPagination v-if="activities?.last_page > 1" :links="activities.links" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
