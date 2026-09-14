<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import SupportActivityPicker from '@/Components/SupportActivityPicker.vue';
import { employeeAccessLabel } from '@/presentation/supportActivities';
import { supportOperationUuid } from '@/presentation/support';
defineProps({ types: Array });
const form = useForm({ client_operation_uuid: '', vending_machine_id: '', employee_id: '', activity_type: '', title: '', description: '', support_ticket_uuid: '' });
const employee = ref(null);
const error = ref('');
const eligibleSearch = computed(() => !!form.vending_machine_id && !!form.activity_type);
let lastFingerprint = '';
watch(() => [form.vending_machine_id, form.activity_type], () => { form.employee_id = ''; employee.value = null; });
watch(() => form.vending_machine_id, () => { form.support_ticket_uuid = ''; });
function submit() {
    error.value = '';
    try {
        const fingerprint = JSON.stringify([form.vending_machine_id, form.employee_id, form.activity_type, form.title, form.description, form.support_ticket_uuid]);
        if (!form.client_operation_uuid || fingerprint !== lastFingerprint) form.client_operation_uuid = supportOperationUuid();
        lastFingerprint = fingerprint;
        form.post(route('support.activities.store'));
    } catch (failure) { error.value = failure.message; }
}
</script>
<template>
    <Head title="Nueva actividad de soporte" />
    <AuthenticatedLayout>
        <template #header><div><Link :href="route('support.activities.index')" class="inline-flex min-h-11 items-center text-sm text-indigo-700 dark:text-indigo-300">← Actividades</Link><h1 class="text-xl font-semibold text-app">Nueva actividad</h1><p class="mt-1 text-sm text-soft">Asigna el trabajo a un empleado con autorización vigente para la máquina.</p></div></template>
        <form class="card mx-auto max-w-4xl space-y-5 p-4 sm:p-6" @submit.prevent="submit">
            <p class="text-sm text-soft">Crear la actividad no solicita ubicación ni inicia el trabajo. La presencia física se validará al iniciar desde el flujo de campo.</p>
            <SupportActivityPicker v-model="form.vending_machine_id" kind="machines" purpose="create" label="Máquina" required :disabled="form.processing" /><InputError :message="form.errors.vending_machine_id" />
            <label class="block text-sm">Tipo de actividad<select v-model="form.activity_type" required :disabled="form.processing" class="mt-1 w-full border-app"><option value="">Selecciona el trabajo</option><option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option></select><InputError :message="form.errors.activity_type" /></label>
            <SupportActivityPicker v-model="form.employee_id" kind="employees" purpose="create" label="Empleado / técnico" :machine-id="form.vending_machine_id" :activity-type="form.activity_type" required :disabled="form.processing || !eligibleSearch" @selected="employee = $event" /><InputError :message="form.errors.employee_id" />
            <p v-if="!eligibleSearch" class="text-xs text-soft">Selecciona primero la máquina y el tipo de actividad.</p><p v-if="employee" class="text-sm text-soft" role="status">{{ employeeAccessLabel(employee) }}</p>
            <p class="text-xs text-soft">Sólo se muestran identidades laborales habilitadas y asignaciones compatibles. No se crean cuentas al asignar trabajo.</p>
            <label class="block text-sm">Título<input v-model="form.title" required maxlength="160" :disabled="form.processing" class="mt-1 w-full border-app" /><InputError :message="form.errors.title" /></label>
            <label class="block text-sm">Descripción <span class="text-soft">(opcional)</span><textarea v-model="form.description" maxlength="4000" rows="4" :disabled="form.processing" class="mt-1 w-full border-app" /><InputError :message="form.errors.description" /></label>
            <details class="rounded-xl border border-app p-3"><summary class="min-h-11 cursor-pointer py-2 text-sm font-semibold">Ticket relacionado (opcional)</summary><SupportActivityPicker v-model="form.support_ticket_uuid" kind="tickets" purpose="create" label="Ticket" :machine-id="form.vending_machine_id" :disabled="form.processing || !form.vending_machine_id" /><InputError :message="form.errors.support_ticket_uuid" /></details>
            <p v-if="error" class="text-sm text-rose-700 dark:text-rose-300" role="alert">{{ error }}</p><InputError :message="form.errors.activity || form.errors.client_operation_uuid" />
            <div class="flex flex-wrap items-center gap-3"><button class="btn-primary min-h-11 px-5" :disabled="form.processing">{{ form.processing ? 'Guardando…' : 'Crear y asignar actividad' }}</button><Link :href="route('support.activities.index')" class="inline-flex min-h-11 items-center px-3 text-sm text-soft">Volver al listado</Link></div>
        </form>
    </AuthenticatedLayout>
</template>
