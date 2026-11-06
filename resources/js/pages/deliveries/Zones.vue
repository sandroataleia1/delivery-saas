<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Trash2 } from '@lucide/vue';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    zones: { type: Array, required: true },
});

const form = useForm({ name: '', fee: '' });
const editing = ref(null);
const deleteForm = useForm({});

function edit(zone) {
    Object.assign(form, { name: zone.name, fee: zone.fee });
    form.clearErrors();
    editing.value = zone;
}

function clear() {
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    Object.assign(form, { name: '', fee: '' });
    editing.value = null;
}

function submit() {
    const options = { preserveScroll: true, onSuccess: clear };

    if (editing.value) {
        form.put(route('deliveries.zones.update', editing.value.id), options);
    } else {
        form.post(route('deliveries.zones.store'), options);
    }
}
</script>

<template>
    <AppLayout title="Bairros">
        <PageHeader title="Bairros e taxas" subtitle="Sugerem a taxa no pedido de entrega.">
            <Link :href="route('deliveries.board')" class="btn btn-secondary">
                <ArrowLeft :size="16" />
                Voltar
            </Link>
        </PageHeader>

        <form class="card mb-4 grid max-w-xl gap-4 p-5 sm:grid-cols-[1fr_8rem_auto] sm:items-end" @submit.prevent="submit">
            <Field v-slot="{ id }" label="Bairro" required :error="form.errors.name">
                <input :id="id" v-model="form.name" class="input" maxlength="100" required />
            </Field>
            <Field v-slot="{ id }" label="Taxa (R$)" required :error="form.errors.fee">
                <DecimalInput :id="id" v-model="form.fee" pad required />
            </Field>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ editing ? 'Salvar' : 'Adicionar' }}</button>
        </form>

        <EmptyState v-if="zones.length === 0" title="Nenhum bairro cadastrado" />

        <div v-else class="table-wrap max-w-xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Bairro</th>
                        <th class="text-right">Taxa</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="zone in zones" :key="zone.id">
                        <td class="font-semibold">{{ zone.name }}</td>
                        <td class="text-right tabular-nums">{{ formatMoney(zone.fee) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="btn btn-ghost" :aria-label="`Editar ${zone.name}`" @click="edit(zone)"><Pencil :size="16" /></button>
                            <button type="button" class="btn btn-ghost" :aria-label="`Excluir ${zone.name}`" @click="deleteForm.delete(route('deliveries.zones.destroy', zone.id), { preserveScroll: true })"><Trash2 :size="16" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
