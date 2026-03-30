<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmployeeAttendanceDrawer from '@/Components/EmployeeAttendanceDrawer.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Modal from '@/Components/Modal.vue';
import Toast from '@/Components/Toast.vue';
import LoadingState from '@/Components/LoadingState.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ErrorState from '@/Components/ErrorState.vue';
import { apiUrl, appUrl } from '@/utils/url';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref } from 'vue';

const employees = ref([]);
const loading = ref(false);
const loadError = ref('');
const syncing = ref(false);
const statusChanges = ref([]);
const filters = reactive({
    status: '',
    search: '',
    fingerprint: '',
    face: '',
    syncReady: false,
    page: 1,
    perPage: 15,
});
const isAttendanceOpen = ref(false);
const selectedEmployee = ref(null);
const attendancePanelKey = ref(0);
const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
    duration: 5000,
});
const syncConfig = reactive({
    mode: 'fortia',
    label: 'Fortia',
    is_mock: false,
});

const faceFormDefaults = () => ({
    face_enabled: false,
    face_status: 'none',
    face_samples_count: 0,
    face_template_version: '',
    face_quality_score: '',
    face_meta_text: '',
});

const faceModal = reactive({
    show: false,
    loading: false,
    error: '',
    employee: null,
    form: faceFormDefaults(),
});

const meta = reactive({
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    total: 0,
    per_page: filters.perPage,
});

const normalizeStatus = (value) => {
    const normalized = String(value ?? '').toUpperCase();
    return normalized === 'A' || normalized === 'ACTIVE' ? 'ACTIVE' : 'INACTIVE';
};

const isActiveStatus = (value) => normalizeStatus(value) === 'ACTIVE';

const normalizeFingerprintStatus = (employee = {}) => {
    const fingerprintStatus = employee.fingerprint_status;

    if (typeof fingerprintStatus === 'string' && fingerprintStatus.length > 0) {
        return fingerprintStatus;
    }

    if (typeof fingerprintStatus === 'boolean') {
        return fingerprintStatus ? 'enrolled' : 'none';
    }

    return employee.has_fingerprint ? 'enrolled' : 'none';
};

const normalizeFaceStatus = (employee = {}) => {
    const status = String(employee.face_status ?? '').trim().toLowerCase();

    if (status) {
        return status;
    }

    if (employee.has_face_enrollment) {
        return employee.face_enabled ? 'enrolled' : 'disabled';
    }

    return 'none';
};

const normalizeEmployee = (employee = {}) => ({
    ...employee,
    status: normalizeStatus(employee.status),
    has_fingerprint: Boolean(employee.has_fingerprint),
    fingerprint_status: normalizeFingerprintStatus(employee),
    has_face_enrollment: Boolean(employee.has_face_enrollment),
    face_status: normalizeFaceStatus(employee),
    face_enabled: Boolean(employee.face_enabled),
    face_samples_count: Number(employee.face_samples_count ?? 0),
    face_quality_score: employee.face_quality_score ?? null,
    face_template_version: employee.face_template_version ?? null,
    face_meta: employee.face_meta && typeof employee.face_meta === 'object' ? employee.face_meta : null,
    face_sync_ready: Boolean(employee.face_sync_ready),
});

const fingerprintStatusLabel = (employee) => {
    if (employee.fingerprint_status === 'enrolled') return 'Con huella';
    if (employee.fingerprint_status === 'pending_delete') return 'Eliminando';

    return 'Sin huella';
};

const fingerprintStatusClasses = (employee) => ({
    'bg-emerald-50 text-emerald-700': employee.fingerprint_status === 'enrolled',
    'bg-amber-50 text-amber-700': employee.fingerprint_status === 'pending_delete',
    'bg-slate-100 text-soft dark:bg-slate-800': employee.fingerprint_status === 'none',
});

