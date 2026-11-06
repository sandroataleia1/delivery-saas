<script setup>
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <GuestLayout title="Criar nova senha">
        <form class="space-y-4" @submit.prevent="form.post(route('password.update'))">
            <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                <input :id="id" v-model="form.email" class="input" type="email" autocomplete="username" required />
            </Field>

            <Field v-slot="{ id }" label="Nova senha" :error="form.errors.password" hint="Mínimo de 8 caracteres, com letras e números.">
                <input :id="id" v-model="form.password" class="input" type="password" autocomplete="new-password" required />
            </Field>

            <Field v-slot="{ id }" label="Confirme a nova senha">
                <input :id="id" v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" required />
            </Field>

            <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">Salvar senha</button>
        </form>
    </GuestLayout>
</template>
