<script setup>
import { formatDateTime as formatOperationalDate } from '@/presentation/labels';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import PaginationBar from '@/Components/PaginationBar.vue';
import Toast from '@/Components/Toast.vue';
import CompanyFormModal from './Partials/CompanyFormModal.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    initialCompanies: {
        type: Object,
        default: () => ({
            data: [],
            meta: null,
        }),
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canCreateCompanies = computed(() => can('companies', 'create'));
const canUpdateCompanies = computed(() => can('companies', 'update'));
const canDisableCompanies = computed(() => can('companies', 'disable'));

const companies = ref(props.initialCompanies?.data ?? []);
const pagination = ref(props.initialCompanies?.meta ?? null);
const perPage = ref(pagination.value?.per_page ?? 12);
const perPageOptions = [10, 12, 20, 50];
const listLoading = ref(false);
const listError = ref('');
const filtersReady = ref(false);

const filters = reactive({
    search: '',
    status: '',
});

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

const pageSummary = computed(() => {
    const total = pagination.value?.total ?? companies.value.length;

    if (!total) {
        return { start: 0, end: 0, total: 0 };
    }

    if (pagination.value?.from != null && pagination.value?.to != null) {
        return {
            start: pagination.value.from,
            end: pagination.value.to,
            total,
        };
    }

    return {
        start: companies.value.length ? 1 : 0,
        end: companies.value.length,
        total,
    };
});

const totalActive = computed(() => companies.value.filter((company) => company.status === 1).length);
const totalInactive = computed(() => companies.value.filter((company) => company.status === 0).length);
const currentPage = computed(() => pagination.value?.current_page ?? 1);
const totalPages = computed(() => pagination.value?.last_page ?? 1);
const paginationMeta = computed(() => ({
    current_page: pagination.value?.current_page ?? 1,
    last_page: pagination.value?.last_page ?? 1,
    per_page: pagination.value?.per_page ?? perPage.value,
    total: pagination.value?.total ?? companies.value.length,
    from: pagination.value?.from ?? pageSummary.value.start ?? 0,
    to: pagination.value?.to ?? pageSummary.value.end ?? 0,
}));

const defaultForm = (company = null) => ({
    id: company?.id ?? null,
    name: company?.name ?? '',
    code: company?.code ?? '',
    status: company?.status ?? 1,
});

const formState = reactive({
    open: false,
    mode: 'create',
    form: defaultForm(),
    errors: {},
    loading: false,
});

const confirmState = reactive({
    open: false,
    loading: false,
    company: null,
});

const fetchCompanies = async (pageNumber = currentPage.value) => {
    listLoading.value = true;
    listError.value = '';

    try {
        const { data } = await axios.get(route('companies.list'), {
            params: {
                search: filters.search || undefined,
                status: filters.status !== '' ? filters.status : undefined,
                page: pageNumber,
                per_page: perPage.value,
            },
        });

        companies.value = data.data ?? [];
        pagination.value = data.meta ?? pagination.value;
    } catch (error) {
        listError.value = error.response?.data?.message ?? 'No se pudo cargar el catalogo.';
        showToast({
            type: 'error',
            title: 'Error al cargar',
            message: listError.value,
        });
    } finally {
        listLoading.value = false;
    }
};

onMounted(() => {
    filtersReady.value = true;
});

watch(
    () => ({ ...filters }),
    () => {
        if (!filtersReady.value) return;
        fetchCompanies(1);
    },
    { deep: true },
);

const handlePageChange = (pageNumber) => {
    if (pageNumber < 1 || pageNumber > totalPages.value || pageNumber === currentPage.value) return;
    fetchCompanies(pageNumber);
};

const handlePerPageChange = (value) => {
    if (perPage.value === value) return;
    perPage.value = value;
    fetchCompanies(1);
};

const openCreateForm = () => {
    formState.mode = 'create';
    formState.form = defaultForm();
    formState.errors = {};
    formState.open = true;
};

