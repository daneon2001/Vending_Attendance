<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import Toast from '@/Components/Toast.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const permissionMatrix = computed(() => page.props.auth.permissions ?? {});
const can = (module, action = 'view') => {
    const actions = permissionMatrix.value?.[module] ?? [];
    return actions.includes(action) || actions.includes('manage');
};

const canCreate = computed(() => can('users', 'create'));
const canUpdate = computed(() => can('users', 'update'));
const canDisable = computed(() => can('users', 'disable'));

const rolesOptions = computed(() => props.roles ?? []);

const filters = reactive({
    search: '',
    status: 'todos',
    page: 1,
    perPage: 10,
});

const users = ref([]);
const meta = reactive({
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    total: 0,
});

const loading = ref(false);

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
    requestAnimationFrame(() => {
        toast.show = true;
    });
};

const userModal = reactive({
    open: false,
    mode: 'create',
    loading: false,
    errors: {},
    form: {
        id: null,
        name: '',
        email: '',
        role_id: '',
        password: '',
        password_confirmation: '',
        resetPassword: false,
    },
});

const confirmModal = reactive({
    show: false,
    loading: false,
    user: null,
    activate: true,
});

const passwordRules = [
    'Minimo 10 caracteres',
    'Debe incluir mayusculas y minusculas',
    'Debe incluir al menos un numero',
    'Debe incluir al menos un simbolo',
];

const setPagination = (payloadMeta) => {
    if (!payloadMeta) return;
    meta.current_page = payloadMeta.current_page ?? 1;
    meta.last_page = payloadMeta.last_page ?? 1;
    meta.from = payloadMeta.from ?? 0;
    meta.to = payloadMeta.to ?? 0;
    meta.total = payloadMeta.total ?? 0;
};

const loadUsers = async (pageNumber = filters.page) => {
    loading.value = true;
    filters.page = pageNumber;
    try {
        const { data } = await axios.get('/api/users', {
            params: {
                search: filters.search || undefined,
                status: filters.status !== 'todos' ? filters.status : undefined,
                page: filters.page,
                per_page: filters.perPage,
            },
        });

        users.value = data.data ?? [];
        setPagination(data.meta);
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo cargar la lista',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
        });
    } finally {
        loading.value = false;
    }
};

const resetUserForm = () => {
    userModal.errors = {};
    userModal.form = {
        id: null,
        name: '',
        email: '',
        role_id: rolesOptions.value[0]?.id ?? '',
        password: '',
        password_confirmation: '',
        resetPassword: false,
    };
};

const openCreateModal = () => {
    userModal.mode = 'create';
    resetUserForm();
    userModal.open = true;
};

const openEditModal = (user) => {
    userModal.mode = 'edit';
    userModal.errors = {};
    userModal.form = {
        id: user.id,
        name: user.name,
        email: user.email,
        role_id: user.roles?.[0]?.id ?? rolesOptions.value[0]?.id ?? '',
        password: '',
        password_confirmation: '',
        resetPassword: false,
    };
    userModal.open = true;
};

const submitUser = async () => {
    userModal.loading = true;
    userModal.errors = {};

    const payload = {
        name: userModal.form.name,
        email: userModal.form.email,
        role_id: userModal.form.role_id,
    };

    if (userModal.mode === 'create' || userModal.form.resetPassword) {
        payload.password = userModal.form.password;
        payload.password_confirmation = userModal.form.password_confirmation;
    }

    try {
        if (userModal.mode === 'create') {
            await axios.post('/api/users', payload);
            showToast({
                title: 'Usuario creado',
                message: 'El usuario se registro correctamente.',
            });
        } else {
            await axios.put(`/api/users/${userModal.form.id}`, payload);
            showToast({
                title: 'Usuario actualizado',
                message: 'Los cambios fueron guardados.',
            });
        }

        userModal.open = false;
        await loadUsers();
    } catch (error) {
        if (error.response?.status === 422) {
            userModal.errors = error.response.data.errors ?? {};
        } else {
            showToast({
                type: 'error',
                title: 'Accion no completada',
                message: error.response?.data?.message ?? 'Revisa la informacion.',
            });
        }
    } finally {
        userModal.loading = false;
    }
};

const askToggleStatus = (user) => {
    confirmModal.user = user;
    confirmModal.activate = !user.status;
    confirmModal.show = true;
};

