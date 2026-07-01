<script setup>
import { computed } from 'vue';

const props = defineProps({
    meta: {
        type: Object,
        required: true,
    },
    perPageOptions: {
        type: Array,
        default: () => [10, 15, 25, 50],
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:page', 'update:perPage']);

const currentPage = computed(() => props.meta?.current_page ?? 1);
const lastPage = computed(() => props.meta?.last_page ?? 1);
const from = computed(() => props.meta?.from ?? 0);
const to = computed(() => props.meta?.to ?? 0);
const total = computed(() => props.meta?.total ?? null);
const hasMorePages = computed(() => {
    if (typeof props.meta?.has_more_pages === 'boolean') {
        return props.meta.has_more_pages;
    }

    return currentPage.value < lastPage.value;
});

const canGoPrev = computed(() => currentPage.value > 1);
const canGoNext = computed(() => hasMorePages.value);

const changePage = (newPage) => {
    if (props.disabled) return;
    const target = Math.min(Math.max(newPage, 1), lastPage.value || 1);
    emit('update:page', target);
};

const changePerPage = (event) => {
    const value = Number(event.target.value);
    if (props.disabled) return;
    emit('update:perPage', value);
    emit('update:page', 1);
};
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-xl border border-app bg-white px-4 py-3 text-sm dark:bg-slate-900 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
        :class="compact ? 'text-xs' : 'text-sm'"
    >
        <div class="w-full text-soft sm:w-auto sm:min-w-[150px]">
            <template v-if="total !== null">
                Mostrando
                <span class="font-semibold text-app">{{ from }}</span>
                -
                <span class="font-semibold text-app">{{ to }}</span>
                de
                <span class="font-semibold text-app">{{ total }}</span>
            </template>
            <template v-else>
                Mostrando
                <span class="font-semibold text-app">{{ from }}</span>
                -
                <span class="font-semibold text-app">{{ to }}</span>
            </template>
        </div>

        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <button
                type="button"
                class="w-full rounded-lg border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto"
                :disabled="!canGoPrev || disabled"
                aria-label="Pagina anterior"
                @click="changePage(currentPage - 1)"
            >
                Anterior
            </button>
            <span class="text-center text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                <template v-if="total !== null">
                    Pagina {{ currentPage }} de {{ lastPage }}
                </template>
                <template v-else>
                    Pagina {{ currentPage }}
                </template>
            </span>
            <button
                type="button"
                class="w-full rounded-lg border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto"
                :disabled="!canGoNext || disabled"
                aria-label="Pagina siguiente"
                @click="changePage(currentPage + 1)"
            >
                Siguiente
            </button>
        </div>

        <div class="flex w-full items-center justify-between gap-2 text-soft sm:w-auto sm:justify-end">
            <label class="text-xs uppercase tracking-[0.3em]">Registros</label>
            <select
                class="w-24 rounded-lg border border-app bg-white px-3 py-2 text-sm dark:bg-slate-900"
                :value="props.meta?.per_page ?? perPageOptions[0]"
                :disabled="disabled"
                @change="changePerPage"
            >
                <option v-for="option in perPageOptions" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>
        </div>
    </div>
</template>