const faceStatusLabel = (employee) => {
    switch (employee.face_status) {
        case 'enrolled':
            return employee.face_enabled ? 'Con Face ID' : 'Face deshabilitado';
        case 'ready':
            return 'Face listo';
        case 'review_required':
            return 'Revision requerida';
        case 'pending':
            return 'Face pendiente';
        case 'disabled':
            return 'Face deshabilitado';
        default:
            return 'Sin Face ID';
    }
};

const faceStatusClasses = (employee) => ({
    'bg-sky-50 text-sky-700': employee.face_status === 'enrolled' || employee.face_status === 'ready',
    'bg-amber-50 text-amber-700': employee.face_status === 'pending' || employee.face_status === 'review_required',
    'bg-rose-50 text-rose-700': employee.face_status === 'disabled',
    'bg-slate-100 text-soft dark:bg-slate-800': employee.face_status === 'none',
});

const formatFaceMeta = (faceMeta) => {
    if (!faceMeta || typeof faceMeta !== 'object') {
        return '';
    }

    return JSON.stringify(faceMeta, null, 2);
};

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
const canViewAttendance = computed(() => can('attendance', 'view'));
const canDeleteFingerprints = computed(() => can('biometrics', 'fingerprints.delete'));
const canManageFace = computed(() => can('biometrics', 'face.manage'));

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
const syncButtonLabel = computed(() =>
    syncConfig.label ? `Sincronizar con ${syncConfig.label}` : 'Sincronizar empleados',
);
const syncModeCaption = computed(() =>
    syncConfig.is_mock
        ? 'Modo local con fuente mock.'
        : `Fuente configurada: ${syncConfig.label}.`,
);

const setMeta = (payload) => {
    if (!payload) {
        meta.current_page = 1;
        meta.last_page = 1;
        meta.from = 0;
        meta.to = 0;
        meta.total = employees.value.length;
        meta.per_page = filters.perPage;
        return;
    }

    const currentPage = payload.current_page ?? payload.page ?? 1;
    const perPage = payload.per_page ?? filters.perPage;
    const total = payload.total ?? 0;

    meta.current_page = currentPage;
    meta.last_page = payload.last_page ?? 1;
    meta.total = total;
    meta.per_page = perPage;
    meta.from = payload.from ?? (total > 0 ? (currentPage - 1) * perPage + 1 : 0);
    meta.to = payload.to ?? (total > 0 ? Math.min(currentPage * perPage, total) : 0);
};

const loadEmployees = async (pageNumber = filters.page) => {
    loading.value = true;
    loadError.value = '';
    filters.page = pageNumber;

    try {
        const { data } = await axios.get(apiUrl('/api/admin/employees'), {
            params: {
                status: filters.status || undefined,
                q: filters.search || undefined,
                fingerprint: filters.fingerprint || undefined,
                face: filters.face || undefined,
                sync_ready: filters.syncReady ? 1 : undefined,
                page: filters.page,
                per_page: filters.perPage,
            },
        });

        employees.value = (data.data ?? []).map(normalizeEmployee);
        setMeta(data.meta);
        if (data.sync && typeof data.sync === 'object') {
            Object.assign(syncConfig, {
                mode: data.sync.mode ?? syncConfig.mode,
                label: data.sync.label ?? syncConfig.label,
                is_mock: Boolean(data.sync.is_mock),
            });
        }
    } catch (error) {
        loadError.value = error?.response?.data?.message ?? 'Intenta nuevamente.';
        showToast({
            type: 'error',
            title: 'No se pudo cargar el catalogo',
            message: loadError.value,
        });
    } finally {
        loading.value = false;
    }
};

const syncNow = async () => {
    if (!canSyncEmployees.value) return;

    syncing.value = true;
    statusChanges.value = [];

    try {
        const { data } = await axios.post(apiUrl('/api/employees/sync-fortia-mock'));
        statusChanges.value = data.status_changed || [];
        if (data.sync && typeof data.sync === 'object') {
            Object.assign(syncConfig, {
                mode: data.sync.mode ?? syncConfig.mode,
                label: data.sync.label ?? syncConfig.label,
                is_mock: Boolean(data.sync.is_mock),
            });
        }
        await loadEmployees(filters.page);
        showToast({
            type: 'success',
            title: 'Sincronizacion lista',
            message: `${syncConfig.label}: Nuevos ${data.created_count}, Actualizados ${data.updated_count}, Sin cambios ${data.unchanged_count}, Cambios de estatus ${data.status_changed_count}`,
        });
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Sincronizacion fallida',
            message: error?.response?.data?.message || 'No se pudo sincronizar.',
        });
    } finally {
        syncing.value = false;
    }
};

