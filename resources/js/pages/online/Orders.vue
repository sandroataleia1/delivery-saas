<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePoll } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import MenuReceiptAlerts from '@/components/MenuReceiptAlerts.vue';
import OnlineOrderCard from '@/components/OnlineOrderCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    pending: { type: Array, required: true },
    producing: { type: Array, required: true },
    ready: { type: Array, required: true },
    closed: { type: Array, required: true },
    couriers: { type: Array, default: () => [] },
    store: { type: Object, default: null },
    whatsapp: { type: Object, default: null },
    operation: { type: Object, required: true },
    paymentMethods: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const lastUpdated = ref(Date.now());
usePoll(10000, { only: ['pending', 'producing', 'ready', 'closed', 'store', 'operation', 'onlinePendingCount', 'whatsapp'], onSuccess: () => (lastUpdated.value = Date.now()) }, { keepAlive: true });
const pendingIds = computed(() => props.pending.map((order) => order.id));

const columns = computed(() => [
    { stage: 'analysis', title: 'Em análise', orders: props.pending, empty: 'Novos pedidos da loja aparecem aqui sozinhos.' },
    { stage: 'production', title: 'Em produção', orders: props.producing, empty: 'Pedidos aceitos ficam aqui enquanto são preparados.' },
    { stage: 'ready', title: 'Prontos para entrega', orders: props.ready, empty: 'Quando a produção termina, o pedido aparece aqui.' },
]);

/** Etapa seguinte de cada coluna e a permissão exigida para levar o pedido até ela. */
const moves = {
    analysis: { to: 'production', permission: 'online_orders.accept', route: 'online.orders.accept' },
    production: { to: 'ready', permission: 'production.operate', route: 'online.orders.ready' },
};
const dragging = ref(null);
const dropTarget = ref(null);
const moving = ref(false);
const moveError = ref(null);

const canMove = (stage) => Boolean(moves[stage]) && can(moves[stage].permission);
const accepts = (stage) => dragging.value !== null && moves[dragging.value.stage]?.to === stage;

function onDragStart(event, order, stage) {
    dragging.value = { order, stage };
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(order.id));
}

function onDragEnd() {
    dragging.value = null;
    dropTarget.value = null;
}

function onDragOver(event, stage) {
    if (accepts(stage)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        dropTarget.value = stage;
    }
}

function onDrop(stage) {
    if (accepts(stage)) {
        move(dragging.value.order, dragging.value.stage);
    }
    onDragEnd();
}

function move(order, stage) {
    if (moving.value) {
        return;
    }
    moving.value = true;
    moveError.value = null;
    router.post(route(moves[stage].route, order.id), {}, {
        preserveScroll: true,
        onError: (bag) => (moveError.value = bag.online_order ?? bag.production ?? Object.values(bag)[0] ?? null),
        onFinish: () => (moving.value = false),
    });
}

const advanceForm = useForm({ expected_status: '', courier_id: '' });
const dispatching = ref(null);

function advance(order) {
    advanceForm.clearErrors();
    advanceForm.courier_id = '';

    if (order.delivery.next_status === 'out_for_delivery') {
        dispatching.value = order;

        return;
    }

    advanceForm.expected_status = order.delivery.status;
    advanceForm.post(route('deliveries.advance', order.delivery.id), { preserveScroll: true });
}

function dispatch() {
    advanceForm.expected_status = dispatching.value.delivery.status;
    advanceForm.post(route('deliveries.advance', dispatching.value.delivery.id), {
        preserveScroll: true,
        onSuccess: () => (dispatching.value = null),
    });
}

const advanceError = computed(() => advanceForm.errors.order ?? advanceForm.errors.production ?? null);

const pausing = ref(false);
function togglePause() {
    pausing.value = true;
    const options = { preserveScroll: true, onFinish: () => (pausing.value = false) };
    if (props.store.paused) {
        router.delete(route('online.resume'), options);
    } else {
        router.post(route('online.pause'), {}, options);
    }
}
</script>

