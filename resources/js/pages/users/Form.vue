<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhone } from '@/lib/format';

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, required: true },
});

const editing = computed(() => props.user !== null);

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    phone: formatPhone(props.user?.phone),
    role_id: props.user?.role_id ?? '',
    password: '',
    password_confirmation: '',
});

const selectedRole = computed(() => props.roles.find((role) => role.id === form.role_id));

function submit() {
    if (editing.value) {
        form.put(route('users.update', props.user.id));
    } else {
        form.post(route('users.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar usuário' : 'Novo usuário'">
        <PageHeader :title="editing ? 'Editar usuário' : 'Novo usuário'" />

        <form class="card max-w-xl space-y-4 p-5" @submit.prevent="submit">
            <Field v-slot="{ id }" label="Nome" :error="form.errors.name">
                <input :id="id" v-model="form.name" class="input" required autofocus />
            </Field>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                    <input :id="id" v-model="form.email" class="input" type="email" autocomplete="off" required />
                </Field>

                <Field v-slot="{ id }" label="Telefone" :error="form.errors.phone">
                    <input
                        :id="id"
                        class="input"
                        inputmode="tel"
                        :value="form.phone"
                        @input="form.phone = $event.target.value = formatPhone($event.target.value)"
                    />
                </Field>
            </div>

            <Field v-slot="{ id }" label="Perfil de acesso" :error="form.errors.role_id" :hint="selectedRole?.description">
                <select :id="id" v-model="form.role_id" class="input" required>
                    <option value="" disabled>Selecione</option>
                    <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                </select>
            </Field>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field
                    v-slot="{ id }"
                    :label="editing ? 'Nova senha' : 'Senha'"
                    :error="form.errors.password"
                    :hint="editing ? 'Deixe em branco para manter a atual.' : 'Mínimo de 8 caracteres, com letras e números.'"
                >
                    <input :id="id" v-model="form.password" class="input" type="password" autocomplete="new-password" :required="!editing" />
                </Field>

                <Field v-slot="{ id }" label="Confirme a senha">
                    <input :id="id" v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" :required="!editing || form.password !== ''" />
                </Field>
            </div>

            <p class="text-muted text-sm">A pessoa entra com esta senha e pode trocá-la quando quiser em Minha conta.</p>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('users.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
