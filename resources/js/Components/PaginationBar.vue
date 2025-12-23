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
const total = computed(() => props.meta?.total ?? 0);

const canGoPrev = computed(() => currentPage.value > 1);
const canGoNext = computed(() => currentPage.value < lastPage.value);

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
        class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-app bg-white px-4 py-3 text-sm dark:bg-slate-900"
        :class="compact ? 'text-xs' : 'text-sm'"
    >
        <div class="min-w-[150px] text-soft">
            Mostrando
            <span class="font-semibold text-app">{{ from }}</span>
            –
            <span class="font-semibold text-app">{{ to }}</span>
            de
            <span class="font-semibold text-app">{{ total }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canGoPrev || disabled"
                aria-label="Pagina anterior"
                @click="changePage(currentPage - 1)"
            >
                Anterior
            </button>
            <span class="text-xs font-semibold uppercase tracking-[0.3em] text-soft">
                Página {{ currentPage }} de {{ lastPage }}
            </span>
            <button
                type="button"
                class="rounded-2xl border border-app px-3 py-2 text-xs font-semibold uppercase tracking-[0.3em] text-muted disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canGoNext || disabled"
                aria-label="Pagina siguiente"
                @click="changePage(currentPage + 1)"
            >
                Siguiente
            </button>
        </div>

        <div class="flex items-center gap-2 text-soft">
            <label class="text-xs uppercase tracking-[0.3em]">Registros</label>
            <select
                class="rounded-2xl border border-app bg-white px-6 py-2 text-sm dark:bg-slate-900"
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
