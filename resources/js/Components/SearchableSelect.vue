<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Selecciona una opcion',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    inputClass: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const rootRef = ref(null);
const searchInputRef = ref(null);
const open = ref(false);
const searchQuery = ref('');
const highlightedIndex = ref(-1);

const normalizedOptions = computed(() =>
    props.options.map((option) => ({
        value: String(option.value ?? option.id ?? ''),
        text: option.label ?? option.name ?? '',
        disabled: Boolean(option.disabled),
    })),
);

const normalizedValue = computed(() => String(props.modelValue ?? ''));
const selectedOption = computed(() =>
    normalizedOptions.value.find((option) => option.value === normalizedValue.value) ?? null,
);

const filteredOptions = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (query === '') {
        return normalizedOptions.value;
    }

    return normalizedOptions.value.filter((option) => option.text.toLowerCase().includes(query));
});

const triggerLabel = computed(() => selectedOption.value?.text || props.placeholder);
const hasSelection = computed(() => normalizedValue.value !== '');

const focusSearchInput = async () => {
    await nextTick();
    searchInputRef.value?.focus();
    searchInputRef.value?.select();
};

const openDropdown = async () => {
    if (props.disabled) {
        return;
    }

    open.value = true;
    searchQuery.value = '';
    highlightedIndex.value = filteredOptions.value.findIndex((option) => option.value === normalizedValue.value);
    await focusSearchInput();
};

const closeDropdown = () => {
    open.value = false;
    searchQuery.value = '';
    highlightedIndex.value = -1;
};

const selectOption = (option) => {
    if (!option || option.disabled) {
        return;
    }

    emit('update:modelValue', option.value);
    emit('change', option.value);
    closeDropdown();
};

const clearSelection = () => {
    emit('update:modelValue', '');
    emit('change', '');
    closeDropdown();
};

const moveHighlight = (direction) => {
    if (!filteredOptions.value.length) {
        highlightedIndex.value = -1;
        return;
    }

    let nextIndex = highlightedIndex.value;

    do {
        nextIndex += direction;

        if (nextIndex < 0) {
            nextIndex = filteredOptions.value.length - 1;
        }

        if (nextIndex >= filteredOptions.value.length) {
            nextIndex = 0;
        }
    } while (filteredOptions.value[nextIndex]?.disabled && nextIndex !== highlightedIndex.value);

    highlightedIndex.value = nextIndex;
};

const selectHighlighted = () => {
    if (highlightedIndex.value < 0 || highlightedIndex.value >= filteredOptions.value.length) {
        return;
    }

    selectOption(filteredOptions.value[highlightedIndex.value]);
};

const handleDocumentPointerDown = (event) => {
    if (!rootRef.value?.contains(event.target)) {
        closeDropdown();
    }
};

const handleTriggerKeydown = async (event) => {
    if (props.disabled) {
        return;
    }

    if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
        event.preventDefault();

        if (!open.value) {
            await openDropdown();
            return;
        }

        moveHighlight(1);
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();

        if (!open.value) {
            await openDropdown();
            return;
        }

        moveHighlight(-1);
    }
};

const handleSearchKeydown = (event) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveHighlight(1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveHighlight(-1);
        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        selectHighlighted();
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        closeDropdown();
    }
};

watch(filteredOptions, (options) => {
    if (!options.length) {
        highlightedIndex.value = -1;
        return;
    }

    if (highlightedIndex.value >= options.length) {
        highlightedIndex.value = 0;
    }
});

watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) {
            closeDropdown();
        }
    },
);

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleDocumentPointerDown);
});
</script>

<template>
    <div ref="rootRef" class="relative" data-select-search-root="ignore">
        <button
            type="button"
            :disabled="disabled"
            :class="[
                inputClass,
                'flex w-full items-center justify-between gap-3 text-left',
                disabled ? 'cursor-not-allowed opacity-60' : '',
            ]"
            @click="open ? closeDropdown() : openDropdown()"
            @keydown="handleTriggerKeydown"
        >
            <span :class="hasSelection ? 'text-app' : 'text-slate-400'">
                {{ triggerLabel }}
            </span>
            <span class="shrink-0 text-slate-400">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path
                        fill-rule="evenodd"
                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.51a.75.75 0 01-1.08 0l-4.25-4.51a.75.75 0 01.02-1.06z"
                        clip-rule="evenodd"
                    />
                </svg>
            </span>
        </button>

        <div
            v-if="open"
            class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
        >
            <div class="border-b border-slate-100 p-2">
                <input
                    ref="searchInputRef"
                    v-model="searchQuery"
                    type="text"
                    class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400"
                    placeholder="Buscar empleado..."
                    @keydown="handleSearchKeydown"
                />
            </div>

            <div class="max-h-64 overflow-y-auto py-1">
                <button
                    type="button"
                    class="flex w-full items-center px-3 py-2 text-left text-sm text-slate-500 hover:bg-slate-50"
                    :class="highlightedIndex === 0 && !filteredOptions.length ? 'bg-slate-50' : ''"
                    @click="clearSelection"
                >
                    {{ placeholder }}
                </button>

                <button
                    v-for="(option, index) in filteredOptions"
                    :key="option.value"
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50"
                    :class="[
                        option.disabled ? 'cursor-not-allowed text-slate-300' : 'text-slate-700',
                        highlightedIndex === index ? 'bg-slate-50' : '',
                    ]"
                    :disabled="option.disabled"
                    @mouseenter="highlightedIndex = index"
                    @click="selectOption(option)"
                >
                    <span>{{ option.text }}</span>
                    <span v-if="option.value === normalizedValue" class="text-indigo-600">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                fill-rule="evenodd"
                                d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.415 0l-3-3a1 1 0 111.414-1.42l2.293 2.29 6.793-6.79a1 1 0 011.415 0z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </span>
                </button>

                <div
                    v-if="!filteredOptions.length"
                    class="px-3 py-3 text-sm text-slate-400"
                >
                    Sin coincidencias
                </div>
            </div>
        </div>
    </div>
</template>
