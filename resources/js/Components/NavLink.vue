<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-indigo-400 text-sm font-medium leading-5 text-gray-900 transition duration-150 ease-in-out focus:border-indigo-700 focus:outline-none dark:text-slate-100'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 hover:border-gray-300 focus:text-gray-700 focus:border-gray-300 focus:outline-none dark:text-slate-400 dark:hover:text-slate-200 dark:hover:border-slate-600 dark:focus:text-slate-200 dark:focus:border-slate-600',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