const openEditForm = (company) => {
    formState.mode = 'edit';
    formState.form = defaultForm(company);
    formState.errors = {};
    formState.open = true;
};

const submitForm = async () => {
    formState.loading = true;
    formState.errors = {};

    try {
        const payload = { ...formState.form };
        let response;

        if (formState.mode === 'create') {
            response = await axios.post(route('companies.store'), payload);
        } else {
            response = await axios.put(route('companies.update', payload.id), payload);
        }

        formState.open = false;
        showToast({
            type: 'success',
            title: 'Empresa guardada',
            message: response?.data?.message ?? 'La empresa se guardo correctamente.',
        });
        await fetchCompanies();
    } catch (error) {
        if (error.response?.status === 422) {
            formState.errors = error.response.data.errors ?? {};
        } else {
            showToast({
                type: 'error',
                title: 'Error al guardar',
                message: error.response?.data?.message ?? 'No se pudo guardar la empresa.',
            });
        }
    } finally {
        formState.loading = false;
    }
};

const requestToggle = (company) => {
    confirmState.company = company;
    confirmState.open = true;
};

const toggleStatus = async () => {
    if (!confirmState.company) return;

    confirmState.loading = true;

    try {
        const { data } = await axios.put(route('companies.toggle-status', confirmState.company.id));
        confirmState.open = false;
        confirmState.company = null;
        showToast({
            type: 'success',
            title: 'Estado actualizado',
            message: data.message ?? 'La empresa cambio de estado.',
        });
        await fetchCompanies();
    } catch (error) {
        showToast({
            type: 'error',
            title: 'Error al actualizar',
            message: error.response?.data?.message ?? 'No se pudo cambiar el estado.',
        });
    } finally {
        confirmState.loading = false;
    }
};

const clearFilters = () => {
    filters.search = '';
    filters.status = '';
};

const formatDate = (value) => formatOperationalDate(value, 'Sin fecha');
</script>