const updateEmployeeInList = (updatedData) => {
    const normalized = normalizeEmployee(updatedData);
    const index = employees.value.findIndex((item) => item.id === normalized.id);
    if (index !== -1) {
        employees.value[index] = {
            ...employees.value[index],
            ...normalized,
        };
    }
};

const resetModal = () => {
    modalState.value = { ...modalDefaults };
};

const closeFaceModal = () => {
    faceModal.show = false;
    faceModal.loading = false;
    faceModal.error = '';
    faceModal.employee = null;
    Object.assign(faceModal.form, faceFormDefaults());
};

const openStatusModal = (employee) => {
    const nextStatus = isActiveStatus(employee.status) ? 'inactive' : 'active';
    modalState.value = {
        ...modalDefaults,
        show: true,
        title: nextStatus === 'inactive' ? 'Desactivar empleado' : 'Activar empleado',
        message: `Seguro que deseas ${nextStatus === 'inactive' ? 'desactivar' : 'activar'} a ${employee.full_name ?? employee.name}?`,
        confirmLabel: nextStatus === 'inactive' ? 'Desactivar' : 'Activar',
        action: 'status',
        context: { employee, nextStatus },
    };
};

const openFingerprintModal = (employee) => {
    modalState.value = {
        ...modalDefaults,
        show: true,
        title: 'Eliminar huellas del empleado',
        message: `Eliminar huellas del empleado ${employee.full_name ?? employee.name}? Esta accion no se puede deshacer.`,
        confirmLabel: 'Eliminar huellas',
        action: 'fingerprint',
        context: { employee },
    };
};

const openFaceDeleteModal = (employee) => {
    modalState.value = {
        ...modalDefaults,
        show: true,
        title: 'Eliminar Face ID del empleado',
        message: `Eliminar Face ID y sus plantillas de ${employee.full_name ?? employee.name}? Esta accion no se puede deshacer.`,
        confirmLabel: 'Eliminar Face ID',
        action: 'face-delete',
        context: { employee },
    };
};

const openFaceModal = (employee) => {
    faceModal.show = true;
    faceModal.error = '';
    faceModal.employee = employee;
    Object.assign(faceModal.form, {
        face_enabled: Boolean(employee.face_enabled),
        face_status: employee.face_status || 'none',
        face_samples_count: Number(employee.face_samples_count ?? 0),
        face_template_version: employee.face_template_version ?? '',
        face_quality_score: employee.face_quality_score ?? '',
        face_meta_text: formatFaceMeta(employee.face_meta),
    });
};

const parseFaceMeta = () => {
    const raw = String(faceModal.form.face_meta_text ?? '').trim();

    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        throw new Error('El metadata facial debe ser JSON valido.');
    }
};

const saveFaceProfile = async () => {
    if (!faceModal.employee) {
        return;
    }

    faceModal.loading = true;
    faceModal.error = '';

    try {
        const payload = {
            face_enabled: Boolean(faceModal.form.face_enabled),
            face_status: faceModal.form.face_status,
            face_samples_count: Number(faceModal.form.face_samples_count ?? 0),
            face_template_version: faceModal.form.face_template_version || null,
            face_quality_score:
                faceModal.form.face_quality_score === '' || faceModal.form.face_quality_score === null
                    ? null
                    : Number(faceModal.form.face_quality_score),
            face_meta: parseFaceMeta(),
        };

        const { data } = await axios.patch(toAppUrl(`/api/admin/employees/${faceModal.employee.id}/face-profile`), payload);
        updateEmployeeInList(data.employee ?? {});
        showToast({
            type: 'success',
            title: 'Face ID actualizado',
            message: `Se actualizo el perfil facial de ${faceModal.employee.full_name ?? faceModal.employee.name}.`,
        });
        closeFaceModal();
    } catch (error) {
        faceModal.error = error?.message || error?.response?.data?.message || 'No se pudo actualizar Face ID.';
        showToast({
            type: 'error',
            title: 'No se pudo actualizar Face ID',
            message: error?.response?.data?.message || faceModal.error,
        });
    } finally {
        faceModal.loading = false;
    }
};

