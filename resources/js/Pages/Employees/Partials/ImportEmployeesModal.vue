<script setup>
import Modal from '@/Components/Modal.vue';
import { apiUrl } from '@/utils/url';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'imported']);

const selectedFile = ref(null);
const preview = ref(null);
const validating = ref(false);
const creatingCatalogs = ref(false);
const importing = ref(false);
const requestError = ref('');
const requestNotice = ref('');
const requestNoticeType = ref('success');
const selectedDetailFilter = ref('all_rows');

const summary = computed(() => preview.value?.summary ?? {});
const catalogSummary = computed(() => preview.value?.catalogs ?? {});
const rows = computed(() => preview.value?.rows ?? []);

const busy = computed(() => validating.value || creatingCatalogs.value || importing.value);
const canImport = computed(() =>
    Boolean(selectedFile.value)
    && Boolean(preview.value?.can_import)
    && !busy.value,
);
const canCreateMissingCatalogs = computed(() =>
    Boolean(selectedFile.value)
    && Number(catalogSummary.value?.creatable_total ?? 0) > 0
    && !busy.value,
);

const generalCards = computed(() => [
    {
        key: 'all_rows',
        label: 'Filas',
        value: Number(summary.value?.total_rows ?? 0),
        tone: 'default',
    },
    {
        key: 'new_rows',
        label: 'Nuevos',
        value: Number(summary.value?.new_records ?? summary.value?.create_count ?? 0),
        tone: 'success',
    },
    {
        key: 'update_rows',
        label: 'Actualizar',
        value: Number(summary.value?.update_records ?? summary.value?.update_count ?? 0),
        tone: 'info',
    },
    {
        key: 'error_rows',
        label: 'Errores',
        value: Number(summary.value?.error_records ?? summary.value?.error_count ?? 0),
        tone: 'danger',
    },
    {
        key: 'missing_catalogs',
        label: 'Catalogos faltantes',
        value: Number(catalogSummary.value?.missing_total ?? 0),
        tone: 'warning',
    },
]);

const catalogCards = computed(() => {
    const preferredOrder = [
        'locations',
        'departamentos',
        'centros_costo',
        'puestos',
        'companies',
        'areas',
        'razones_sociales',
        'registros_imss',
        'periodos_pago',
        'ubicaciones_laborales',
    ];
    const cardMap = new Map(
        (catalogSummary.value?.by_type ?? []).map((item) => [
            item.type,
            {
                key: `catalog:${item.type}`,
                type: item.type,
                label: resolveCatalogCardLabel(item.type, item.label),
                value: Number(item.missing_count ?? 0),
                affectedRows: Number(item.affected_rows ?? 0),
                tone: 'warning',
            },
        ]),
    );

    const ordered = preferredOrder
        .map((type) => cardMap.get(type))
        .filter(Boolean);

    const remaining = [...cardMap.values()].filter((card) => !preferredOrder.includes(card.type));

    return [...ordered, ...remaining].filter((card) => card.value > 0);
});

const allCards = computed(() => [...generalCards.value, ...catalogCards.value]);
const activeCard = computed(() => allCards.value.find((card) => card.key === selectedDetailFilter.value) ?? generalCards.value[0]);
const detailMode = computed(() => selectedDetailFilter.value === 'missing_catalogs' || selectedDetailFilter.value.startsWith('catalog:') ? 'catalogs' : 'employees');

const filteredEmployeeRows = computed(() => {
    switch (selectedDetailFilter.value) {
        case 'new_rows':
            return rows.value.filter((row) => row.expected_action === 'create' && row.action !== 'error');
        case 'update_rows':
            return rows.value.filter((row) => row.expected_action === 'update' && row.action !== 'error');
        case 'error_rows':
            return rows.value.filter((row) => row.action === 'error');
        case 'all_rows':
        default:
            return rows.value;
    }
});

const filteredCatalogItems = computed(() => {
    const items = catalogSummary.value?.items ?? [];

    if (selectedDetailFilter.value === 'missing_catalogs') {
        return items;
    }

    if (selectedDetailFilter.value.startsWith('catalog:')) {
        const type = selectedDetailFilter.value.replace('catalog:', '');
        return items.filter((item) => item.type === type);
    }

    return [];
});

watch(
    () => props.show,
    (show) => {
        if (!show) {
            resetState();
        }
    },
);

const resetState = () => {
    selectedFile.value = null;
    preview.value = null;
    validating.value = false;
    creatingCatalogs.value = false;
    importing.value = false;
    requestError.value = '';
    requestNotice.value = '';
    requestNoticeType.value = 'success';
    selectedDetailFilter.value = 'all_rows';
};

