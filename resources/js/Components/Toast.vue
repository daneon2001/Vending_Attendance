<script setup>
import { computed, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'info',
    },
    title: {
        type: String,
        default: '',
    },
    message: {
        type: String,
        default: '',
    },
    duration: {
        type: Number,
        default: 5000,
    },
});

const emit = defineEmits(['close']);

let timer = null;

const typeStyles = computed(() => {
    switch (props.type) {
        case 'success':
            return {
                container: 'border-emerald-200 bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-900/40',
                title: 'text-emerald-800 dark:text-emerald-200',
            };
        case 'error':
            return {
                container: 'border-rose-200 bg-rose-50 dark:border-rose-800/50 dark:bg-rose-900/40',
                title: 'text-rose-800 dark:text-rose-200',
            };
        default:
            return {
                container: 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900',
                title: 'text-slate-900 dark:text-slate-100',
            };
    }
});

const clearTimer = () => {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
};

const startTimer = () => {
    clearTimer();

    if (props.show && props.duration > 0) {
        timer = setTimeout(() => {
            emit('close');
        }, props.duration);
    }
};

watch(
    () => [props.show, props.message, props.title, props.duration],
    () => {
        startTimer();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimer();
});
</script>

<template>
    <Teleport to="body">
        <transition name="fade">
            <div
                v-if="show"
                class="pointer-events-auto fixed left-4 right-4 top-4 z-[70] w-auto max-w-none rounded-3xl border px-4 py-3 text-sm shadow-lg sm:left-auto sm:right-6 sm:top-6 sm:w-full sm:max-w-sm"
                :class="typeStyles.container"
            >
                <div class="flex items-start gap-3">
                    <div class="flex-1">
                        <p class="text-base font-semibold" :class="typeStyles.title">
                            {{ title }}
                        </p>
                        <p class="mt-1 break-words text-slate-600 dark:text-slate-200">
                            {{ message }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-full p-1 text-slate-400 transition hover:text-slate-700 dark:text-slate-500 dark:hover:text-slate-200"
                        aria-label="Cerrar notificacion"
                        @click="emit('close')"
                    >
                        x
                    </button>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}
</style>
