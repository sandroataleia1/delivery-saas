<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCnpj, formatPhone } from '@/lib/format';

const props = defineProps({
    operation: { type: Object, default: null },
    company: { type: Object, default: null },
});

const block = useForm({ blocked: props.operation?.automatic_blocked ?? false, reason: '' });
const editing = computed(() => props.company !== null);

const form = useForm({
    legal_name: props.company?.legal_name ?? '',
    trade_name: props.company?.trade_name ?? '',
    document: formatCnpj(props.company?.document),
    email: props.company?.email ?? '',
    phone: formatPhone(props.company?.phone),
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

function submit() {
    if (editing.value) {
        form.put(route('admin.companies.update', props.company.id));
    } else {
        form.post(route('admin.companies.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar empresa' : 'Nova empresa'">
        <PageHeader :title="editing ? 'Editar empresa' : 'Nova empresa'" />

        <form class="max-w-xl space-y-4" @submit.prevent="submit">
            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Dados da empresa</h2>

                <Field v-slot="{ id }" label="CNPJ" :error="form.errors.document">
                    <input
                        :id="id"
                        class="input"
                        :value="form.document"
                        placeholder="00.000.000/0000-00"
                        required
                        @input="form.document = $event.target.value = formatCnpj($event.target.value)"
                    />
                </Field>

                <Field v-slot="{ id }" label="Razão social" :error="form.errors.legal_name">
                    <input :id="id" v-model="form.legal_name" class="input" required />
                </Field>

                <Field v-slot="{ id }" label="Nome fantasia" :error="form.errors.trade_name">
                    <input :id="id" v-model="form.trade_name" class="input" required />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                        <input :id="id" v-model="form.email" class="input" type="email" />
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
            </div>

            <div v-if="!editing" class="card space-y-4 p-5">
                <div>
                    <h2 class="font-bold">Primeiro administrador</h2>
                    <p class="text-muted mt-1 text-sm">Precisará trocar a senha provisória no primeiro acesso.</p>
                </div>

                <Field v-slot="{ id }" label="Nome" :error="form.errors.admin_name">
                    <input :id="id" v-model="form.admin_name" class="input" required />
                </Field>

                <Field v-slot="{ id }" label="E-mail" :error="form.errors.admin_email">
                    <input :id="id" v-model="form.admin_email" class="input" type="email" autocomplete="off" required />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Senha provisória" :error="form.errors.admin_password">
                        <input :id="id" v-model="form.admin_password" class="input" type="password" autocomplete="new-password" required />
                    </Field>

                    <Field v-slot="{ id }" label="Confirme a senha">
                        <input :id="id" v-model="form.admin_password_confirmation" class="input" type="password" autocomplete="new-password" required />
                    </Field>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('admin.companies.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
        <form v-if="editing && operation" class="card mt-6 space-y-4 p-5" @submit.prevent="block.post(route('admin.companies.operation.block', company.id), { preserveScroll: true })">
            <h2 class="font-bold">Bloqueio administrativo do modo automático</h2>
            <p class="text-muted text-sm">Preferência da empresa: {{ operation.confirmation_mode }}. Modo efetivo: manual. A liberação do bloqueio mantém o modo manual enquanto a produção automática estiver indisponível.</p>
            <label class="flex gap-2"><input v-model="block.blocked" type="checkbox" /> Bloquear automático</label>
            <Field v-slot="{ id }" label="Motivo" :error="block.errors.reason"><input :id="id" v-model="block.reason" class="input" maxlength="255" required /></Field>
            <button class="btn btn-secondary" :disabled="block.processing">Registrar decisão</button>
        </form>
    </AppLayout>
</template>