<template>
    <AppLayout title="Pedidos online">
        <PageHeader title="Pedidos online" :subtitle="store ? store.label : null">
            <button v-if="store" type="button" class="btn btn-secondary" :disabled="pausing" @click="togglePause">
                {{ store.paused ? 'Reabrir loja' : 'Pausar loja' }}
            </button>
        </PageHeader>

        <div v-if="!store" class="card p-6 text-center">
            <p class="font-semibold">A loja online ainda não foi configurada.</p>
            <Link v-if="can('company.manage')" :href="route('online.settings')" class="btn btn-primary mt-3">Configurar loja online</Link>
        </div>

        <template v-else>
            <p class="text-muted mb-3 text-sm">Link da loja: <a :href="store.link" target="_blank" rel="noopener" class="text-brand-strong break-all underline">{{ store.link }}</a></p>
            <p v-if="store.paused" class="text-warn mb-3 text-sm font-semibold" role="status">A loja está pausada: os clientes não conseguem enviar novos pedidos.</p>
            <p v-if="whatsapp && !whatsapp.connected" class="text-warn mb-3 text-sm font-semibold" role="alert">
                WhatsApp desconectado: os clientes não estão recebendo as mensagens dos pedidos.
                <Link v-if="can('company.manage')" :href="route('online.whatsapp')" class="underline">Abrir WhatsApp</Link>
            </p>
            <p v-if="whatsapp && whatsapp.failed_recent > 0" class="text-warn mb-3 text-sm font-semibold" role="alert">
                {{ whatsapp.failed_recent }} {{ whatsapp.failed_recent === 1 ? 'mensagem' : 'mensagens' }} de WhatsApp {{ whatsapp.failed_recent === 1 ? 'não enviada' : 'não enviadas' }} nas últimas 24 horas.
                <Link v-if="can('company.manage')" :href="route('online.whatsapp')" class="underline">Abrir WhatsApp</Link>
            </p>
            <MenuReceiptAlerts :ids="pendingIds" :operation="operation" :last-updated="lastUpdated" />

            <p v-if="advanceError || moveError" class="text-danger mb-3 text-sm" role="alert">{{ advanceError ?? moveError }}</p>
            <p class="text-muted mb-3 hidden text-sm lg:block">Arraste o pedido para a próxima coluna para mudar a etapa.</p>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <section
                    v-for="column in columns"
                    :key="column.stage"
                    class="bg-canvas rounded-xl border p-3 transition-colors"
                    :class="dropTarget === column.stage ? 'border-brand bg-brand-soft' : accepts(column.stage) ? 'border-brand border-dashed' : 'border-line'"
                    :aria-label="column.title"
                    @dragover="onDragOver($event, column.stage)"
                    @dragleave="dropTarget === column.stage && (dropTarget = null)"
                    @drop.prevent="onDrop(column.stage)"
                >
                    <h2 class="mb-3 flex items-center justify-between gap-2 px-1 font-bold">
                        {{ column.title }}
                        <span class="badge bg-surface text-muted tabular-nums">{{ column.orders.length }}</span>
                    </h2>
                    <p v-if="!column.orders.length" class="text-muted px-1 py-6 text-center text-sm">{{ column.empty }}</p>
                    <div v-else class="space-y-3">
                        <div
                            v-for="order in column.orders"
                            :key="order.id"
                            :draggable="canMove(column.stage) && !moving"
                            :class="[canMove(column.stage) ? 'cursor-grab active:cursor-grabbing' : '', dragging?.order.id === order.id ? 'opacity-50' : '']"
                            @dragstart="onDragStart($event, order, column.stage)"
                            @dragend="onDragEnd"
                        >
                            <OnlineOrderCard :order="order" :payment-methods="paymentMethods" :stage="column.stage" @advance="advance" @ready="move($event, 'production')" />
                        </div>
                    </div>
                </section>
            </div>

            <details v-if="closed.length" class="mt-8">
                <summary class="cursor-pointer text-lg font-bold">Recusados e expirados nas últimas 12 horas ({{ closed.length }})</summary>
                <div class="mt-3 grid gap-4 lg:grid-cols-3">
                    <OnlineOrderCard v-for="order in closed" :key="order.id" :order="order" :payment-methods="paymentMethods" stage="closed" />
                </div>
            </details>

            <ConfirmDialog
                :open="dispatching !== null"
                :title="`Despachar o pedido online #${dispatching?.number ?? ''}`"
                confirm-label="Despachar"
                :disabled="advanceForm.courier_id === ''"
                :processing="advanceForm.processing"
                @confirm="dispatch"
                @cancel="dispatching = null"
            >
                <div class="mt-4">
                    <Field v-slot="{ id }" label="Entregador" required :error="advanceForm.errors.courier_id" :hint="couriers.length === 0 ? 'Nenhum usuário com perfil de entregador.' : null">
                        <select :id="id" v-model="advanceForm.courier_id" class="input">
                            <option value="" disabled>Selecione</option>
                            <option v-for="courier in couriers" :key="courier.id" :value="courier.id">{{ courier.name }}</option>
                        </select>
                    </Field>
                </div>
            </ConfirmDialog>
        </template>
    </AppLayout>
</template>
