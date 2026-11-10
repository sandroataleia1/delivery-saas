<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps({
    couriers: { type: Array, required: true },
    users: { type: Array, required: true },
});

const blank = { name: '', phone: '', user_id: '', active: true };
const form = useForm({ ...blank });
const editing = ref(null);

function edit(courier) {
    Object.assign(form, { name: courier.name, phone: courier.phone ?? '', user_id: courier.user_id ?? '', active: courier.active });
    form.clearErrors();
    editing.value = courier;
}

function clear() {
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    Object.assign(form, blank);
    form.clearErrors();
    editing.value = null;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: clear };
    const send = form.transform((data) => ({ ...data, phone: data.phone || null, user_id: data.user_id || null }));

    if (editing.value) {
        send.put(route('couriers.update', editing.value.id), options);
    } else {
        send.post(route('couriers.store'), options);
    }
}

function toggle(courier) {
    router.put(
        route('couriers.update', courier.id),
        { name: courier.name, phone: courier.phone, user_id: courier.user_id, active: !courier.active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <AppLayout title="Entregadores">
        <PageHeader title="Entregadores" subtitle="Quem leva as entregas. Escolha um deles ao despachar o pedido.">
            <Link :href="route('deliveries.board')" class="btn btn-secondary">
                <ArrowLeft :size="16" />
                Voltar
            </Link>
        </PageHeader>

        <form class="card mb-4 grid max-w-3xl gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit">
            <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                <input :id="id" v-model="form.name" class="input" maxlength="80" required />
            </Field>
            <Field v-slot="{ id }" label="Telefone" :error="form.errors.phone">
                <input :id="id" v-model="form.phone" class="input" maxlength="20" inputmode="tel" />
            </Field>
            <Field
                v-slot="{ id }"
                label="Login no sistema (opcional)"
                :error="form.errors.user_id"
                hint="Com login, o entregador vê as próprias entregas no celular em Minhas entregas."
            >
                <select :id="id" v-model="form.user_id" class="input">
                    <option value="">Sem login</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>
            </Field>
            <label class="flex items-center gap-2 self-center text-sm">
                <input v-model="form.active" type="checkbox" class="size-4" />
                Ativo (aparece ao despachar)
            </label>
            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ editing ? 'Salvar' : 'Adicionar' }}</button>
                <button v-if="editing" type="button" class="btn btn-secondary" @click="clear">Cancelar edição</button>
            </div>
        </form>

        <EmptyState v-if="couriers.length === 0" title="Nenhum entregador cadastrado" text="Cadastre quem faz as entregas para escolher ao despachar." />

        <div v-else class="table-wrap max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th class="max-sm:hidden">Telefone</th>
                        <th class="max-md:hidden">Login</th>
                        <th>Situação</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="courier in couriers" :key="courier.id" :class="{ 'text-muted': !courier.active }">
                        <td class="font-semibold">{{ courier.name }}</td>
                        <td class="whitespace-nowrap max-sm:hidden">{{ courier.phone ?? '—' }}</td>
                        <td class="max-md:hidden">{{ courier.user ?? 'Sem login' }}</td>
                        <td>
                            <button type="button" class="btn btn-ghost min-h-8 px-2 py-1 text-xs" :aria-pressed="courier.active" @click="toggle(courier)">
                                {{ courier.active ? 'Ativo' : 'Inativo' }}
                            </button>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="btn btn-ghost" :aria-label="`Editar ${courier.name}`" @click="edit(courier)"><Pencil :size="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