const confirmStatusChange = async () => {
    if (!confirmModal.user) return;
    confirmModal.loading = true;
    try {
        await axios.patch(`/api/users/${confirmModal.user.id}/status`, {
            status: confirmModal.activate ? 'active' : 'inactive',
        });
        showToast({
            title: 'Estado actualizado',
            message: `El usuario ahora esta ${confirmModal.activate ? 'activo' : 'inactivo'}.`,
        });
        confirmModal.show = false;
        await loadUsers();
    } catch (error) {
        showToast({
            type: 'error',
            title: 'No se pudo cambiar el estado',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
        });
    } finally {
        confirmModal.loading = false;
    }
};

const applyFilters = () => loadUsers(1);

const goToPage = (direction) => {
    if (direction === 'prev' && meta.current_page > 1) {
        loadUsers(meta.current_page - 1);
    }
    if (direction === 'next' && meta.current_page < meta.last_page) {
        loadUsers(meta.current_page + 1);
    }
};

const statusBadgeClass = (status) =>
    status
        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-100'
        : 'bg-slate-100 text-soft dark:bg-slate-800';

watch(
    () => filters.perPage,
    () => {
        loadUsers(1);
    },
);

onMounted(() => {
    resetUserForm();
    loadUsers();
});
</script>

<template>
    <Head title="Usuarios del sistema" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold">Configuracion · Usuarios del sistema</h1>
                <p class="text-sm text-muted">
                    Administra las cuentas que pueden acceder al sistema y define sus roles.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="text-sm text-muted">
                    Consulta y gestiona los usuarios internos de Medical Life.
                </p>
                <button
                    v-if="canCreate"
                    type="button"
                    class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500"
                    @click="openCreateModal"
                >
                    Nuevo usuario
                </button>
            </div>

            <div class="card flex flex-wrap gap-4 px-4 py-4 text-sm">
                <label class="flex min-w-[220px] flex-1 flex-col">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Buscar</span>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Nombre o correo..."
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @keyup.enter="applyFilters"
                    />
                </label>
                <label class="flex flex-col">
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">Estado</span>
                    <select
                        v-model="filters.status"
                        class="rounded-2xl border border-app bg-white px-4 py-2 dark:bg-slate-900"
                        @change="applyFilters"
                    >
                        <option value="todos">Todos</option>
                        <option value="active">Activos</option>
                        <option value="inactive">Inactivos</option>
                    </select>
                </label>
                <div class="flex items-end">
                    <button
                        class="rounded-2xl border border-app px-4 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted hover:text-app"
                        @click="applyFilters"
                    >
                        Aplicar
                    </button>
                </div>
            </div>

            <div class="card overflow-hidden p-0">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-[0.3em] text-soft">
                                <th class="px-4 py-3">Usuario</th>
                                <th class="px-4 py-3">Rol</th>
                                <th class="px-4 py-3">Estado</th>
                                <th class="px-4 py-3 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody v-if="users.length">
                            <tr
                                v-for="user in users"
                                :key="user.id"
                                class="border-b border-slate-100 last:border-b-0 dark:border-slate-800"
                            >
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-app">{{ user.name }}</p>
                                    <p class="text-xs text-muted">{{ user.email }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-muted">
                                        {{ user.roles?.[0]?.name ?? 'Sin rol' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                                        :class="statusBadgeClass(user.status)"
                                    >
                                        {{ user.status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex gap-3 text-xs font-semibold">
                                        <button
                                            v-if="canUpdate"
                                            type="button"
                                            class="text-indigo-600 hover:text-indigo-500"
                                            @click="openEditModal(user)"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            v-if="canDisable"
                                            type="button"
                                            class="text-slate-500 hover:text-slate-700 dark:text-slate-300 dark:hover:text-white"
                                            @click="askToggleStatus(user)"
                                        >
                                            {{ user.status ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tbody v-else>
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-muted">
                                    No se encontraron usuarios con los filtros seleccionados.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                <div>
                    <p class="text-xs uppercase tracking-[0.3em] text-soft">Mostrando</p>
                    <p class="text-app">
                        {{ meta.from || 0 }} - {{ meta.to || 0 }} de {{ meta.total || 0 }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs uppercase tracking-[0.3em] text-soft">Registros</label>
                    <select
                        v-model.number="filters.perPage"
                        class="rounded-2xl border border-app bg-white px-6 py-2 text-sm dark:bg-slate-900"
                    >
                        <option :value="10">10</option>
                        <option :value="12">12</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:opacity-40"
                        :disabled="meta.current_page <= 1"
                        @click="goToPage('prev')"
                    >
                        Anterior
                    </button>
                    <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                        Pagina {{ meta.current_page }} de {{ meta.last_page }}
                    </span>
                    <button
                        class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page"
                        @click="goToPage('next')"
                    >
                        Siguiente
                    </button>
                </div>
            </div>
        </section>

        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="toast.show = false"
        />

        <div
            v-if="userModal.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/70 px-4 py-8"
        >
            <div class="flex w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900">
                <header class="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            {{ userModal.mode === 'create' ? 'Nuevo usuario' : 'Editar usuario' }}
                        </p>
                        <h3 class="text-2xl font-semibold text-app">
                            {{ userModal.mode === 'create' ? 'Crear usuario' : 'Actualizar usuario' }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-app p-2 text-soft hover:text-app dark:hover:text-white"
                        @click="userModal.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <form class="flex flex-1 flex-col overflow-y-auto px-6 py-6" @submit.prevent="submitUser">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-semibold text-app">
                            Nombre completo
                            <input
                                v-model="userModal.form.name"
                                type="text"
                                class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                            />
                            <span v-if="userModal.errors?.name" class="text-xs text-rose-500">
                                {{ userModal.errors.name[0] }}
                            </span>
                        </label>
                        <label class="text-sm font-semibold text-app">
                            Correo
                            <input
                                v-model="userModal.form.email"
                                type="email"
                                class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                            />
                            <span v-if="userModal.errors?.email" class="text-xs text-rose-500">
                                {{ userModal.errors.email[0] }}
                            </span>
                        </label>
                    </div>
                    <label class="mt-4 text-sm font-semibold text-app">
                        Rol asignado
                        <select
                            v-model="userModal.form.role_id"
                            class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                        >
                            <option value="">Selecciona un rol</option>
                            <option
                                v-for="role in rolesOptions"
                                :key="role.id"
                                :value="role.id"
                            >
                                {{ role.name }}
                            </option>
                        </select>
                        <span v-if="userModal.errors?.role_id" class="text-xs text-rose-500">
                            {{ userModal.errors.role_id[0] }}
                        </span>
                    </label>

                    <div class="mt-6 space-y-4">
                        <template v-if="userModal.mode === 'create' || userModal.form.resetPassword">
                            <label class="text-sm font-semibold text-app">
                                Contraseña
                                <input
                                    v-model="userModal.form.password"
                                    type="password"
                                    class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                                />
                                <span v-if="userModal.errors?.password" class="text-xs text-rose-500">
                                    {{ userModal.errors.password[0] }}
                                </span>
                            </label>
                            <label class="text-sm font-semibold text-app">
                                Confirmar contraseña
                                <input
                                    v-model="userModal.form.password_confirmation"
                                    type="password"
                                    class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                                />
                            </label>
                            <ul class="text-xs text-muted">
                                <li v-for="rule in passwordRules" :key="rule">• {{ rule }}</li>
                            </ul>
                        </template>
                        <div
                            v-else
                            class="rounded-2xl border border-dashed border-app px-4 py-3 text-xs text-muted"
                        >
                            La contraseña se mantiene sin cambios.
                            <button
                                type="button"
                                class="ml-2 text-indigo-600 hover:text-indigo-500"
                                @click="userModal.form.resetPassword = true"
                            >
                                Restablecer
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <button
                            type="button"
                            class="rounded-2xl px-4 py-2 text-sm font-semibold text-soft hover:text-app dark:hover:text-white"
                            @click="userModal.open = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60"
                            :disabled="userModal.loading"
                        >
                            <span v-if="userModal.loading">Guardando...</span>
                            <span v-else>Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <ConfirmModal
            :show="confirmModal.show"
            :loading="confirmModal.loading"
            :title="confirmModal.activate ? 'Activar usuario' : 'Desactivar usuario'"
            :message="confirmModal.activate
                ? 'El usuario podra iniciar sesion nuevamente.'
                : 'El usuario no podra iniciar sesion hasta que lo actives.'"
            confirm-label="Confirmar"
            cancel-label="Cancelar"
            @cancel="confirmModal.show = false"
            @confirm="confirmStatusChange"
        />
    </AuthenticatedLayout>
</template>
