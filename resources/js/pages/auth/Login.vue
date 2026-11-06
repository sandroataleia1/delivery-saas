<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <GuestLayout title="Entrar" subtitle="Acesse a gestão da sua loja.">
        <p v-if="status" class="bg-ok-soft text-ok mb-4 rounded-lg px-3 py-2 text-sm font-medium">{{ status }}</p>

        <form class="space-y-4" @submit.prevent="submit">
            <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                <input :id="id" v-model="form.email" class="input" type="email" autocomplete="username" required autofocus />
            </Field>

            <Field v-slot="{ id }" label="Senha" :error="form.errors.password">
                <input :id="id" v-model="form.password" class="input" type="password" autocomplete="current-password" required />
            </Field>

            <div class="flex items-center justify-between gap-3 text-sm">
                <label class="flex cursor-pointer items-center gap-2">
                    <input v-model="form.remember" type="checkbox" class="accent-brand size-4" />
                    Manter conectado
                </label>
                <Link :href="route('password.request')" class="text-brand font-semibold hover:underline">Esqueci a senha</Link>
            </div>

            <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">Entrar</button>
        </form>

        <p class="text-muted mt-6 text-center text-sm">
            Ainda não tem conta?
            <Link :href="route('register')" class="text-brand font-semibold hover:underline">Cadastre sua loja</Link>
        </p>
    </GuestLayout>
</template>