const executeModalAction = async () => {
    const { action, context } = modalState.value;
    if (!action || !context) {
        return;
    }

    modalState.value.loading = true;

    try {
        if (action === 'status') {
<<<<<<< HEAD
            const { data } = await axios.patch(apiUrl(`/api/employees/${context.employee.id}/status`), {
=======
            const { data } = await axios.patch(toAppUrl(`/api/employees/${context.employee.id}/status`), {
>>>>>>> dev
                status: context.nextStatus,
            });
            updateEmployeeInList(data);
            showToast({
                type: 'success',
                title: 'Empleado actualizado',
                message: `Estado de ${data.full_name ?? data.name} actualizado correctamente.`,
            });
        } else if (action === 'fingerprint') {
            await axios.get(appUrl('/sanctum/csrf-cookie'));
            const { data } = await axios.delete(apiUrl(`/api/admin/employees/${context.employee.id}/fingerprints`));
            updateEmployeeInList({
                ...context.employee,
                has_fingerprint: false,
                fingerprint_status: 'none',
            });
            showToast({
                type: 'success',
                title: 'Huellas eliminadas',
                message: `Se eliminaron ${data.deleted_count ?? 0} huella(s).`,
            });
        } else if (action === 'face-delete') {
            await axios.get(toAppUrl('/sanctum/csrf-cookie'));
            const { data } = await axios.delete(toAppUrl(`/api/admin/employees/${context.employee.id}/face-profile`));
            updateEmployeeInList(data.employee ?? {});
            closeFaceModal();
            showToast({
                type: 'success',
                title: 'Face ID eliminado',
                message: `Se eliminaron ${data.deleted_count ?? 0} plantilla(s) faciales.`,
            });
        }

        resetModal();
    } catch (error) {
        resetModal();
        showToast({
            type: 'error',
            title: 'Accion no completada',
            message: error?.response?.data?.message || 'No se pudo completar la accion.',
        });
    } finally {
        modalState.value.loading = false;
    }
};

const handlePageChange = (pageNumber) => {
    if (loading.value) return;
    const totalPages = meta.last_page || 1;
    const target = Math.min(Math.max(pageNumber, 1), totalPages);
    loadEmployees(target);
};

const handlePerPageChange = (perPage) => {
    if (filters.perPage === perPage) return;
    filters.perPage = perPage;
    loadEmployees(1);
};

const openAttendance = (employee) => {
    if (!canViewAttendance.value) return;
    if (!selectedEmployee.value || selectedEmployee.value.id !== employee.id) {
        selectedEmployee.value = employee;
        attendancePanelKey.value += 1;
    }
    isAttendanceOpen.value = true;
};

const closeAttendance = () => {
    isAttendanceOpen.value = false;
    selectedEmployee.value = null;
};

onMounted(loadEmployees);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Catalogo de empleados" />

        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Catalogo de trabajadores
                </h1>
                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Recursos humanos</p>
            </div>
        </template>

        <section class="space-y-4">
            <div class="flex flex-wrap items-stretch justify-between gap-4 sm:items-center">
                <div>
                    <p class="text-sm text-muted">Control de estados, huellas y Face ID biometricos.</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        {{ syncModeCaption }}
                    </p>
                </div>
                <button
                    v-if="canSyncEmployees"
                    class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                    :disabled="syncing"
                    @click="syncNow"
                >
                    <span v-if="syncing">Sincronizando...</span>
                    <span v-else>{{ syncButtonLabel }}</span>
                </button>
            </div>

            <div class="card flex flex-wrap gap-3 px-4 py-3 text-sm">
                <label class="flex w-full flex-col sm:w-auto sm:min-w-[16rem]">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Buscar</span>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Nombre o codigo..."
                        class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @keyup.enter="loadEmployees(1)"
                    />
                </label>
                <label class="flex w-full flex-col sm:w-auto">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Estado</span>
                    <select v-model="filters.status" class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto" @change="loadEmployees(1)">
                        <option value="">Todos</option>
                        <option value="active">Activos</option>
                        <option value="inactive">Baja</option>
                    </select>
                </label>
                <label class="flex w-full flex-col sm:w-auto">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Huella</span>
                    <select v-model="filters.fingerprint" class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto" @change="loadEmployees(1)">
                        <option value="">Todas</option>
                        <option value="with">Con huella</option>
                        <option value="without">Sin huella</option>
                    </select>
                </label>
                <label class="flex w-full flex-col sm:w-auto">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Face ID</span>
                    <select v-model="filters.face" class="w-full rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900 sm:w-auto" @change="loadEmployees(1)">
                        <option value="">Todos</option>
                        <option value="with">Con Face ID</option>
                        <option value="without">Sin Face ID</option>
                    </select>
                </label>
                <label class="flex items-end gap-2 rounded-2xl border border-app px-4 py-2 text-sm">
                    <input v-model="filters.syncReady" type="checkbox" class="rounded border-app text-indigo-600 focus:ring-indigo-500" @change="loadEmployees(1)" />
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Listo para sync</span>
                </label>
                <button
                    class="w-full self-end rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted hover:text-app sm:w-auto"
                    @click="loadEmployees(1)"
                >
                    Aplicar
                </button>
            </div>

            <div v-if="statusChanges.length" class="rounded-2xl border border-app bg-white px-4 py-3 text-sm shadow-sm dark:bg-slate-900">
                <p class="font-semibold text-muted">Cambios de estatus recientes:</p>
                <ul class="mt-2 space-y-1 text-sm text-app">
                    <li v-for="item in statusChanges.slice(0, 5)" :key="`${item.company_id}-${item.fortia_employee_id}-${item.changed_at}`">
                        <span class="font-semibold">{{ item.full_name }}</span> ({{ item.fortia_employee_id }}) - {{ item.old_status }} -> {{ item.new_status }}
                    </li>
                    <li v-if="statusChanges.length > 5" class="text-xs text-muted">
                        ...y {{ statusChanges.length - 5 }} mas
                    </li>
                </ul>
            </div>

            <PaginationBar
                v-if="meta.total > 0"
                :meta="meta"
                :disabled="loading"
                class="card"
                @update:page="handlePageChange"
                @update:perPage="handlePerPageChange"
            />

            <ErrorState
                v-if="loadError && !loading"
                title="No se pudo cargar el catalogo"
                :message="loadError"
                @retry="loadEmployees(filters.page)"
            />

            <LoadingState v-else-if="loading" title="Cargando catalogo de empleados..." :rows="5" />

            <EmptyState
                v-else-if="!employees.length"
                title="Sin empleados"
                message="No hay empleados para los filtros seleccionados."
            />

            <div v-else class="card overflow-hidden">
                <div class="space-y-3 p-3 sm:hidden">
                    <article
                        v-for="employee in employees"
                        :key="`mobile-${employee.id}`"
                        class="rounded-2xl border border-app bg-white p-3 shadow-sm"
                    >
                        <p class="truncate text-sm font-semibold text-app" :title="employee.full_name ?? employee.name">
                            {{ employee.full_name ?? employee.name }}
                        </p>
                        <p class="mt-1 text-xs text-muted">{{ employee.unit_name ?? 'Sin unidad' }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold"
                                :class="isActiveStatus(employee.status) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                            >
                                {{ isActiveStatus(employee.status) ? 'Activo' : 'Baja' }}
                            </span>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="fingerprintStatusClasses(employee)">
                                {{ fingerprintStatusLabel(employee) }}
                            </span>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="faceStatusClasses(employee)">
                                {{ faceStatusLabel(employee) }}
                            </span>
                            <span v-if="employee.face_sync_ready" class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                                Listo sync
                            </span>
                        </div>
                        <div class="mt-3 grid gap-1 text-xs text-muted">
                            <span>Muestras Face: {{ employee.face_samples_count }}</span>
                            <span v-if="employee.face_quality_score !== null">Score Face: {{ employee.face_quality_score }}</span>
                            <span v-if="employee.face_template_version">Version: {{ employee.face_template_version }}</span>
                        </div>
                        <div class="mt-3 flex flex-col gap-2 text-xs font-semibold">
                            <button
                                v-if="canViewAttendance"
                                class="w-full rounded-2xl border border-app px-3 py-2"
                                @click="openAttendance(employee)"
                            >
                                Ver asistencias
                            </button>
                            <button
                                v-if="canManageFace"
                                class="w-full rounded-2xl border border-app px-3 py-2 text-sky-700"
                                @click="openFaceModal(employee)"
                            >
                                Administrar Face ID
                            </button>
                            <button
                                v-if="canDeleteFingerprints"
                                class="w-full rounded-2xl border border-app px-3 py-2 text-rose-600"
                                @click="openFingerprintModal(employee)"
                            >
                                Borrar huella
                            </button>
                        </div>
                    </article>
                </div>

                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[78rem] divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                            <tr>
                                <th class="px-4 py-3">Nombre</th>
                                <th class="px-4 py-3">Unidad</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3">Huella</th>
                                <th class="px-4 py-3">Face ID</th>
                                <th class="px-4 py-3">Sync Face</th>
                                <th class="px-4 py-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in employees" :key="employee.id" class="hover:bg-slate-50 dark:hover:bg-slate-900/40">
                                <td class="px-4 py-3 font-semibold text-app">
                                    <span class="block max-w-[16rem] truncate" :title="employee.full_name ?? employee.name">
                                        {{ employee.full_name ?? employee.name }}
                                    </span>
                                    <span class="mt-1 block text-xs font-normal text-muted">
                                        Muestras Face: {{ employee.face_samples_count }}
                                        <template v-if="employee.face_quality_score !== null"> | Score: {{ employee.face_quality_score }}</template>
                                        <template v-if="employee.face_template_version"> | {{ employee.face_template_version }}</template>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-muted">{{ employee.unit_name ?? 'Sin unidad' }}</td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="canDisableEmployees"
                                        class="rounded-full px-3 py-2 text-xs font-semibold"
                                        :class="isActiveStatus(employee.status) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                        @click="openStatusModal(employee)"
                                    >
                                        {{ isActiveStatus(employee.status) ? 'Activo' : 'Baja' }}
                                    </button>
                                    <span
                                        v-else
                                        class="rounded-full bg-slate-100 px-3 py-2 text-xs font-semibold text-soft dark:bg-slate-800 dark:text-slate-300"
                                    >
                                        {{ isActiveStatus(employee.status) ? 'Activo' : 'Baja' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-3 py-2 text-xs font-semibold" :class="fingerprintStatusClasses(employee)">
                                        {{ fingerprintStatusLabel(employee) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-2">
                                        <span class="inline-flex rounded-full px-3 py-2 text-xs font-semibold" :class="faceStatusClasses(employee)">
                                            {{ faceStatusLabel(employee) }}
                                        </span>
                                        <span class="text-xs text-muted">
                                            {{ employee.face_enabled ? 'Habilitado' : 'Deshabilitado' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-3 py-2 text-xs font-semibold"
                                        :class="employee.face_sync_ready ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                                    >
                                        {{ employee.face_sync_ready ? 'Listo' : 'Pendiente' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-2 text-xs font-semibold sm:flex-row sm:flex-wrap">
                                        <button
                                            v-if="canViewAttendance"
                                            class="w-full rounded-2xl border border-app px-3 py-2 sm:w-auto"
                                            @click="openAttendance(employee)"
                                        >
                                            Ver asistencias
                                        </button>
                                        <button
                                            v-if="canManageFace"
                                            class="w-full rounded-2xl border border-app px-3 py-2 text-sky-700 sm:w-auto"
                                            @click="openFaceModal(employee)"
                                        >
                                            Administrar Face ID
                                        </button>
                                        <button
                                            v-if="canDeleteFingerprints"
                                            class="w-full rounded-2xl border border-app px-3 py-2 text-rose-600 sm:w-auto"
                                            @click="openFingerprintModal(employee)"
                                        >
                                            Borrar huella
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <EmployeeAttendanceDrawer
                v-if="canViewAttendance"
                :key="attendancePanelKey"
                :open="isAttendanceOpen"
                :employee="selectedEmployee"
                @close="closeAttendance"
            />

            <Modal :show="faceModal.show" max-width="2xl" @close="closeFaceModal">
                <div class="p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-app">Administrar Face ID</h2>
                            <p class="mt-1 text-sm text-muted">
                                {{ faceModal.employee?.full_name ?? faceModal.employee?.name }}
                            </p>
                        </div>
                        <span
                            v-if="faceModal.employee"
                            class="rounded-full px-3 py-2 text-xs font-semibold"
                            :class="faceModal.employee?.face_sync_ready ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                        >
                            {{ faceModal.employee?.face_sync_ready ? 'Listo para sync' : 'No listo para sync' }}
                        </span>
                    </div>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Estatus facial</span>
                            <select v-model="faceModal.form.face_status" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900">
                                <option value="none">Sin Face ID</option>
                                <option value="pending">Pendiente</option>
                                <option value="enrolled">Enrolado</option>
                                <option value="ready">Listo</option>
                                <option value="review_required">Revision requerida</option>
                                <option value="disabled">Deshabilitado</option>
                            </select>
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Face habilitado</span>
                            <select v-model="faceModal.form.face_enabled" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900">
                                <option :value="true">Si</option>
                                <option :value="false">No</option>
                            </select>
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Muestras</span>
                            <input v-model.number="faceModal.form.face_samples_count" type="number" min="0" max="99" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900" />
                        </label>

                        <label class="flex flex-col gap-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Score de calidad</span>
                            <input v-model="faceModal.form.face_quality_score" type="number" min="0" max="100" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900" />
                        </label>

                        <label class="flex flex-col gap-2 sm:col-span-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Version de template</span>
                            <input v-model="faceModal.form.face_template_version" type="text" maxlength="80" class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900" placeholder="FACE_EMBEDDING_V1" />
                        </label>

                        <label class="flex flex-col gap-2 sm:col-span-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Metadata facial</span>
                            <textarea
                                v-model="faceModal.form.face_meta_text"
                                rows="7"
                                class="rounded-2xl border border-app bg-white px-4 py-3 font-mono text-xs dark:bg-slate-900"
                                placeholder='{"capture_source":"enroller","notes":"calidad estable"}'
                            />
                        </label>
                    </div>

                    <p v-if="faceModal.error" class="mt-4 text-sm font-semibold text-rose-600">
                        {{ faceModal.error }}
                    </p>

                    <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                        <button
                            class="w-full rounded-2xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                            :disabled="faceModal.loading || !faceModal.employee?.has_face_enrollment"
                            @click="openFaceDeleteModal(faceModal.employee)"
                        >
                            Eliminar Face ID
                        </button>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <button
                                class="w-full rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-muted hover:text-app sm:w-auto"
                                :disabled="faceModal.loading"
                                @click="closeFaceModal"
                            >
                                Cancelar
                            </button>
                            <button
                                class="w-full rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                :disabled="faceModal.loading"
                                @click="saveFaceProfile"
                            >
                                <span v-if="faceModal.loading">Guardando...</span>
                                <span v-else>Guardar Face ID</span>
                            </button>
                        </div>
                    </div>
                </div>
            </Modal>

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
