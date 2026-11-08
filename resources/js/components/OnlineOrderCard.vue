<script setup>
import { computed, onUnmounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    order: { type: Object, required: true },
    paymentMethods: { type: Object, default: () => ({}) },
    decided: { type: Boolean, default: false },
});

const now = ref(Date.now());
const timer = props.decided ? null : setInterval(() => (now.value = Date.now()), 1000);
onUnmounted(() => timer && clearInterval(timer));

const remaining = computed(() => Math.max(0, Math.floor((Date.parse(props.order.expires_at) - now.value) / 1000)));
const expired = computed(() => remaining.value === 0);
const countdown = computed(() => `${String(Math.floor(remaining.value / 60)).padStart(2, '0')}:${String(remaining.value % 60).padStart(2, '0')}`);
const urgent = computed(() => remaining.value <= 120);

const statusLabel = { accepted: 'Aceito', rejected: 'Recusado', expired: 'Expirado' };
const statusClass = { accepted: 'text-success', rejected: 'text-danger', expired: 'text-muted' };

const processing = ref(false);
const errors = ref({});
const rejecting = ref(false);
const reason = ref('');

function send(url, data, onDone) {
    processing.value = true;
    errors.value = {};
    router.post(url, data, {
        preserveScroll: true,
        onError: (bag) => (errors.value = bag),
        onSuccess: onDone,
        onFinish: () => (processing.value = false),
    });
}

const accept = () => send(route('online.orders.accept', props.order.id), {});
const reject = () => send(route('online.orders.reject', props.order.id), { reason: reason.value }, () => {
    rejecting.value = false;
    reason.value = '';
});
function openReject() {
    errors.value = {};
    rejecting.value = true;
}
const time = (iso) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <article class="card p-4" :aria-label="`Pedido online ${order.number}`">
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="text-lg font-bold">Online #{{ order.number }} <span class="text-muted text-sm font-normal">{{ order.type === 'delivery' ? 'Entrega' : 'Retirada' }} · {{ time(order.created_at) }}</span></h3>
                <p class="text-sm">{{ order.customer_name }} · {{ order.customer_phone }}</p>
            </div>
            <p v-if="!decided" class="font-semibold tabular-nums" :class="expired ? 'text-danger' : urgent ? 'text-warn' : ''" role="timer">
                {{ expired ? 'Prazo encerrado' : `Aceitar em ${countdown}` }}
            </p>
            <p v-else class="text-sm font-semibold" :class="statusClass[order.status]">
                {{ statusLabel[order.status] }}<template v-if="order.decided_at"> às {{ time(order.decided_at) }}</template>
                <template v-if="order.decided_by_name"> por {{ order.decided_by_name }}</template>
            </p>
        </header>

        <p v-if="order.type === 'delivery'" class="text-muted mt-2 text-sm">
            {{ order.street }}, {{ order.number_address }}<template v-if="order.complement"> - {{ order.complement }}</template>
            <template v-if="order.zone_name"> · {{ order.zone_name }}</template>
            <template v-if="order.reference"> · Ref.: {{ order.reference }}</template>
        </p>

        <ul class="mt-3 space-y-1.5 text-sm">
            <li v-for="item in order.items" :key="item.id" class="flex justify-between gap-3">
                <span>
                    {{ item.quantity }}x {{ item.product_name }}
                    <span v-if="item.options_label" class="text-muted block">{{ item.options_label }}</span>
                    <span v-if="item.notes" class="text-muted block italic">Obs.: {{ item.notes }}</span>
                </span>
                <span class="tabular-nums">{{ formatMoney(item.total) }}</span>
            </li>
        </ul>

        <p v-if="order.notes" class="mt-2 text-sm italic">Obs. do pedido: {{ order.notes }}</p>

        <dl class="border-line mt-3 space-y-0.5 border-t pt-3 text-sm">
            <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatMoney(order.subtotal) }}</dd></div>
            <div class="flex justify-between"><dt>Taxa de entrega</dt><dd class="tabular-nums">{{ formatMoney(order.delivery_fee) }}</dd></div>
            <div class="flex justify-between font-bold"><dt>Total</dt><dd class="tabular-nums">{{ formatMoney(order.total) }}</dd></div>
            <div class="text-muted flex justify-between">
                <dt>Pagamento na entrega</dt>
                <dd>{{ paymentMethods[order.payment_method] ?? order.payment_method }}<template v-if="order.change_for"> · troco para {{ formatMoney(order.change_for) }}</template></dd>
            </div>
        </dl>

        <p v-if="decided && order.decision_reason" class="text-muted mt-2 text-sm">Motivo: {{ order.decision_reason }}</p>
        <Link v-if="decided && order.delivery_id" :href="route('deliveries.show', order.delivery_id)" class="btn btn-secondary mt-3">Ver entrega</Link>

        <p v-if="errors.online_order" class="text-danger mt-3 text-sm" role="alert">{{ errors.online_order }}</p>

        <div v-if="!decided" class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" :disabled="processing || expired" @click="accept">Aceitar</button>
            <button type="button" class="btn btn-secondary" :disabled="processing" @click="openReject">Recusar</button>
        </div>

        <ConfirmDialog :open="rejecting" :title="`Recusar pedido #${order.number}`" confirm-label="Recusar" danger :processing="processing" :disabled="reason.trim().length < 3" @confirm="reject" @cancel="rejecting = false">
            <div class="mt-4">
                <Field v-slot="{ id }" label="Motivo" required :error="errors.reason" hint="O cliente verá este motivo ao acompanhar o pedido.">
                    <textarea :id="id" v-model="reason" class="input" rows="3" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>
    </article>
</template>
