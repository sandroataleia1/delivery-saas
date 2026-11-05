<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban, Printer } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    sale: { type: Object, required: true },
});

const { can } = usePermissions();
const confirming = ref(false);
const form = useForm({ reason: '', sale: null });
const margin = computed(() => Number(props.sale.total) - Number(props.sale.cost));

function cancelSale() {
    form.post(route('sales.cancel', props.sale.id), {
        preserveScroll: true,
        onSuccess: () => (confirming.value = false),
    });
}
</script>

<template>
    <AppLayout :title="`Venda #${sale.number}`">
        <PageHeader :title="`Venda #${sale.number}`" :subtitle="`${sale.table_number ? `Mesa ${sale.table_number} · ` : ''}${sale.user} em ${sale.date}`">
            <a :href="route('sales.receipt', sale.id)" target="_blank" class="btn btn-secondary">
                <Printer :size="16" />
                Imprimir cupom
            </a>
            <button v-if="!sale.cancelled && can('sales.cancel')" type="button" class="btn btn-secondary" @click="confirming = true">
                <Ban :size="16" />
                Cancelar venda
            </button>
        </PageHeader>

        <div v-if="sale.cancelled" class="bg-danger-soft text-danger mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm">
            <p class="font-semibold">Cancelada por {{ sale.cancelled_by }} em {{ sale.cancelled_at }}</p>
            <p>Motivo: {{ sale.cancel_reason }}</p>
        </div>

        <div class="table-wrap mb-4 max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right max-sm:hidden">Preço</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in sale.items" :key="item.id">
                        <td class="font-semibold">{{ item.product_name }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(item.quantity, item.unit) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatMoney(item.unit_price) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(item.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
            <dl class="card space-y-1 p-5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted">Subtotal</dt>
                    <dd class="tabular-nums">{{ formatMoney(sale.subtotal) }}</dd>
                </div>
                <div v-if="Number(sale.discount) > 0" class="flex justify-between">
                    <dt class="text-muted">Desconto</dt>
                    <dd class="tabular-nums">− {{ formatMoney(sale.discount) }}</dd>
                </div>
                <div v-if="Number(sale.service_fee) > 0" class="flex justify-between">
                    <dt class="text-muted">Taxa de serviço</dt>
                    <dd class="tabular-nums">{{ formatMoney(sale.service_fee) }}</dd>
                </div>
                <div class="flex justify-between text-base font-bold">
                    <dt>Total</dt>
                    <dd class="tabular-nums">{{ formatMoney(sale.total) }}</dd>
                </div>
                <template v-if="sale.cost !== null">
                    <div class="border-line mt-2 flex justify-between border-t pt-2">
                        <dt class="text-muted">Custo</dt>
                        <dd class="tabular-nums">{{ formatMoney(sale.cost) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted">Margem</dt>
                        <dd class="font-semibold tabular-nums">{{ formatMoney(margin) }}</dd>
                    </div>
                </template>
            </dl>

            <dl class="card space-y-1 p-5 text-sm">
                <div v-for="payment in sale.payments" :key="payment.id" class="flex justify-between">
                    <dt class="text-muted">{{ payment.label }}</dt>
                    <dd class="tabular-nums">{{ formatMoney(payment.received) }}</dd>
                </div>
                <div v-if="Number(sale.change) > 0" class="flex justify-between">
                    <dt class="text-muted">Troco dado</dt>
                    <dd class="tabular-nums">{{ formatMoney(sale.change) }}</dd>
                </div>
            </dl>
        </div>

        <ConfirmDialog
            :open="confirming"
            :title="`Cancelar a venda #${sale.number}?`"
            message="O estoque baixado por esta venda é devolvido."
            confirm-label="Cancelar venda"
            danger
            :processing="form.processing"
            @confirm="cancelSale"
            @cancel="confirming = false"
        >
            <div class="mt-4 space-y-3">
                <Field v-slot="{ id }" label="Motivo" required :error="form.errors.reason">
                    <input :id="id" v-model="form.reason" class="input" maxlength="255" />
                </Field>
                <p v-if="form.errors.sale" class="text-danger text-sm" role="alert">{{ form.errors.sale }}</p>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
