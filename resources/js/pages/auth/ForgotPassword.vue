<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ email: '' });
</script>

<template>
    <GuestLayout title="Esqueci a senha" subtitle="Enviaremos um link para você criar uma nova senha.">
        <p v-if="status" class="bg-ok-soft text-ok mb-4 rounded-lg px-3 py-2 text-sm font-medium">{{ status }}</p>

        <form class="space-y-4" @submit.prevent="form.post(route('password.email'))">
            <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                <input :id="id" v-model="form.email" class="input" type="email" autocomplete="username" required autofocus />
            </Field>

            <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">Enviar link</button>
        </form>

        <p class="mt-4 text-center text-sm">
            <Link :href="route('login')" class="text-brand font-semibold hover:underline">Voltar para o login</Link>
        </p>
    </GuestLayout>
</template>
