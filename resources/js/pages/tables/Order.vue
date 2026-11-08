<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRightLeft, Ban, ChefHat, Minus, Plus, Receipt, Trash2, X } from '@lucide/vue';
import MenuReceiptAlerts from '@/components/MenuReceiptAlerts.vue';
import MenuReceiptDecisions from '@/components/MenuReceiptDecisions.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CustomerPicker from '@/components/CustomerPicker.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import PaymentPanel from '@/components/PaymentPanel.vue';
import ProductGrid from '@/components/ProductGrid.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';
import { fromCents, lineCents, newUuid, toCents } from '@/lib/money';

const props = defineProps({
    receipts: { type: Array, required: true },
    operation: { type: Object, required: true },
    order: { type: Object, required: true },
    products: { type: Array, required: true },
    categories: { type: Array, required: true },
    paymentMethods: { type: Object, required: true },
    serviceFeePercent: { type: String, required: true },
    freeTables: { type: Array, required: true },
    ticketBatch: { type: Number, default: null },
});

const page = usePage();
const { can } = usePermissions();

const lastUpdated = ref(Date.now());
usePoll(10000, { only: ['order', 'freeTables', 'receipts', 'operation'], onSuccess: () => lastUpdated.value = Date.now() }, { keepAlive: true });
const pendingIds = computed(() => props.receipts.filter(receipt => receipt.status === 'pending').map(receipt => receipt.id));

const view = ref('menu');
const drafts = ref([]);
const launchUuid = ref(newUuid());
const launchForm = useForm({ client_uuid: '', items: [] });

const activeItems = computed(() => props.order.items.filter((item) => !item.cancelled));
const draftCount = computed(() => drafts.value.length);
const subtotalCents = computed(() => toCents(props.order.subtotal));
const perPersonCents = computed(() => Math.ceil(totalCents.value / Math.max(props.order.people, 1)));

function addDraft(product, quantity = 1) {
    const line = drafts.value.find((item) => item.product_id === product.id && !item.notes);

    if (line) {
        line.quantity = String(Number(line.quantity) + quantity);
    } else {
        drafts.value.push({
            product_id: product.id,
            name: product.name,
            sale_price: product.sale_price,
            quantity: String(quantity),
            notes: '',
        });
    }
}

function changeDraft(item, delta) {
    const quantity = Number(item.quantity) + delta;

    if (quantity <= 0) {
        drafts.value = drafts.value.filter((line) => line !== item);
    } else {
        item.quantity = String(Number(quantity.toFixed(3)));
    }
}

function launch() {
    launchForm
        .transform(() => ({
            client_uuid: launchUuid.value,
            items: drafts.value.map(({ product_id, quantity, notes }) => ({ product_id, quantity, notes: notes || null })),
        }))
        .post(route('orders.items', props.order.id), {
            preserveScroll: true,
            onSuccess: () => {
                drafts.value = [];
                launchUuid.value = newUuid();
            },
        });
}

const deliveryForm = useForm({ expected_version: 0, target: 'delivered' });
function deliverItem(item) { deliveryForm.expected_version = item.production.version; deliveryForm.post(route('production.transition', item.production.id), { preserveScroll: true }); }
const kitchenForm = useForm({});
const ticket = ref(props.ticketBatch);

watch(() => props.ticketBatch, (batch) => {
    if (batch) {
        ticket.value = batch;
    }
});

function sendToKitchen() {
    kitchenForm.post(route('orders.kitchen', props.order.id), { preserveScroll: true });
}

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function startCancel(target) {
    reasonForm.reason = '';
    reasonForm.clearErrors();
    cancelling.value = target;
}

function confirmCancel() {
    const url = cancelling.value === 'order'
        ? route('orders.cancel', props.order.id)
        : route('orders.items.cancel', [props.order.id, cancelling.value.id]);

    reasonForm.post(url, {
        preserveScroll: true,
        onSuccess: () => (cancelling.value = null),
    });
}

const transferring = ref(false);
const transferForm = useForm({ dining_table_id: '' });

function startTransfer() {
    transferForm.dining_table_id = '';
    transferForm.clearErrors();
    transferring.value = true;
}

function transfer() {
    transferForm.post(route('orders.transfer', props.order.id), {
        preserveScroll: true,
        onSuccess: () => (transferring.value = false),
    });
}

