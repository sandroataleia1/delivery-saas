<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhone } from '@/lib/format';

const props = defineProps({
    account: { type: Object, required: true },
});

const page = usePage();

const profile = useForm({ name: props.account.name, phone: formatPhone(props.account.phone) });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function savePassword() {
    password.put(route('account.password'), {
        preserveScroll: true,
        onSuccess: () => password.reset(),
        onError: () => password.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout title="Minha conta">
        <PageHeader title="Minha conta" :subtitle="account.email" />

        <p
            v-if="page.props.auth.user.must_change_password"
            class="bg-warn-soft text-warn mb-6 max-w-xl rounded-lg px-4 py-3 text-sm font-medium"
        >
            Sua senha é provisória. Defina uma nova senha para usar o sistema.
        </p>

        <div class="grid max-w-xl gap-6">
            <form
                v-if="!page.props.auth.user.must_change_password"
                class="card space-y-4 p-5"
                @submit.prevent="profile.put(route('account.update'), { preserveScroll: true })"
            >
                <h2 class="font-bold">Meus dados</h2>

                <Field v-slot="{ id }" label="Nome" :error="profile.errors.name">
                    <input :id="id" v-model="profile.name" class="input" required />
                </Field>

                <Field v-slot="{ id }" label="Telefone" :error="profile.errors.phone">
                    <input
                        :id="id"
                        class="input"
                        inputmode="tel"
                        :value="profile.phone"
                        @input="profile.phone = $event.target.value = formatPhone($event.target.value)"
                    />
                </Field>

                <button type="submit" class="btn btn-primary" :disabled="profile.processing">Salvar</button>
            </form>

            <form class="card space-y-4 p-5" @submit.prevent="savePassword">
                <h2 class="font-bold">Alterar senha</h2>

                <Field v-slot="{ id }" label="Senha atual" :error="password.errors.current_password">
                    <input :id="id" v-model="password.current_password" class="input" type="password" autocomplete="current-password" required />
                </Field>

                <Field v-slot="{ id }" label="Nova senha" :error="password.errors.password" hint="Mínimo de 8 caracteres, com letras e números.">
                    <input :id="id" v-model="password.password" class="input" type="password" autocomplete="new-password" required />
                </Field>

                <Field v-slot="{ id }" label="Confirme a nova senha">
                    <input :id="id" v-model="password.password_confirmation" class="input" type="password" autocomplete="new-password" required />
                </Field>

                <button type="submit" class="btn btn-primary" :disabled="password.processing">Alterar senha</button>
            </form>
        </div>
    </AppLayout>
</template>
