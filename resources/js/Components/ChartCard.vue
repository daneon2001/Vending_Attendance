<script setup>
import ChartEmptyState from '@/Components/ChartEmptyState.vue';
import { hasChartData } from '@/utils/chart';
import Chart from 'chart.js/auto';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'line',
    },
    dataset: {
        type: Object,
        default: () => ({}),
    },
    options: {
        type: Object,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: null,
    },
    hasData: {
        type: [Boolean, null],
        default: null,
    },
    chartKey: {
        type: Number,
        default: 0,
    },
    emptyText: {
        type: String,
        default: 'Sin información disponible',
    },
});

const emit = defineEmits(['point-click']);

const canvasRef = ref(null);
const chartContainerRef = ref(null);
let chartInstance = null;
let resizeObserver = null;
let resizeRaf = null;

const destroyChart = () => {
    if (chartInstance) {
        chartInstance.destroy();
        chartInstance = null;
    }
};

const scheduleChartResize = () => {
    if (typeof window === 'undefined' || !chartInstance) return;

    if (resizeRaf) {
        window.cancelAnimationFrame(resizeRaf);
    }

    resizeRaf = window.requestAnimationFrame(() => {
        chartInstance?.resize();
        resizeRaf = null;
    });
};

const buildOptions = () => {
    const isDark = document.documentElement.classList.contains('dark');
    const axisColor = isDark ? '#cbd5f5' : '#475569';
    const gridColor = isDark ? 'rgba(148, 163, 184, 0.2)' : 'rgba(148, 163, 184, 0.3)';

    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index',
        },
        plugins: {
            legend: {
                labels: {
                    color: axisColor,
                },
            },
            tooltip: {
                enabled: true,
            },
        },
        onClick: (event, elements) => {
            if (!elements?.length || !chartInstance) return;
            const [{ datasetIndex, index }] = elements;
            const dataset = chartInstance.data.datasets[datasetIndex];
            const point = {
                datasetIndex,
                index,
                label: chartInstance.data.labels?.[index] ?? '',
                value: dataset?.data?.[index] ?? 0,
                datasetLabel: dataset?.label ?? '',
            };
            emit('point-click', point);
        },
        ...props.options,
    };

    if (!['doughnut', 'pie', 'polarArea'].includes(props.type)) {
        baseOptions.scales = {
            x: {
                ticks: { color: axisColor },
                grid: { color: gridColor },
            },
            y: {
                ticks: { color: axisColor },
                grid: { color: gridColor },
            },
        };
    }

    return baseOptions;
};

const internalHasData = computed(() =>
    typeof props.hasData === 'boolean' ? props.hasData : hasChartData(props.dataset),
);

const renderChart = () => {
    destroyChart();

    if (props.loading || !internalHasData.value) {
        return;
    }

    nextTick(() => {
        if (!canvasRef.value || props.loading || !internalHasData.value) {
            return;
        }

        const config = {
            type: props.type,
            data: props.dataset,
            options: buildOptions(),
        };

        chartInstance = new Chart(canvasRef.value, config);
    });
};

watch(
    [() => props.dataset, () => props.type, () => props.options, () => props.loading, () => props.chartKey, () => internalHasData.value],
    () => {
        renderChart();
    },
    { deep: true },
);

onMounted(() => {
    renderChart();

    if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
            scheduleChartResize();
        });

        if (chartContainerRef.value) {
            resizeObserver.observe(chartContainerRef.value);
        }
    }
});

onBeforeUnmount(() => {
    if (resizeObserver) {
        resizeObserver.disconnect();
        resizeObserver = null;
    }

    if (resizeRaf && typeof window !== 'undefined') {
        window.cancelAnimationFrame(resizeRaf);
        resizeRaf = null;
    }

    destroyChart();
});
</script>

<template>
    <article class="card flex h-full flex-col">
        <div class="flex-1 p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">
                        {{ description }}
                    </p>
                    <h3 class="text-lg font-semibold text-app">
                        {{ title }}
                    </h3>
                </div>
            </header>

            <div ref="chartContainerRef" class="mt-6 h-56 sm:h-64 lg:h-72">
                <div
                    v-if="loading"
                    class="h-full rounded-2xl bg-slate-100/70 animate-pulse dark:bg-slate-800/60"
                />
                <div
                    v-else-if="error"
                    class="flex h-full items-center justify-center rounded-2xl border border-dashed border-rose-200 px-4 text-center text-sm text-rose-600 dark:border-rose-500/40 dark:text-rose-100"
                >
                    {{ error }}
                </div>
                <ChartEmptyState
                    v-else-if="!internalHasData"
                    :message="emptyText"
                />
                <canvas
                    v-else
                    :key="chartKey"
                    ref="canvasRef"
                    class="h-full w-full"
                />
            </div>
        </div>
    </article>
</template>