const close = () => {
    resetState();
    emit('close');
};

const handleFileChange = (event) => {
    const [file] = event.target.files ?? [];
    selectedFile.value = file ?? null;
    preview.value = null;
    requestError.value = '';
    requestNotice.value = '';
    selectedDetailFilter.value = 'all_rows';
};

const buildFormData = () => {
    const formData = new FormData();
    formData.append('file', selectedFile.value);

    return formData;
};

const resolveErrorMessage = (error, fallback) => {
    const validationErrors = error?.response?.data?.errors ?? {};
    const firstValidationError = Object.values(validationErrors)?.[0]?.[0];

    return firstValidationError
        || error?.response?.data?.message
        || fallback;
};

const chooseDefaultFilter = (data) => {
    if (Number(data?.summary?.error_records ?? data?.summary?.error_count ?? 0) > 0) {
        return 'error_rows';
    }

    if (Number(data?.catalogs?.missing_total ?? 0) > 0) {
        return 'missing_catalogs';
    }

    return 'all_rows';
};

const applyPreview = (data) => {
    preview.value = data;
    selectedDetailFilter.value = chooseDefaultFilter(data);
};

const validateFile = async () => {
    if (!selectedFile.value) {
        requestError.value = 'Selecciona un archivo .xlsx antes de validar.';
        return;
    }

    validating.value = true;
    requestError.value = '';
    requestNotice.value = '';

    try {
        const { data } = await axios.post(apiUrl('/api/admin/employees/import/preview'), buildFormData(), {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        applyPreview(data);
    } catch (error) {
        preview.value = null;
        requestError.value = resolveErrorMessage(error, 'No se pudo validar el archivo.');
    } finally {
        validating.value = false;
    }
};

const createMissingCatalogs = async () => {
    if (!canCreateMissingCatalogs.value) {
        return;
    }

    creatingCatalogs.value = true;
    requestError.value = '';
    requestNotice.value = '';

    try {
        const { data } = await axios.post(apiUrl('/api/admin/employees/import/catalogs/missing/create'), buildFormData(), {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        applyPreview(data.preview ?? null);
        requestNotice.value = data.message ?? 'Catalogos creados correctamente. Se recalculo la validacion del archivo.';
        requestNoticeType.value = 'success';
    } catch (error) {
        requestError.value = resolveErrorMessage(error, 'No se pudieron crear los catalogos faltantes.');
    } finally {
        creatingCatalogs.value = false;
    }
};

const importFile = async () => {
    if (!canImport.value) {
        return;
    }

    importing.value = true;
    requestError.value = '';
    requestNotice.value = '';

    try {
        const { data } = await axios.post(apiUrl('/api/admin/employees/import'), buildFormData(), {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        emit('imported', data);
    } catch (error) {
        const responseData = error?.response?.data;
        if (responseData?.rows) {
            applyPreview(responseData);
        }

        requestError.value = resolveErrorMessage(error, 'No se pudo importar el archivo.');
    } finally {
        importing.value = false;
    }
};

const selectFilter = (filterKey) => {
    selectedDetailFilter.value = filterKey;
};

const resolveCatalogCardLabel = (type, fallback) => {
    const labels = {
        companies: 'Empresas faltantes',
        locations: 'Ubicaciones faltantes',
        departamentos: 'Departamentos faltantes',
        centros_costo: 'Centros de costo faltantes',
        puestos: 'Puestos faltantes',
        areas: 'Areas faltantes',
        razones_sociales: 'Razones sociales faltantes',
        registros_imss: 'Registros IMSS faltantes',
        periodos_pago: 'Periodos de pago faltantes',
        ubicaciones_laborales: 'Ubicaciones laborales faltantes',
    };

    return labels[type] ?? fallback ?? type;
};

const cardClasses = (tone, active) => {
    const base = 'rounded-2xl border px-4 py-3 text-left transition disabled:cursor-default';
    const tones = {
        default: active ? 'border-slate-900 bg-slate-900 text-white' : 'border-app bg-slate-50 text-app hover:bg-slate-100',
        success: active ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
        info: active ? 'border-sky-700 bg-sky-700 text-white' : 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100',
        danger: active ? 'border-rose-700 bg-rose-700 text-white' : 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100',
        warning: active ? 'border-amber-700 bg-amber-700 text-white' : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
    };

    return `${base} ${tones[tone] ?? tones.default}`;
};

const rowActionLabel = (row) => {
    if (row.action === 'error') return 'Error';
    if (row.blocked_by_catalogs) return row.expected_action === 'update' ? 'Actualizar pendiente' : 'Crear pendiente';
    return row.expected_action === 'update' ? 'Actualizar' : 'Crear';
};

const catalogActionLabel = (item) => {
    return item.can_create ? 'Crear catalogo' : 'Revision manual';
};
</script>

<template>
    <Modal :show="show" max-width="5xl" @close="close">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-app">Importar Excel</h2>
                    <p class="mt-1 text-sm text-muted">
                        Carga un archivo con la estructura del export de trabajadores para crear o actualizar registros.
                    </p>
                </div>
                <button class="rounded-2xl border border-app px-3 py-2 text-sm font-semibold text-muted hover:text-app" @click="close">
                    Cerrar
                </button>
            </div>

            <div class="mt-6 space-y-4">
                <label class="flex flex-col gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Archivo Excel</span>
                    <input
                        type="file"
                        accept=".xlsx"
                        class="rounded-2xl border border-app bg-white px-4 py-3 text-sm dark:bg-slate-900"
                        @change="handleFileChange"
                    >
                </label>

                <p
                    v-if="requestNotice"
                    class="rounded-2xl border px-4 py-3 text-sm"
                    :class="requestNoticeType === 'success'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-sky-200 bg-sky-50 text-sky-700'"
                >
                    {{ requestNotice }}
                </p>

                <p v-if="requestError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {{ requestError }}
                </p>

                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button
                        class="rounded-2xl border border-app px-4 py-2 text-sm font-semibold text-app hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!selectedFile || busy"
                        @click="validateFile"
                    >
                        <span v-if="validating">Validando...</span>
                        <span v-else>Validar archivo</span>
                    </button>
                    <button
                        v-if="preview && Number(catalogSummary?.missing_total ?? 0) > 0"
                        class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!canCreateMissingCatalogs"
                        @click="createMissingCatalogs"
                    >
                        <span v-if="creatingCatalogs">Creando catalogos...</span>
                        <span v-else>Crear catalogos faltantes</span>
                    </button>
                    <button
                        class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!canImport"
                        @click="importFile"
                    >
                        <span v-if="importing">Importando...</span>
                        <span v-else>Importar</span>
                    </button>
                </div>
            </div>

            <div v-if="preview" class="mt-6 space-y-6">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <button
                        v-for="card in generalCards"
                        :key="card.key"
                        type="button"
                        :class="cardClasses(card.tone, selectedDetailFilter === card.key)"
                        @click="selectFilter(card.key)"
                    >
                        <p class="text-xs font-semibold uppercase tracking-[0.3em]">{{ card.label }}</p>
                        <p class="mt-2 text-2xl font-semibold">{{ card.value }}</p>
                    </button>
                </div>

                <div class="rounded-2xl border border-app bg-slate-50/70 px-4 py-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-app">Catalogos relacionados</h3>
                            <p class="mt-1 text-sm text-muted">{{ preview.message }}</p>
                        </div>
                        <div class="grid gap-2 text-sm text-muted sm:grid-cols-2">
                            <p>Faltantes: <span class="font-semibold text-app">{{ catalogSummary?.missing_total ?? 0 }}</span></p>
                            <p>Tipos: <span class="font-semibold text-app">{{ catalogSummary?.missing_types ?? 0 }}</span></p>
                            <p>Creables: <span class="font-semibold text-app">{{ catalogSummary?.creatable_total ?? 0 }}</span></p>
                            <p>Pendientes por catalogo: <span class="font-semibold text-app">{{ summary?.employees_pending_by_catalogs ?? 0 }}</span></p>
                            <p>Resolubles si se crean: <span class="font-semibold text-app">{{ summary?.employees_resolvable_after_catalog_creation ?? 0 }}</span></p>
                            <p>Bloqueantes restantes: <span class="font-semibold text-app">{{ catalogSummary?.blocking_total ?? 0 }}</span></p>
                        </div>
                    </div>
                </div>

                <div v-if="catalogCards.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <button
                        v-for="card in catalogCards"
                        :key="card.key"
                        type="button"
                        :class="cardClasses(card.tone, selectedDetailFilter === card.key)"
                        @click="selectFilter(card.key)"
                    >
                        <p class="text-xs font-semibold uppercase tracking-[0.3em]">{{ card.label }}</p>
                        <p class="mt-2 text-2xl font-semibold">{{ card.value }}</p>
                        <p class="mt-2 text-xs opacity-80">Empleados afectados: {{ card.affectedRows }}</p>
                    </button>
                </div>

                <div
                    v-if="!preview.can_import"
                    class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                >
                    <span v-if="Number(summary?.error_records ?? 0) > 0">
                        La importacion esta bloqueada hasta corregir las filas con error.
                    </span>
                    <span v-else-if="Number(summary?.employees_pending_by_catalogs ?? 0) > 0">
                        La importacion esta bloqueada hasta resolver los catalogos faltantes.
                    </span>
                </div>

                <div class="rounded-2xl border border-app">
                    <div class="border-b border-app px-4 py-3">
                        <h3 class="text-sm font-semibold text-app">{{ activeCard?.label ?? 'Detalle' }}</h3>
                    </div>

                    <div v-if="detailMode === 'employees'" class="max-h-[28rem] overflow-auto">
                        <table class="w-full min-w-[70rem] divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                                <tr>
                                    <th class="px-4 py-3">Fila Excel</th>
                                    <th class="px-4 py-3">CLA_TRAB</th>
                                    <th class="px-4 py-3">Nombre</th>
                                    <th class="px-4 py-3">Estatus</th>
                                    <th class="px-4 py-3">Ubicacion</th>
                                    <th class="px-4 py-3">Centro costo</th>
                                    <th class="px-4 py-3">Departamento</th>
                                    <th class="px-4 py-3">Accion esperada</th>
                                    <th class="px-4 py-3">Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in filteredEmployeeRows"
                                    :key="`${row.row_number}-${row.cla_trab ?? 'na'}-${row.expected_action}`"
                                    class="align-top"
                                >
                                    <td class="px-4 py-3 font-semibold text-app">{{ row.row_number }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.cla_trab ?? 'Sin clave' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.name ?? 'Sin nombre' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.status ?? 'Sin estatus' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.ubicacion ?? 'Sin ubicacion' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.centro_costo ?? 'Sin centro' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ row.departamento ?? 'Sin departamento' }}</td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="rounded-full px-3 py-2 text-xs font-semibold"
                                            :class="row.action === 'error'
                                                ? 'bg-rose-50 text-rose-700'
                                                : row.blocked_by_catalogs
                                                    ? 'bg-amber-50 text-amber-800'
                                                    : row.expected_action === 'update'
                                                        ? 'bg-sky-50 text-sky-700'
                                                        : 'bg-emerald-50 text-emerald-700'"
                                        >
                                            {{ rowActionLabel(row) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="space-y-2 text-sm text-muted">
                                            <p v-if="!row.reasons?.length">Sin incidencias</p>
                                            <p v-for="reason in row.reasons" :key="`${row.row_number}-${reason}`">
                                                {{ reason }}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!filteredEmployeeRows.length">
                                    <td colspan="9" class="px-4 py-6 text-center text-sm text-muted">
                                        No hay filas para este filtro.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="max-h-[28rem] overflow-auto">
                        <table class="w-full min-w-[60rem] divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.3em] text-soft dark:bg-slate-900/40">
                                <tr>
                                    <th class="px-4 py-3">Tipo de catalogo</th>
                                    <th class="px-4 py-3">Clave</th>
                                    <th class="px-4 py-3">Nombre</th>
                                    <th class="px-4 py-3">Empleados afectados</th>
                                    <th class="px-4 py-3">Accion sugerida</th>
                                    <th class="px-4 py-3">Filas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in filteredCatalogItems"
                                    :key="`${item.type}-${item.code ?? 'sin-clave'}-${item.name ?? 'sin-nombre'}`"
                                    class="align-top"
                                >
                                    <td class="px-4 py-3 font-semibold text-app">{{ item.label }}</td>
                                    <td class="px-4 py-3 text-muted">{{ item.code ?? 'Sin clave' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ item.name ?? 'Sin nombre' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ item.employees_affected }}</td>
                                    <td class="px-4 py-3">
                                        <div class="space-y-2">
                                            <span
                                                class="rounded-full px-3 py-2 text-xs font-semibold"
                                                :class="item.can_create ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                            >
                                                {{ catalogActionLabel(item) }}
                                            </span>
                                            <p v-if="item.create_reason" class="text-xs text-muted">{{ item.create_reason }}</p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-muted">
                                        {{ (item.row_numbers ?? []).join(', ') || 'Sin filas' }}
                                    </td>
                                </tr>
                                <tr v-if="!filteredCatalogItems.length">
                                    <td colspan="6" class="px-4 py-6 text-center text-sm text-muted">
                                        No hay catalogos faltantes para este filtro.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
