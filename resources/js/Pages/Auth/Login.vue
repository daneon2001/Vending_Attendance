<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Iniciar sesión" />

        <div
            v-if="status"
            class="mb-6 rounded-2xl border border-[#c3ebd4] bg-[#e8f9ef] px-4 py-3 text-sm font-medium text-[#1f7a3a]"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <div class="space-y-2">
                <label for="email" class="text-xs font-semibold uppercase tracking-[0.4em] text-[#0F3F6F]">
                    Correo corporativo
                </label>
                <TextInput
                    id="email"
                    type="email"
                    class="block w-full rounded-2xl border border-[#d0ddea] bg-[#f9fcff] px-5 py-3 text-base text-[#0F3F6F] shadow-sm focus:border-[#36A144] focus:ring-2 focus:ring-[#36A144]"
                    placeholder="admin@medical.life"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <InputError class="text-sm text-rose-600" :message="form.errors.email" />
            </div>

            <div class="space-y-2">
                <label
                    for="password"
                    class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.4em] text-[#0F3F6F]"
                >
                    Contraseña
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-[11px] font-semibold tracking-normal text-[#0F3F6F] hover:text-[#36A144]"
                    >
                        ¿Olvidaste tu contraseña?
                    </Link>
                </label>
                <TextInput
                    id="password"
                    type="password"
                    class="block w-full rounded-2xl border border-[#d0ddea] bg-[#f9fcff] px-5 py-3 text-base text-[#0F3F6F] shadow-sm focus:border-[#36A144] focus:ring-2 focus:ring-[#36A144]"
                    placeholder="••••••••"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />
                <InputError class="text-sm text-rose-600" :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center text-sm text-slate-500">
                    <Checkbox
                        name="remember"
                        v-model:checked="form.remember"
                        class="rounded-md border-slate-300 text-[#36A144] focus:ring-[#155F88]"
                    />
                    <span class="ms-2 text-sm text-[#0F3F6F]">Mantener sesión activa</span>
                </label>
                <span class="text-xs uppercase tracking-[0.4em] text-[#36A144]">Medical Life ID</span>
            </div>

            <div>
                <PrimaryButton
                    class="relative w-full justify-center rounded-2xl bg-gradient-to-r from-[#0F3F6F] to-[#36A144] py-3 text-base font-semibold tracking-wide text-white shadow-lg shadow-[#0F3F6F]/30 hover:from-[#155F88] hover:to-[#40B052] focus:ring-2 focus:ring-[#36A144]/40"
                    :class="{ 'opacity-50': form.processing }"
                    :disabled="form.processing"
                >
                    Ingresar
                </PrimaryButton>
            </div>
        </form>

        <div class="mt-10 grid gap-4 text-sm text-slate-500 sm:grid-cols-2">
            <div class="rounded-2xl border border-[#d0ddea] bg-[#f9fcff] p-5">
                <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-[#36A144]">RRHH</p>
                <p class="mt-2 text-2xl font-semibold text-[#0F3F6F]">Portal de Registro</p>
                <p class="mt-1 text-sm">Solicitudes de registro de asistencia.</p>
            </div>
            <div class="rounded-2xl border border-[#d0ddea] bg-[#fefaf4] p-5">
                <p class="text-[11px] font-semibold uppercase tracking-[0.4em] text-[#FF8A00]">Operación</p>
                <p class="mt-2 text-2xl font-semibold text-[#0F3F6F]">Tableros en vivo</p>
                <p class="mt-1 text-sm">
                    Sesiones firmadas con Sanctum, alertas de dispositivos y auditoría minuto a minuto.
                </p>
            </div>
        </div>
    </GuestLayout>
</template>
