<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Ban, Printer, Wallet } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaymentPanel from '@/components/PaymentPanel.vue';
import SaleCompleteDialog from '@/components/SaleCompleteDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';
import { fromCents, newUuid, toCents } from '@/lib/money';

const props = defineProps({
    order: { type: Object, required: true },
    couriers: { type: Array, required: true },
    paymentMethods: { type: Object, required: true },
    completed: { type: Object, default: null },
});

const { can } = usePermissions();
const receipt = ref(props.completed);

watch(() => props.completed, (sale) => {
    if (sale) {
        receipt.value = sale;
    }
});

const canSettle = computed(() => props.order.status === 'delivered' && !props.order.settled && can('deliveries.settle'));
const canCancel = computed(() => !props.order.settled && props.order.status !== 'cancelled' && can('deliveries.cancel'));

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });

function cancelOrder() {
    cancelForm.post(route('deliveries.cancel', props.order.id), {
        preserveScroll: true,
        onSuccess: () => (cancelling.value = false),
    });
}

const settling = ref(false);
const discount = ref('');
const payments = ref([]);
const paymentValid = ref(false);
const settleUuid = ref(newUuid());
const settleForm = useForm({});

const allowCredit = computed(() => props.order.payment_method === 'fiado' || can('sales.credit'));
const totalCents = computed(() => Math.max(toCents(props.order.total) - toCents(discount.value), 0));

function startSettle() {
    settleForm.clearErrors();
    payments.value = [{ method: props.order.payment_method, amount: fromCents(totalCents.value) }];
    settling.value = true;
}

function settle() {
    settleForm
        .transform(() => ({
            client_uuid: settleUuid.value,
            discount: discount.value || null,
            payments: payments.value
                .filter((payment) => toCents(payment.amount) > 0)
                .map((payment) => ({ method: payment.method, amount: fromCents(toCents(payment.amount)) })),
        }))
        .post(route('deliveries.settle', props.order.id), {
            preserveScroll: true,
            onSuccess: () => (settling.value = false),
            onError: () => (settleUuid.value = newUuid()),
        });
}
</script>

<template>
    <AppLayout :title="`Pedido #${order.number}`">
        <PageHeader :title="`Pedido #${order.number}`" :subtitle="`${order.type_label} · ${order.status_label} · ${order.date}`">
            <a :href="route('deliveries.ticket', order.id)" target="_blank" class="btn btn-secondary">
                <Printer :size="16" />
                Imprimir
            </a>
            <button v-if="canCancel" type="button" class="btn btn-secondary" @click="cancelling = true">
                <Ban :size="16" />
                Cancelar pedido
            </button>
            <button v-if="canSettle" type="button" class="btn btn-primary" @click="startSettle">
                <Wallet :size="16" />
                Acertar
            </button>
        </PageHeader>

        <div v-if="order.status === 'cancelled'" class="bg-danger-soft text-danger mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm">
            <p class="font-semibold">Cancelado por {{ order.cancelled_by }}</p>
            <p>Motivo: {{ order.cancel_reason }}</p>
        </div>

        <p v-if="order.settled" class="bg-ok-soft text-ok mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm font-semibold">
            Acertado no caixa.
            <Link :href="route('sales.show', order.sale_id)" class="underline">Ver venda</Link>
        </p>

        <dl class="card mb-4 grid max-w-3xl gap-4 p-5 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted">Cliente</dt>
                <dd class="font-semibold"><Link :href="route('customers.show', order.customer_id)" class="text-brand hover:underline">{{ order.customer }}</Link></dd>
            </div>
            <div>
                <dt class="text-muted">Pagamento previsto</dt>
                <dd class="font-semibold">{{ order.payment }}<template v-if="order.change_for"> · troco para {{ formatMoney(order.change_for) }}</template></dd>
            </div>
            <div v-if="order.address">
                <dt class="text-muted">Endereço</dt>
                <dd class="font-semibold">{{ order.address }}</dd>
                <dd v-if="order.reference" class="text-muted text-xs">{{ order.reference }}</dd>
            </div>
            <div v-if="order.courier">
                <dt class="text-muted">Entregador</dt>
                <dd class="font-semibold">{{ order.courier }}</dd>
            </div>
            <div v-if="order.notes" class="sm:col-span-2">
                <dt class="text-muted">Observações</dt>
                <dd>{{ order.notes }}</dd>
            </div>
        </dl>

        <div class="table-wrap max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in order.items" :key="item.id">
                        <td>
                            <p class="font-semibold">{{ item.product_name }}</p>
                            <p v-if="item.notes" class="text-warn text-xs">{{ item.notes }}</p>
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(item.quantity, item.unit) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(item.total) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-muted">Taxa de entrega</td>
                        <td class="text-right tabular-nums">{{ formatMoney(order.delivery_fee) }}</td>
                    </tr>
                    <tr>
                        <td colspan="2" class="font-bold">Total</td>
                        <td class="text-right font-bold tabular-nums">{{ formatMoney(order.total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <ConfirmDialog
            :open="cancelling"
            :title="`Cancelar o pedido #${order.number}?`"
            confirm-label="Confirmar cancelamento"
            cancel-label="Voltar"
            danger
            :processing="cancelForm.processing"
            @confirm="cancelOrder"
            @cancel="cancelling = false"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Motivo" required :error="cancelForm.errors.reason">
                    <input :id="id" v-model="cancelForm.reason" class="input" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="settling"
            :title="`Acerto do pedido #${order.number}: ${formatMoney(fromCents(totalCents))}`"
            confirm-label="Registrar acerto"
            :disabled="!paymentValid"
            :processing="settleForm.processing"
            @confirm="settle"
            @cancel="settling = false"
        >
            <div v-if="can('sales.discount')" class="mt-4 flex items-center justify-between gap-3 text-sm">
                <label for="settle-discount" class="text-muted">Desconto (R$)</label>
                <DecimalInput id="settle-discount" v-model="discount" class="w-28 text-right" pad />
            </div>
            <PaymentPanel v-model:payments="payments" v-model:valid="paymentValid" :methods="paymentMethods" :total-cents="totalCents" :allow-credit="allowCredit" />
            <p v-for="message in [settleForm.errors.order, settleForm.errors.cash, settleForm.errors.payments, settleForm.errors.discount].filter(Boolean)" :key="message" class="text-danger mt-2 text-sm" role="alert">{{ message }}</p>
            <Link v-if="settleForm.errors.cash" :href="route('cash.index')" class="text-brand mt-1 block text-sm font-semibold hover:underline">Abrir meu caixa</Link>
        </ConfirmDialog>

        <SaleCompleteDialog :sale="receipt" confirm-label="Pronto" @close="receipt = null" />
    </AppLayout>
</template>
