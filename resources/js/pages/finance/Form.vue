<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    type: { type: String, required: true },
    bill: { type: Object, default: null },
    categories: { type: Array, required: true },
    suppliers: { type: Array, required: true },
});

const editing = computed(() => props.bill !== null);
const isPayable = computed(() => props.type === 'pagar');
const title = computed(() => {
    const label = isPayable.value ? 'conta a pagar' : 'conta a receber';

    return editing.value ? `Editar ${label}` : `Nova ${label}`;
});

const form = useForm({
    description: props.bill?.description ?? '',
    category: props.bill?.category ?? '',
    supplier_id: props.bill?.supplier_id ?? '',
    counterparty: props.bill?.counterparty ?? '',
    amount: props.bill?.amount ?? '',
    due_date: props.bill?.due_date ?? '',
    installments: 1,
    notes: props.bill?.notes ?? '',
});

const installmentValue = computed(() => Number(form.amount || 0) / Math.max(Number(form.installments) || 1, 1));

function submit() {
    if (editing.value) {
        form.put(route('bills.update', props.bill.id));
    } else {
        form.post(route('bills.store', props.type));
    }
}
</script>

<template>
    <AppLayout :title="title">
        <PageHeader :title="title" :subtitle="bill?.installment_label" />

        <form class="card max-w-xl space-y-4 p-5" @submit.prevent="submit">
            <Field v-slot="{ id }" label="Descrição" required :error="form.errors.description">
                <input :id="id" v-model="form.description" class="input" maxlength="150" required autofocus />
            </Field>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field v-slot="{ id }" label="Categoria" required :error="form.errors.category">
                    <select :id="id" v-model="form.category" class="input" required>
                        <option value="" disabled>Selecione</option>
                        <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                    </select>
                </Field>

                <Field v-if="isPayable" v-slot="{ id }" label="Fornecedor" :error="form.errors.supplier_id">
                    <select :id="id" v-model="form.supplier_id" class="input">
                        <option value="">Sem fornecedor</option>
                        <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                    </select>
                </Field>

                <Field v-else v-slot="{ id }" label="Cliente" :error="form.errors.counterparty">
                    <input :id="id" v-model="form.counterparty" class="input" maxlength="120" />
                </Field>

                <Field v-slot="{ id }" :label="editing ? 'Valor (R$)' : 'Valor total (R$)'" required :error="form.errors.amount">
                    <DecimalInput :id="id" v-model="form.amount" pad required />
                </Field>

                <Field v-slot="{ id }" :label="editing ? 'Vencimento' : 'Primeiro vencimento'" required :error="form.errors.due_date">
                    <input :id="id" v-model="form.due_date" class="input" type="date" required />
                </Field>

                <Field
                    v-if="!editing"
                    v-slot="{ id }"
                    label="Parcelas"
                    :error="form.errors.installments"
                    :hint="Number(form.installments) > 1 ? `${form.installments}x de ${formatMoney(installmentValue)}, mês a mês` : null"
                >
                    <input :id="id" v-model.number="form.installments" class="input" type="number" min="1" max="60" />
                </Field>
            </div>

            <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                <input :id="id" v-model="form.notes" class="input" maxlength="255" />
            </Field>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('bills.index', type)" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
