<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmployeeAttendance from '@/Components/EmployeeAttendance.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Toast from '@/Components/Toast.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref } from 'vue';

const employees = ref([]);
const loading = ref(false);
const syncing = ref(false);
const statusChanges = ref([]);
const filters = ref({
    status: '',
    search: '',
});
const activeEmployeeId = ref(null);
const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
    duration: 5000,
});

const showToast = ({ type = 'success', title = '', message = '', duration }) => {
    toast.show = false;
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration ?? (type === 'error' ? 9000 : 5000);
    nextTick(() => {
        toast.show = true;
    });
};

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};
const canSyncEmployees = computed(() => can('employees', 'sync'));
const canDisableEmployees = computed(() => can('employees', 'disable'));
const canUpdateEmployees = computed(() => can('employees', 'update'));
const canViewAttendance = computed(() => can('attendance', 'view'));

const modalDefaults = {
    show: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
    loading: false,
    action: null,
    context: null,
};

const modalState = ref({ ...modalDefaults });

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
    if (!canSyncEmployees.value) return;
    syncing.value = true;
    statusChanges.value = [];
    try {
        const { data } = await axios.post('/api/employees/sync-fortia-mock');
        statusChanges.value = data.status_changed || [];
        await loadEmployees();
        showToast({
            type: 'success',
            title: 'Sincronización lista',
            message: `Nuevos: ${data.created_count}, Actualizados: ${data.updated_count}, Sin cambios: ${data.unchanged_count}, Cambios de estatus: ${data.status_changed_count}`,
        });
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Sincronización fallida',
            message: error?.response?.data?.message || 'No se pudo sincronizar.',
        });
    } finally {
        syncing.value = false;
    }
};

const updateEmployeeInList = (updatedData) => {
    const index = employees.value.findIndex((item) => item.id === updatedData.id);
    if (index !== -1) {
        employees.value[index] = { ...employees.value[index], ...updatedData };
    }
};

const resetModal = () => {
    modalState.value = { ...modalDefaults };
};

const openStatusModal = (employee) => {
    const nextStatus = employee.status === 'A' ? 'inactive' : 'active';
    modalState.value = {
        ...modalDefaults,
        show: true,
        title: nextStatus === 'inactive' ? 'Desactivar empleado' : 'Activar empleado',
        message: `¿Seguro que deseas ${nextStatus === 'inactive' ? 'desactivar' : 'activar'} a ${
            employee.full_name ?? employee.name
        }?`,
        confirmLabel: nextStatus === 'inactive' ? 'Desactivar' : 'Activar',
        action: 'status',
        context: { employee, nextStatus },
    };
};

const openFingerprintModal = (employee) => {
    modalState.value = {
        ...modalDefaults,
        show: true,
        title: 'Borrar huella',
        message:
            'Esto marcará la huella como pendiente de eliminación y notificará al sistema on-premise cuando esté disponible.',
        confirmLabel: 'Borrar huella',
        action: 'fingerprint',
        context: { employee },
    };
};

const executeModalAction = async () => {
    const { action, context } = modalState.value;
    if (!action || !context) {
        return;
    }

    modalState.value.loading = true;

    try {
        if (action === 'status') {
            const { data } = await axios.patch(`/api/employees/${context.employee.id}/status`, {
                status: context.nextStatus,
            });
            updateEmployeeInList(data);
            showToast({
                type: 'success',
                title: 'Empleado actualizado',
                message: `Estado de ${data.full_name ?? data.name} actualizado correctamente.`,
            });
        } else if (action === 'fingerprint') {
            const { data } = await axios.delete(`/api/employees/${context.employee.id}/fingerprints`);
            updateEmployeeInList({
                id: context.employee.id,
                has_fingerprint: data.has_fingerprint,
                fingerprint_status: data.fingerprint_status,
            });
            showToast({
                type: 'success',
                title: 'Huella actualizada',
                message: data.message,
            });
        }
        resetModal();
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Acción no completada',
            message: error?.response?.data?.message || 'No se pudo completar la acción.',
        });
    } finally {
        modalState.value.loading = false;
    }
};

const toggleAttendance = (employeeId) => {
    if (!canViewAttendance.value) return;
    activeEmployeeId.value = activeEmployeeId.value === employeeId ? null : employeeId;
};

onMounted(loadEmployees);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Catalogo de empleados" />

        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Catálogo de trabajadores
                </h1>
                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Recursos humanos</p>
            </div>
        </template>

        <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-muted">Control de estados y huellas biometricas.</p>
            </div>
            <button
                v-if="canSyncEmployees"
                class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="syncing"
                @click="syncNow"
            >
                <span v-if="syncing">Sincronizando...</span>
                <span v-else>Sincronizar con Sistema de Nomina</span>
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

        <div v-if="statusChanges.length" class="rounded-2xl border border-app bg-white px-4 py-3 text-sm shadow-sm dark:bg-slate-900">
            <p class="text-muted font-semibold">Cambios de estatus recientes:</p>
            <ul class="mt-2 space-y-1 text-sm text-app">
                <li v-for="item in statusChanges.slice(0, 5)" :key="`${item.company_id}-${item.fortia_employee_id}-${item.changed_at}`">
                    <span class="font-semibold">{{ item.full_name }}</span> ({{ item.fortia_employee_id }}) - {{ item.old_status }} → {{ item.new_status }}
                </li>
                <li v-if="statusChanges.length > 5" class="text-muted text-xs">
                    ...y {{ statusChanges.length - 5 }} más
                </li>
            </ul>
        </div>

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
                                v-if="canDisableEmployees"
                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                :class="employee.status === 'A' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                @click="openStatusModal(employee)"
                            >
                                {{ employee.status === 'A' ? 'Activo' : 'Baja' }}
                            </button>
                            <span
                                v-else
                                class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-soft dark:bg-slate-800 dark:text-slate-300"
                            >
                                {{ employee.status === 'A' ? 'Activo' : 'Baja' }}
                            </span>
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
                                <button
                                    v-if="canViewAttendance"
                                    class="rounded-2xl border border-app px-3 py-1"
                                    @click="toggleAttendance(employee.id)"
                                >
                                    {{ activeEmployeeId === employee.id ? 'Ocultar asistencias' : 'Ver asistencias' }}
                                </button>
                                <button
                                    v-if="canUpdateEmployees"
                                    class="rounded-2xl border border-app px-3 py-1 text-rose-600"
                                    @click="openFingerprintModal(employee)"
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

        <EmployeeAttendance v-if="activeEmployeeId && canViewAttendance" :employee-id="activeEmployeeId" />
        <ConfirmModal
            :show="modalState.show"
            :title="modalState.title"
            :message="modalState.message"
            :confirm-label="modalState.confirmLabel"
            :cancel-label="modalState.cancelLabel"
            :loading="modalState.loading"
            @cancel="resetModal"
            @confirm="executeModalAction"
        />
        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="toast.show = false"
        />
    </section>
    </AuthenticatedLayout>
</template>
