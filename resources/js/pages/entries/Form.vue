<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    suppliers: { type: Array, required: true },
    today: { type: String, required: true },
});

const form = useForm({
    supplier_id: '',
    invoice_number: '',
    entry_date: props.today,
    notes: '',
    items: [],
    generate_bill: false,
    bill_due_date: '',
    bill_installments: 1,
});

const { can } = usePermissions();

const lineTotal = (item) => Number(item.quantity || 0) * Number(item.unit_cost || 0);
const total = computed(() => form.items.reduce((sum, item) => sum + lineTotal(item), 0));

function addProduct(product) {
    if (form.items.some((item) => item.product_id === product.id)) {
        return;
    }

    form.items.push({
        product_id: product.id,
        name: product.name,
        unit: product.unit,
        quantity: '',
        unit_cost: String(Number(product.cost_price)),
    });
}

function removeItem(index) {
    form.items.splice(index, 1);
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            items: data.items.map(({ product_id, quantity, unit_cost }) => ({ product_id, quantity, unit_cost })),
        }))
        .post(route('entries.store'));
}
</script>

<template>
    <AppLayout title="Nova entrada">
        <PageHeader title="Nova entrada de mercadorias" subtitle="Ao salvar, o estoque e o custo médio são atualizados." />

        <form class="max-w-4xl space-y-4" @submit.prevent="submit">
            <div class="card grid gap-4 p-5 sm:grid-cols-3">
                <Field v-slot="{ id }" label="Fornecedor" :error="form.errors.supplier_id">
                    <select :id="id" v-model="form.supplier_id" class="input">
                        <option value="">Sem fornecedor</option>
                        <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                    </select>
                </Field>

                <Field v-slot="{ id }" label="Número da nota" :error="form.errors.invoice_number">
                    <input :id="id" v-model="form.invoice_number" class="input" maxlength="44" />
                </Field>

                <Field v-slot="{ id }" label="Data da entrada" required :error="form.errors.entry_date">
                    <input :id="id" v-model="form.entry_date" class="input" type="date" :max="today" required />
                </Field>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Produtos</h2>

                <ProductPicker @select="addProduct" />

                <p v-if="form.errors.items" class="text-danger text-sm" role="alert">{{ form.errors.items }}</p>

                <EmptyState v-if="form.items.length === 0" title="Nenhum produto adicionado" text="Busque acima ou use o leitor de código de barras." />

                <ul v-else class="divide-line divide-y">
                    <li
                        v-for="(item, index) in form.items"
                        :key="item.product_id"
                        class="grid grid-cols-2 items-start gap-3 py-3 sm:grid-cols-[1fr_7.5rem_7.5rem_6.5rem_2.5rem]"
                    >
                        <div class="col-span-2 min-w-0 sm:col-span-1 sm:pt-7">
                            <p class="truncate text-sm font-semibold">{{ item.name }}</p>
                            <p v-if="form.errors[`items.${index}.product_id`]" class="text-danger text-sm" role="alert">
                                {{ form.errors[`items.${index}.product_id`] }}
                            </p>
                        </div>

                        <Field v-slot="{ id }" :label="`Qtd. (${item.unit})`" required :error="form.errors[`items.${index}.quantity`]">
                            <DecimalInput :id="id" v-model="item.quantity" :decimals="3" required />
                        </Field>

                        <Field v-slot="{ id }" label="Custo unit." required :error="form.errors[`items.${index}.unit_cost`]">
                            <DecimalInput :id="id" v-model="item.unit_cost" :decimals="4" required />
                        </Field>

                        <div class="sm:pt-7">
                            <p class="text-muted text-xs sm:hidden">Total</p>
                            <p class="py-2 text-sm font-semibold whitespace-nowrap tabular-nums sm:text-right">{{ formatMoney(lineTotal(item)) }}</p>
                        </div>

                        <div class="flex justify-end sm:pt-7">
                            <button type="button" class="btn btn-ghost" :aria-label="`Remover ${item.name}`" title="Remover" @click="removeItem(index)">
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </li>
                </ul>

                <div class="border-line flex items-center justify-between border-t pt-4">
                    <span class="font-bold">Total da entrada</span>
                    <span class="text-xl font-bold tabular-nums">{{ formatMoney(total) }}</span>
                </div>
            </div>

            <div v-if="can('finance.manage')" class="card space-y-4 p-5">
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.generate_bill" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Gerar conta a pagar</span>
                        <span class="text-muted">Lança o total desta entrada no financeiro.</span>
                    </span>
                </label>

                <div v-if="form.generate_bill" class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Primeiro vencimento" required :error="form.errors.bill_due_date">
                        <input :id="id" v-model="form.bill_due_date" class="input" type="date" required />
                    </Field>
                    <Field v-slot="{ id }" label="Parcelas" :error="form.errors.bill_installments">
                        <input :id="id" v-model.number="form.bill_installments" class="input" type="number" min="1" max="60" />
                    </Field>
                </div>
            </div>

            <div class="card p-5">
                <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                    <textarea :id="id" v-model="form.notes" class="input" rows="2" maxlength="500" />
                </Field>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing || form.items.length === 0">Lançar entrada</button>
                <Link :href="route('entries.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
