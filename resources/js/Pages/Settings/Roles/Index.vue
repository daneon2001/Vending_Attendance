<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Toast from '@/Components/Toast.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
    modules: {
        type: Object,
        default: () => ({}),
    },
    users: {
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

const toast = reactive({
    show: false,
    type: 'success',
    title: '',
    message: '',
    duration: 5000,
});

const emitToast = ({ type = 'success', title = '', message = '', duration }) => {
    toast.type = type;
    toast.title = title;
    toast.message = message;
    toast.duration = duration ?? (type === 'error' ? 9000 : 5000);
    toast.show = true;
};

const closeToast = () => {
    toast.show = false;
};

const roleList = ref(props.roles ?? []);
const selectedRole = ref(roleList.value[0] ?? null);

watch(
    () => props.roles,
    (value) => {
        roleList.value = value ?? [];
        if (roleList.value.length && !selectedRole.value) {
            selectedRole.value = roleList.value[0];
        }
    },
    { deep: true },
);

const roleForm = reactive({
    open: false,
    mode: 'create',
    loading: false,
    errors: {},
    form: {
        id: null,
        name: '',
        description: '',
        permissions: {},
    },
});

const assignModal = reactive({
    open: false,
    loading: false,
    role: null,
    userIds: [],
    errors: {},
});

const moduleEntries = computed(() => Object.entries(props.modules ?? {}));

const resetRoleForm = () => {
    roleForm.form = {
        id: null,
        name: '',
        description: '',
        permissions: {},
    };
    roleForm.errors = {};
};

const openCreateModal = () => {
    resetRoleForm();
    roleForm.mode = 'create';
    roleForm.open = true;
};

const openEditModal = (role) => {
    resetRoleForm();
    roleForm.mode = 'edit';
    roleForm.form = {
        id: role.id,
        name: role.name,
        description: role.description ?? '',
        permissions: JSON.parse(JSON.stringify(role.permissions ?? {})),
    };
    roleForm.open = true;
};

const togglePermission = (moduleKey, action) => {
    const modulePermissions = roleForm.form.permissions[moduleKey] ?? [];
    if (modulePermissions.includes(action)) {
        roleForm.form.permissions[moduleKey] = modulePermissions.filter((item) => item !== action);
    } else {
        roleForm.form.permissions[moduleKey] = [...modulePermissions, action];
    }
};

const toggleModule = (moduleKey, checked) => {
    const actions = props.modules?.[moduleKey]?.actions ?? [];
    roleForm.form.permissions[moduleKey] = checked ? [...actions] : [];
};

const isModuleFullySelected = (moduleKey) => {
    const actions = props.modules?.[moduleKey]?.actions ?? [];
    const selected = roleForm.form.permissions[moduleKey] ?? [];
    return selected.length && selected.length === actions.length;
};

const moduleHasAction = (moduleKey, action) => {
    const selected = roleForm.form.permissions[moduleKey] ?? [];
    return selected.includes(action);
};

const upsertRole = (role) => {
    const index = roleList.value.findIndex((item) => item.id === role.id);
    if (index === -1) {
        roleList.value = [role, ...roleList.value];
    } else {
        roleList.value.splice(index, 1, role);
    }
    if (!selectedRole.value || selectedRole.value.id === role.id) {
        selectedRole.value = role;
    }
};

const submitRoleForm = async () => {
    roleForm.loading = true;
    roleForm.errors = {};

    try {
        const payload = {
            name: roleForm.form.name,
            description: roleForm.form.description,
            permissions: roleForm.form.permissions,
        };

        let response;
        if (roleForm.mode === 'create') {
            response = await axios.post(route('settings.roles.store'), payload);
        } else {
            response = await axios.put(route('settings.roles.update', roleForm.form.id), payload);
        }

        upsertRole(response.data.data);
        roleForm.open = false;
        emitToast({
            type: 'success',
            title: 'Rol guardado',
            message: `El rol ${response.data.data.name} ha sido actualizado.`,
        });
    } catch (error) {
        if (error.response?.status === 422) {
            roleForm.errors = error.response.data.errors ?? {};
        } else {
            emitToast({
                type: 'error',
                title: 'Error al guardar',
                message: error.response?.data?.message ?? 'Intenta nuevamente.',
            });
        }
    } finally {
        roleForm.loading = false;
    }
};

const deleteRole = async (role) => {
    if (!confirm(`¿Eliminar el rol ${role.name}?`)) {
        return;
    }

    try {
        await axios.delete(route('settings.roles.destroy', role.id));
        roleList.value = roleList.value.filter((item) => item.id !== role.id);
        if (selectedRole.value?.id === role.id) {
            selectedRole.value = roleList.value[0] ?? null;
        }

        emitToast({
            type: 'success',
            title: 'Rol eliminado',
            message: 'El rol se eliminó correctamente.',
        });
    } catch (error) {
        emitToast({
            type: 'error',
            title: 'No se pudo eliminar',
            message: error.response?.data?.message ?? 'Intenta de nuevo.',
        });
    }
};

const openAssignModal = (role) => {
    assignModal.role = role;
    assignModal.userIds = [];
    assignModal.errors = {};
    assignModal.open = true;
};

const submitAssignment = async () => {
    if (!assignModal.role) return;
    assignModal.loading = true;
    assignModal.errors = {};

    try {
        const { data } = await axios.post(route('settings.roles.users.store', assignModal.role.id), {
            user_ids: assignModal.userIds,
        });

        upsertRole(data.data);
        assignModal.open = false;
        emitToast({
            type: 'success',
            title: 'Usuarios asignados',
            message: 'Los usuarios seleccionados ahora pertenecen al rol.',
        });
    } catch (error) {
        if (error.response?.status === 422) {
            assignModal.errors = error.response.data.errors ?? {};
        } else {
            emitToast({
                type: 'error',
                title: 'No se pudo asignar',
                message: error.response?.data?.message ?? 'Revisa la información.',
            });
        }
    } finally {
        assignModal.loading = false;
    }
};

const removeUserFromRole = async (role, user) => {
    try {
        const { data } = await axios.delete(route('settings.roles.users.destroy', [role.id, user.id]));
        upsertRole(data.data);
        emitToast({
            type: 'success',
            title: 'Usuario removido',
            message: `${user.name} ya no pertenece al rol.`,
        });
    } catch (error) {
        emitToast({
            type: 'error',
            title: 'No se pudo remover',
            message: error.response?.data?.message ?? 'Intenta nuevamente.',
        });
    }
};

const selectRole = (role) => {
    selectedRole.value = role;
};
</script>

<template>
    <Head title="Roles y permisos" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h1 class="text-app text-2xl font-semibold">Configuración · Roles y permisos</h1>
                <p class="text-sm text-muted">
                    Administra los perfiles de acceso y el alcance de cada módulo.
                </p>
            </div>
        </template>

        <section class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-app bg-white/70 px-6 py-4 dark:bg-slate-900/60">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Roles activos</p>
                    <p class="text-2xl font-semibold text-app">
                        {{ roleList.length }}
                    </p>
                </div>
                <button
                    v-if="can('settings', 'create')"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60"
                    @click="openCreateModal"
                >
                    <span>Nuevo rol</span>
                </button>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="card p-4 lg:col-span-2">
                    <header class="flex items-center justify-between pb-4">
                        <div>
                            <h2 class="text-lg font-semibold text-app">Lista de roles</h2>
                            <p class="text-sm text-muted">Selecciona un rol para ver los detalles y permisos.</p>
                        </div>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead>
                                <tr class="text-xs uppercase tracking-[0.3em] text-soft">
                                    <th class="px-3 py-2">Rol</th>
                                    <th class="px-3 py-2">Usuarios</th>
                                    <th class="px-3 py-2">Sistema</th>
                                    <th class="px-3 py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="role in roleList"
                                    :key="role.id"
                                    class="border-b border-slate-100 last:border-none dark:border-slate-800"
                                >
                                    <td class="px-3 py-3">
                                        <button
                                            type="button"
                                            class="text-left"
                                            @click="selectRole(role)"
                                        >
                                            <p class="font-semibold text-app">{{ role.name }}</p>
                                            <p class="text-xs text-muted">{{ role.description }}</p>
                                        </button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                            {{ role.user_count }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-widest"
                                            :class="role.is_system ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                                        >
                                            {{ role.is_system ? 'System' : 'Custom' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <div class="inline-flex gap-2">
                                            <button
                                                v-if="can('settings', 'update')"
                                                type="button"
                                                class="text-xs font-semibold text-indigo-600 hover:text-indigo-500"
                                                @click="openEditModal(role)"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                v-if="!role.is_system && can('settings', 'delete')"
                                                type="button"
                                                class="text-xs font-semibold text-rose-600 hover:text-rose-500"
                                                @click="deleteRole(role)"
                                            >
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="!roleList.length" class="py-6 text-center text-sm text-muted">
                            No hay roles registrados aún.
                        </p>
                    </div>
                </div>

                <div class="card p-5">
                    <div v-if="selectedRole" class="space-y-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Rol seleccionado</p>
                                <h3 class="text-xl font-semibold text-app">{{ selectedRole.name }}</h3>
                                <p class="text-sm text-muted">{{ selectedRole.description }}</p>
                            </div>
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                                :class="selectedRole.is_system ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-100' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                            >
                                {{ selectedRole.is_system ? 'System' : 'Custom' }}
                            </span>
                        </div>

                        <div class="space-y-2 rounded-2xl border border-app p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Permisos</p>
                            <div class="space-y-2 text-sm text-muted max-h-56 overflow-y-auto">
                                <div
                                    v-for="[moduleKey, moduleMeta] in moduleEntries"
                                    :key="moduleKey"
                                    class="rounded-2xl border border-transparent px-3 py-2 transition hover:border-app dark:hover:border-slate-700"
                                >
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                        {{ moduleMeta.label }}
                                    </p>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span
                                            v-for="action in (selectedRole.permissions?.[moduleKey] ?? [])"
                                            :key="`${moduleKey}-${action}`"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300"
                                        >
                                            {{ action }}
                                        </span>
                                        <span
                                            v-if="!(selectedRole.permissions?.[moduleKey]?.length)"
                                            class="text-xs text-muted"
                                        >
                                            Sin permisos
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3 rounded-2xl border border-app p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                                        Usuarios asignados
                                    </p>
                                    <p class="text-sm text-muted">
                                        {{ selectedRole.users?.length ?? 0 }} usuarios
                                    </p>
                                </div>
                                <button
                                    v-if="can('settings', 'update')"
                                    type="button"
                                    class="text-xs font-semibold text-indigo-600 hover:text-indigo-500"
                                    @click="openAssignModal(selectedRole)"
                                >
                                    Asignar usuarios
                                </button>
                            </div>
                            <ul class="space-y-2 text-sm text-app max-h-40 overflow-y-auto">
                                <li
                                    v-for="user in selectedRole.users ?? []"
                                    :key="user.id"
                                    class="flex items-center justify-between rounded-2xl border border-app px-3 py-2"
                                >
                                    <div>
                                        <p class="font-semibold">{{ user.name }}</p>
                                        <p class="text-xs text-muted">{{ user.email }}</p>
                                        <span
                                            class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                                            :class="user.status ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-100' : 'bg-slate-100 text-soft dark:bg-slate-800'"
                                        >
                                            {{ user.status_label }}
                                        </span>
                                    </div>
                                    <button
                                        v-if="can('settings', 'update')"
                                        type="button"
                                        class="text-xs text-rose-500 hover:text-rose-400"
                                        @click="removeUserFromRole(selectedRole, user)"
                                    >
                                        Quitar
                                    </button>
                                </li>
                                <li v-if="!(selectedRole.users?.length)" class="text-xs text-muted">
                                    Aún no hay usuarios asignados.
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div v-else class="flex h-full items-center justify-center text-sm text-muted">
                        Selecciona un rol para ver sus detalles.
                    </div>
                </div>
            </div>
        </section>

        <!-- Role form modal -->
        <div
            v-if="roleForm.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div
                class="flex w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-slate-900"
                :class="['max-h-[90vh]', 'sm:max-h-[85vh]']"
            >
                <header
                    class="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-800"
                >
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                            {{ roleForm.mode === 'create' ? 'Nuevo rol' : 'Editar rol' }}
                        </p>
                        <h3 class="text-2xl font-semibold text-app">
                            {{ roleForm.mode === 'create' ? 'Crear rol' : 'Actualizar rol' }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-app p-2 text-soft hover:text-app dark:hover:text-white"
                        @click="roleForm.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </header>

                <form class="flex flex-1 flex-col overflow-hidden" @submit.prevent="submitRoleForm">
                    <div class="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="text-sm font-semibold text-app">
                                Nombre del rol
                                <input
                                    v-model="roleForm.form.name"
                                    :disabled="roleForm.mode === 'edit' && selectedRole?.is_system"
                                    type="text"
                                    class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                                />
                                <span v-if="roleForm.errors?.name" class="text-xs text-rose-500">
                                    {{ roleForm.errors.name[0] }}
                                </span>
                            </label>
                            <label class="text-sm font-semibold text-app">
                                Descripción
                                <input
                                    v-model="roleForm.form.description"
                                    type="text"
                                    class="mt-1 w-full rounded-2xl border border-app px-4 py-2 text-sm dark:bg-slate-900"
                                />
                            </label>
                        </div>

                        <div class="rounded-3xl border border-app">
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-[0.3em] text-soft">
                                        <th class="px-4 py-3">Módulo</th>
                                        <th class="px-4 py-3">Permisos</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <tr
                                        v-for="[moduleKey, moduleMeta] in moduleEntries"
                                        :key="moduleKey"
                                    >
                                        <td class="px-4 py-3 text-sm font-semibold text-app">
                                            {{ moduleMeta.label }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-wrap gap-3">
                                                <label
                                                    v-for="action in moduleMeta.actions"
                                                    :key="`${moduleKey}-${action}`"
                                                    class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.3em] text-soft"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                        :checked="moduleHasAction(moduleKey, action)"
                                                        @change="togglePermission(moduleKey, action)"
                                                    />
                                                    {{ action }}
                                                </label>
                                            </div>
                                            <button
                                                type="button"
                                                class="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-500"
                                                @click="toggleModule(moduleKey, !isModuleFullySelected(moduleKey))"
                                            >
                                                {{ isModuleFullySelected(moduleKey) ? 'Quitar todo' : 'Seleccionar todo' }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-slate-100 px-6 py-4 dark:border-slate-800 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-2xl px-4 py-2 text-sm font-semibold text-soft hover:text-app dark:hover:text-white"
                            @click="roleForm.open = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60"
                            :disabled="roleForm.loading"
                        >
                            <span v-if="roleForm.loading">Guardando...</span>
                            <span v-else>Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignment modal -->
        <div
            v-if="assignModal.open"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 px-4 py-8"
        >
            <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl dark:bg-slate-900">
                <div class="flex items-center justify-between pb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Asignar usuarios</p>
                        <h3 class="text-xl font-semibold text-app">{{ assignModal.role?.name }}</h3>
                    </div>
                    <button
                        type="button"
                        class="rounded-full border border-app p-2 text-soft hover:text-app dark:hover:text-white"
                        @click="assignModal.open = false"
                    >
                        <span class="sr-only">Cerrar</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <form class="space-y-4" @submit.prevent="submitAssignment">
                    <label class="text-sm font-semibold text-app">
                        Selecciona usuarios
                        <select
                            v-model="assignModal.userIds"
                            multiple
                            class="mt-1 h-40 w-full rounded-2xl border border-app px-3 py-2 text-sm dark:bg-slate-900"
                        >
                            <option
                                v-for="user in users"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.name }} — {{ user.email }}
                            </option>
                        </select>
                        <span v-if="assignModal.errors?.user_ids" class="text-xs text-rose-500">
                            {{ assignModal.errors.user_ids[0] }}
                        </span>
                    </label>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-2xl px-4 py-2 text-sm font-semibold text-soft hover:text-app dark:hover:text-white"
                            @click="assignModal.open = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-60"
                            :disabled="assignModal.loading"
                        >
                            <span v-if="assignModal.loading">Guardando...</span>
                            <span v-else>Asignar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <Toast
            :show="toast.show"
            :type="toast.type"
            :title="toast.title"
            :message="toast.message"
            :duration="toast.duration"
            @close="closeToast"
        />
    </AuthenticatedLayout>
</template>