const closing = ref(false);
const chargeService = ref(true);
const customer = ref(null);
const discount = ref('');
const payments = ref([]);
const paymentValid = ref(false);
const closeUuid = ref(newUuid());
const closeForm = useForm({});

const discountCents = computed(() => toCents(discount.value));
const serviceCents = computed(() => (chargeService.value
    ? Math.round(Number((((subtotalCents.value - discountCents.value) * Number(props.serviceFeePercent)) / 100).toFixed(4)))
    : 0));
const totalCents = computed(() => Math.max(subtotalCents.value - discountCents.value + serviceCents.value, 0));

function startClose() {
    closeForm.clearErrors();
    payments.value = [];
    closing.value = true;
}

function close() {
    closeForm
        .transform(() => ({
            client_uuid: closeUuid.value,
            expected_subtotal: props.order.subtotal,
            discount: discount.value || null,
            customer_id: customer.value?.id ?? null,
            charge_service: chargeService.value,
            payments: payments.value
                .filter((payment) => toCents(payment.amount) > 0)
                .map((payment) => ({ method: payment.method, amount: fromCents(toCents(payment.amount)) })),
        }))
        .post(route('orders.close', props.order.id), {
            preserveScroll: true,
            onError: () => (closeUuid.value = newUuid()),
        });
}
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden">
        <Head :title="`Mesa ${order.table}`" />
        <FlashMessages top />

        <header class="bg-sidebar flex h-14 shrink-0 items-center gap-3 px-4 text-white">
            <Link :href="route('tables.index')" class="flex items-center gap-2 text-sm font-semibold" aria-label="Voltar às mesas">
                <ArrowLeft :size="18" />
                <span class="max-sm:hidden">Mesas</span>
            </Link>
            <span class="min-w-0 flex-1 truncate text-center font-bold">Mesa {{ order.table }}</span>
            <span class="text-sidebar-text text-sm tabular-nums">Comanda #{{ order.number }}</span>
        </header>

        <div class="border-line bg-surface grid shrink-0 grid-cols-2 border-b lg:hidden">
            <button type="button" class="cursor-pointer py-3 text-sm font-semibold" :class="view === 'menu' ? 'border-brand text-brand border-b-2' : 'text-muted'" @click="view = 'menu'">
                Cardápio{{ draftCount ? ` (${draftCount})` : '' }}
            </button>
            <button type="button" class="cursor-pointer py-3 text-sm font-semibold" :class="view === 'order' ? 'border-brand text-brand border-b-2' : 'text-muted'" @click="view = 'order'">
                Comanda ({{ activeItems.length }})
            </button>
        </div>

        <div class="flex min-h-0 flex-1">
            <section class="flex min-w-0 flex-1 flex-col" :class="{ 'max-lg:hidden': view === 'order' }" aria-label="Cardápio">
                <ProductGrid :products="products" :categories="categories" @add="addDraft" />

                <div v-if="draftCount" class="border-line bg-surface max-h-[45%] shrink-0 overflow-y-auto border-t p-3">
                    <h2 class="mb-2 text-sm font-bold">A lançar</h2>
                    <ul class="space-y-2">
                        <li v-for="item in drafts" :key="`${item.product_id}-${item.name}`">
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn btn-secondary size-9 min-h-9 px-0" :aria-label="`Diminuir ${item.name}`" @click="changeDraft(item, -1)">
                                    <Minus :size="14" />
                                </button>
                                <span class="w-8 text-center text-sm font-bold tabular-nums">{{ item.quantity }}</span>
                                <button type="button" class="btn btn-secondary size-9 min-h-9 px-0" :aria-label="`Aumentar ${item.name}`" @click="changeDraft(item, 1)">
                                    <Plus :size="14" />
                                </button>
                                <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ item.name }}</span>
                                <span class="text-sm tabular-nums">{{ formatMoney(fromCents(lineCents(item.quantity, item.sale_price))) }}</span>
                            </div>
                            <input v-model="item.notes" class="input mt-1 min-h-9 py-1" maxlength="120" placeholder="Observação (sem cebola, ao ponto...)" :aria-label="`Observação de ${item.name}`" />
                        </li>
                    </ul>
                    <p v-if="launchForm.errors.order" class="text-danger mt-2 text-sm" role="alert">{{ launchForm.errors.order }}</p>
                    <button type="button" class="btn btn-primary mt-3 w-full" :disabled="launchForm.processing" @click="launch">Lançar pedido</button>
                </div>
            </section>

            <aside
                class="border-line bg-surface flex w-full shrink-0 flex-col border-l lg:w-[26rem]"
                :class="{ 'max-lg:hidden': view === 'menu' }"
                aria-label="Comanda"
            >
                <div class="border-line border-b px-4 py-3">
                    <h2 class="font-bold">Comanda</h2>
                    <p class="text-muted text-xs">{{ order.people }} {{ order.people === 1 ? 'pessoa' : 'pessoas' }} · aberta por {{ order.waiter }}</p>
                </div>

                <div class="px-4 pt-3"><MenuReceiptAlerts :ids="pendingIds" :operation="operation" :last-updated="lastUpdated" /><MenuReceiptDecisions :receipts="receipts" :late-minutes="operation.late_after_minutes" /></div>
                <p v-if="order.items.length === 0" class="text-muted flex-1 px-4 py-12 text-center text-sm">Nenhum item lançado.</p>

                <ul v-else class="divide-line min-h-0 flex-1 divide-y overflow-y-auto">
                    <li v-for="item in order.items" :key="item.id" class="flex items-start gap-2 px-4 py-2.5" :class="{ 'opacity-60': item.cancelled }">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold break-words" :class="{ 'line-through': item.cancelled }">
                                {{ formatQuantity(item.quantity) }} × {{ item.product_name }}
                            </p>
                            <p v-if="item.notes" class="text-warn text-xs">{{ item.notes }}</p>
                            <p v-if="item.production && !item.cancelled" class="text-muted text-xs">{{ item.production.delivered_at ? 'Entregue' : item.production.legacy && !item.production.reconciled_at ? 'Legado: precisa de conferência' : { waiting: 'Aguardando preparo', preparing: 'Preparando', ready: 'Pronto' }[item.production.status] }}</p>
                            <button v-if="!item.cancelled && item.production?.status === 'ready' && !item.production.delivered_at && can('production.deliver')" class="btn btn-secondary mt-1" :disabled="deliveryForm.processing" @click="deliverItem(item)">Registrar entrega</button>
                            <p v-if="item.cancelled" class="text-danger text-xs">Cancelado: {{ item.cancel_reason }}</p>
                            <p v-else class="text-muted text-xs">{{ item.sent ? 'Na cozinha' : 'Não enviado' }} · {{ item.user }}</p>
                        </div>
                        <span class="text-sm font-semibold whitespace-nowrap tabular-nums" :class="{ 'line-through': item.cancelled }">{{ formatMoney(item.total) }}</span>
                        <button
                            v-if="!item.cancelled && can('tables.cancel')"
                            type="button"
                            class="btn btn-ghost min-h-7 px-1 py-0.5"
                            :aria-label="`Cancelar ${item.product_name}`"
                            title="Cancelar item"
                            @click="startCancel(item)"
                        >
                            <X :size="16" />
                        </button>
                    </li>
                </ul>

                <div class="border-line space-y-2 border-t p-4">
                    <div class="flex items-center justify-between">
                        <span class="font-bold">Subtotal</span>
                        <span class="text-xl font-bold tabular-nums">{{ formatMoney(order.subtotal) }}</span>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" class="btn btn-secondary" :disabled="order.pending_kitchen === 0 || kitchenForm.processing" @click="sendToKitchen">
                            <ChefHat :size="16" />
                            Impressão manual{{ order.pending_kitchen ? ` (${order.pending_kitchen})` : '' }}
                        </button>
                        <button type="button" class="btn btn-secondary" :disabled="freeTables.length === 0" @click="startTransfer">
                            <ArrowRightLeft :size="16" />
                            Transferir
                        </button>
                        <button v-if="can('tables.cancel')" type="button" class="btn btn-secondary col-span-2" @click="startCancel('order')">
                            <Ban :size="16" />
                            Cancelar comanda
                        </button>
                    </div>

                    <button
                        v-if="can('tables.close')"
                        type="button"
                        class="btn btn-primary min-h-12 w-full text-base"
                        :disabled="activeItems.length === 0"
                        @click="startClose"
                    >
                        <Receipt :size="18" />
                        Fechar conta
                    </button>
                </div>
            </aside>
        </div>

        <ConfirmDialog
            :open="ticket !== null"
            title="Pedido enviado"
            message="Imprima para a cozinha."
            confirm-label="Pronto"
            cancel-label="Fechar"
            @confirm="ticket = null"
            @cancel="ticket = null"
        >
            <a v-if="ticket" :href="route('orders.ticket', [order.id, ticket])" target="_blank" class="btn btn-secondary mt-4 w-full">Imprimir pedido</a>
        </ConfirmDialog>

        <ConfirmDialog
            :open="cancelling !== null"
            :title="cancelling === 'order' ? 'Cancelar a comanda?' : `Cancelar ${cancelling?.product_name ?? ''}?`"
            :message="cancelling === 'order' ? 'A mesa é liberada sem gerar venda.' : 'O item sai da conta.'"
            confirm-label="Confirmar cancelamento"
            cancel-label="Voltar"
            danger
            :processing="reasonForm.processing"
            @confirm="confirmCancel"
            @cancel="cancelling = null"
        >
            <div class="mt-4 space-y-2">
                <Field v-slot="{ id }" label="Motivo" required :error="reasonForm.errors.reason">
                    <input :id="id" v-model="reasonForm.reason" class="input" maxlength="255" />
                </Field>
                <p v-if="reasonForm.errors.order" class="text-danger text-sm" role="alert">{{ reasonForm.errors.order }}</p>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="transferring"
            :title="`Transferir a mesa ${order.table}`"
            confirm-label="Transferir"
            :disabled="transferForm.dining_table_id === ''"
            :processing="transferForm.processing"
            @confirm="transfer"
            @cancel="transferring = false"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Mesa de destino" required :error="transferForm.errors.dining_table_id">
                    <select :id="id" v-model="transferForm.dining_table_id" class="input">
                        <option value="" disabled>Selecione</option>
                        <option v-for="table in freeTables" :key="table.id" :value="table.id">Mesa {{ table.number }}</option>
                    </select>
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="closing"
            :title="`Conta da mesa ${order.table}: ${formatMoney(fromCents(totalCents))}`"
            confirm-label="Receber e fechar"
            :disabled="!paymentValid"
            :processing="closeForm.processing"
            @confirm="close"
            @cancel="closing = false"
        >
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted">Subtotal</dt>
                    <dd class="tabular-nums">{{ formatMoney(order.subtotal) }}</dd>
                </div>
                <div v-if="can('sales.discount')" class="flex items-center justify-between gap-3">
                    <dt><label for="close-discount" class="text-muted">Desconto (R$)</label></dt>
                    <dd><DecimalInput id="close-discount" v-model="discount" class="w-28 text-right" pad /></dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="chargeService" type="checkbox" class="accent-brand size-4" />
                            Taxa de serviço ({{ Number(serviceFeePercent).toLocaleString('pt-BR') }}%)
                        </label>
                    </dt>
                    <dd class="tabular-nums">{{ formatMoney(fromCents(serviceCents)) }}</dd>
                </div>
                <div v-if="order.people > 1" class="flex justify-between">
                    <dt class="text-muted">Dividido por {{ order.people }} pessoas</dt>
                    <dd class="tabular-nums">{{ formatMoney(fromCents(perPersonCents)) }} cada</dd>
                </div>
            </dl>

            <div v-if="can('customers.view')" class="mt-3">
                <CustomerPicker v-model="customer" placeholder="Cliente (opcional, para fiado)" />
            </div>

            <PaymentPanel v-model:payments="payments" v-model:valid="paymentValid" :methods="paymentMethods" :total-cents="totalCents" :allow-credit="Boolean(customer?.allow_credit) && can('sales.credit')" />

            <p v-for="message in [closeForm.errors.order, closeForm.errors.cash, closeForm.errors.payments, closeForm.errors.discount].filter(Boolean)" :key="message" class="text-danger mt-2 text-sm" role="alert">{{ message }}</p>
            <Link v-if="closeForm.errors.cash" :href="route('cash.index')" class="text-brand mt-1 block text-sm font-semibold hover:underline">Abrir meu caixa</Link>
        </ConfirmDialog>
    </div>
</template>
