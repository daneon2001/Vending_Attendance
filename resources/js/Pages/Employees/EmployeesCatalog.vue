<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmployeeAttendance from '@/Components/EmployeeAttendance.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';

const employees = ref([]);
const loading = ref(false);
const syncMessage = ref('');
const filters = ref({
    status: '',
    search: '',
});
const activeEmployeeId = ref(null);
const message = ref('');

const loadEmployees = async () => {
    loading.value = true;
    const { data } = await axios.get('/api/employees', {
        params: {
            status: filters.value.status || undefined,
            search: filters.value.search || undefined,
        },
    });
    employees.value = data.data ?? [];
    loading.value = false;
};

const syncNow = async () => {
    syncMessage.value = '';
    const { data } = await axios.post('/api/employees/sync-fortia');
    syncMessage.value = data.message;
    await loadEmployees();
};

const toggleStatus = async (employee) => {
    const nextStatus = employee.status === 'A' ? 'inactive' : 'active';
    try {
        const { data } = await axios.patch(`/api/employees/${employee.id}/status`, {
            status: nextStatus,
        });
        const index = employees.value.findIndex((item) => item.id === employee.id);
        if (index !== -1) {
            employees.value[index] = data;
        }
    } catch (error) {
        message.value = 'No se pudo actualizar el estado del empleado.';
    }
};

const deleteFingerprint = async (employee) => {
    if (!confirm('Â¿Seguro que deseas borrar la huella de este empleado en todos los relojes?')) {
        return;
    }

    try {
        const { data } = await axios.delete(`/api/employees/${employee.id}/fingerprints`);

        const index = employees.value.findIndex((item) => item.id === employee.id);
        if (index !== -1) {
            employees.value[index].has_fingerprint = data.has_fingerprint;
            employees.value[index].fingerprint_status = data.fingerprint_status;
        }
        message.value = data.message;
    } catch (error) {
        message.value = 'No se pudo iniciar el borrado de huellas.';
    }
};

const showAttendance = (employeeId) => {
    activeEmployeeId.value = employeeId;
};

onMounted(loadEmployees);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Catalogo de empleados" />

        <template #header>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Recursos humanos</p>
                <h1 class="text-app text-2xl font-semibold">Catalogo de trabajadores</h1>
            </div>
        </template>

        <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-muted">Control de estados y huellas biometricas.</p>
            </div>
            <button
                class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                @click="syncNow"
            >
                Sincronizar con Fortia
            </button>
        </div>

        <div class="card flex flex-wrap gap-3 px-4 py-3 text-sm">
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Buscar</span>
                <input
                    v-model="filters.search"
                    type="text"
                    placeholder="Nombre, RFC, IMSS..."
                    class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                    @keyup.enter="loadEmployees"
                />
            </label>
            <label class="flex flex-col">
                <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Estado</span>
                <select v-model="filters.status" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900" @change="loadEmployees">
                    <option value="">Todos</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Baja</option>
                </select>
            </label>
            <button
                class="self-end rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted hover:text-app"
                @click="loadEmployees"
            >
                Aplicar
            </button>
        </div>

        <p v-if="syncMessage" class="rounded-2xl bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">
            {{ syncMessage }}
        </p>
        <p v-if="message" class="rounded-2xl bg-slate-50 px-4 py-2 text-sm text-app dark:bg-slate-900/40">
            {{ message }}
        </p>

        <div class="card overflow-hidden">
            <table class="w-full divide-y divide-slate-100 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                    <tr>
                        <th class="px-4 py-3">Nombre</th>
                        <th class="px-4 py-3">Empresa</th>
                        <th class="px-4 py-3">Estado</th>
                        <th class="px-4 py-3">Estado huella</th>
                        <th class="px-4 py-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="5" class="px-4 py-4 text-center text-muted">Cargando...</td>
                    </tr>
                    <tr v-for="employee in employees" :key="employee.id" class="hover:bg-slate-50 dark:hover:bg-slate-900/40">
                        <td class="px-4 py-3 font-semibold text-app">{{ employee.full_name ?? employee.name }}</td>
                        <td class="px-4 py-3 text-muted">{{ employee.company_name }}</td>
                        <td class="px-4 py-3">
                            <button
                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                :class="employee.status === 'A' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                @click="toggleStatus(employee)"
                            >
                                {{ employee.status === 'A' ? 'Activo' : 'Baja' }}
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                :class="{
                                    'bg-emerald-50 text-emerald-700': employee.fingerprint_status === 'enrolled',
                                    'bg-amber-50 text-amber-700': employee.fingerprint_status === 'pending_delete',
                                    'bg-slate-100 text-soft dark:bg-slate-800': employee.fingerprint_status === 'none',
                                }"
                            >
                                {{
                                    employee.fingerprint_status === 'enrolled'
                                        ? 'Con huella'
                                        : employee.fingerprint_status === 'pending_delete'
                                          ? 'Eliminando'
                                          : 'Sin huella'
                                }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                <button class="rounded-2xl border border-app px-3 py-1" @click="showAttendance(employee.id)">
                                    Ver asistencias
                                </button>
                                <button
                                    class="rounded-2xl border border-app px-3 py-1 text-rose-600"
                                    @click="deleteFingerprint(employee)"
                                >
                                    Borrar huella
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!loading && !employees.length">
                        <td colspan="5" class="px-4 py-4 text-center text-muted">Sin empleados aun.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmployeeAttendance v-if="activeEmployeeId" :employee-id="activeEmployeeId" />
    </section>
    </AuthenticatedLayout>
</template>