<template>
    <Head title="Catalogo de empresas" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-xl font-semibold leading-tight">
                    Catalogo de empresas
                </h1>
                <p class="text-sm text-slate-500">
                    Administra las empresas disponibles para sucursales, relojes y enrolamiento.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <article class="card-kpi bg-gradient-to-br from-cyan-50 to-white dark:from-cyan-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-600">
                        Total
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ pageSummary.total }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Empresas registradas</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-emerald-50 to-white dark:from-emerald-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-500">
                        Activas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ totalActive }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Operando en el sistema</p>
                </article>
                <article class="card-kpi bg-gradient-to-br from-rose-50 to-white dark:from-rose-950/40 dark:to-slate-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-rose-500">
                        Inactivas
                    </p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-slate-100">
                        {{ totalInactive }}
                    </p>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Sin operacion activa</p>
                </article>

                <div
                    v-if="pageSummary.total"
                    class="sm:col-span-3 flex flex-col gap-3 lg:flex-row lg:items-center"
                >
                    <PaginationBar
                        class="flex-1 card"
                        :meta="paginationMeta"
                        :per-page-options="perPageOptions"
                        :disabled="listLoading"
                        @update:page="handlePageChange"
                        @update:perPage="handlePerPageChange"
                    />
                </div>
            </div>

            <div class="card flex flex-wrap items-stretch justify-between gap-4 p-4 sm:items-center">
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <div class="flex w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 px-3 py-1.5 sm:w-auto sm:justify-start">
                        <span class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Estado</span>
                        <select
                            v-model="filters.status"
                            class="w-full bg-transparent text-sm font-medium text-slate-700 focus:outline-none sm:w-auto"
                        >
                            <option value="">Todos</option>
                            <option :value="1">Activas</option>
                            <option :value="0">Inactivas</option>
                        </select>
                    </div>

                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Buscar por nombre o clave"
                        class="w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm shadow-sm focus:border-indigo-400 focus:outline-none sm:w-auto sm:min-w-[16rem]"
                    />
                </div>

                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button
                        type="button"
                        class="w-full rounded-2xl border border-slate-200 px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-slate-500 hover:text-slate-900 sm:w-auto"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                    <button
                        v-if="canCreateCompanies"
                        type="button"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-500 sm:w-auto"
                        @click="openCreateForm"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z"
                                clip-rule="evenodd"
                            />
                        </svg>
                        Nueva empresa
                    </button>
                </div>
            </div>

            <div v-if="listError" class="rounded-3xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-600">
                {{ listError }}
            </div>

            <div v-if="listLoading" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-400">
                Cargando catalogo...
            </div>

            <div v-if="!listLoading" class="space-y-4">
                <article
                    v-for="company in companies"
                    :key="company.id"
                    class="card-record p-5 hover:border-cyan-100 hover:ring-cyan-50 dark:hover:border-cyan-900/60 dark:hover:ring-cyan-950/40"
                >
                    <header class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                Empresa
                            </p>
                            <h3 class="text-xl font-semibold text-slate-900 dark:text-slate-100">
                                {{ company.name }}
                            </h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                Clave {{ company.code || 'Sin clave' }}
                            </p>
                        </div>

                        <span
                            class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em]"
                            :class="company.status ? 'border border-emerald-100 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300' : 'border border-rose-100 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300'"
                        >
                            {{ company.status_label }}
                        </span>
                    </header>

                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <dl class="card-subtle p-4">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Sucursales
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                {{ company.units_count }}
                            </dd>
                            <dd class="text-xs text-slate-500 dark:text-slate-400">
                                Asociadas a la empresa
                            </dd>
                        </dl>

                        <dl class="card-subtle p-4">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Relojes
                            </dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">
                                {{ company.clocks_count }}
                            </dd>
                            <dd class="text-xs text-slate-500 dark:text-slate-400">
                                Equipos vinculados
                            </dd>
                        </dl>

                        <dl class="card-subtle p-4">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">
                                Alta
                            </dt>
                            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                                {{ formatDate(company.created_at) }}
                            </dd>
                            <dd class="text-xs text-slate-500 dark:text-slate-400">
                                Ultima actualizacion {{ formatDate(company.updated_at) }}
                            </dd>
                        </dl>
                    </div>

                    <div
                        v-if="canUpdateCompanies || canDisableCompanies"
                        class="mt-5 flex flex-col gap-2 text-sm font-medium text-slate-600 sm:flex-row sm:flex-wrap"
                    >
                        <button
                            v-if="canUpdateCompanies"
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900 sm:w-auto"
                            @click="openEditForm(company)"
                        >
                            Editar
                        </button>
                        <button
                            v-if="canDisableCompanies"
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-1 rounded-2xl border border-slate-200 px-4 py-2 hover:text-slate-900 sm:w-auto"
                            @click="requestToggle(company)"
                        >
                            {{ company.status ? 'Desactivar' : 'Activar' }}
                        </button>
                    </div>
                </article>

                <p v-if="!companies.length" class="rounded-3xl border border-slate-100 bg-white/80 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/80 dark:text-slate-400">
                    No se encontraron empresas con los filtros seleccionados.
                </p>
            </div>
        </section>

        <CompanyFormModal
            :open="formState.open"
            :mode="formState.mode"
            :form="formState.form"
            :errors="formState.errors"
            :loading="formState.loading"
            @close="formState.open = false"
            @submit="submitForm"
        />

        <ConfirmModal
            :show="confirmState.open"
            :loading="confirmState.loading"
            :title="confirmState.company?.status ? 'Desactivar empresa' : 'Activar empresa'"
            :message="confirmState.company?.status ? 'La empresa dejara de estar disponible para nuevas operaciones del catalogo.' : 'La empresa volvera a estar disponible para el resto de modulos.'"
            confirm-label="Confirmar"
            cancel-label="Cancelar"
            @cancel="confirmState.open = false"
            @confirm="toggleStatus"
        />

        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="toast.show = false"
        />
    </AuthenticatedLayout>
</template>
