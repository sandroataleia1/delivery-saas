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
import { Settings } from '@lucide/vue';

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
    waitingCustomers: { type: Array, default: () => [] },
});

const { can } = usePermissions();
const lastUpdated = ref(Date.now());
usePoll(10000, { only: ['pending', 'producing', 'ready', 'closed', 'store', 'operation', 'onlinePendingCount', 'whatsapp', 'waitingCustomers'], onSuccess: () => (lastUpdated.value = Date.now()) }, { keepAlive: true });
const pendingIds = computed(() => props.pending.map((order) => order.id));
const settingsOpen = ref(false);
const alerts = ref(null);
function toggleSound(event) {
    event.target.checked ? alerts.value?.enable() : alerts.value?.disable();
}

const columns = computed(() => [
    { stage: 'analysis', title: 'Em análise', orders: props.pending, empty: props.store?.auto_accept ? 'Todos os pedidos são aceitos automaticamente.' : 'Nenhum pedido aguardando.', head: 'bg-stone-500', body: 'bg-stone-200' },
    { stage: 'production', title: 'Em produção', orders: props.producing, empty: 'Nenhum pedido em preparo.', head: 'bg-orange-500', body: 'bg-orange-100' },
    { stage: 'ready', title: 'Prontos para entrega', orders: props.ready, empty: 'Nenhum pedido pronto.', head: 'bg-ok', body: 'bg-ok-soft' },
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

const autoAccepting = ref(false);
const autoAcceptError = ref(null);
function toggleAutoAccept(event) {
    const checkbox = event.target;
    autoAccepting.value = true;
    autoAcceptError.value = null;
    router.put(route('online.auto-accept'), { enabled: checkbox.checked }, {
        preserveScroll: true,
        onError: (bag) => {
            autoAcceptError.value = bag.auto_accept ?? Object.values(bag)[0] ?? null;
            checkbox.checked = props.store.auto_accept;
        },
        onFinish: () => (autoAccepting.value = false),
    });
}

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
            <div v-if="store" class="relative" @keydown.escape="settingsOpen = false">
                <button
                    type="button"
                    class="btn btn-secondary px-2.5"
                    aria-label="Configurações dos pedidos online"
                    aria-haspopup="dialog"
                    :aria-expanded="settingsOpen"
                    @click="settingsOpen = !settingsOpen"
                >
                    <Settings :size="18" />
                </button>
                <div v-if="settingsOpen" class="fixed inset-0 z-30" @click="settingsOpen = false" />
                <div
                    v-if="settingsOpen"
                    role="dialog"
                    aria-label="Configurações dos pedidos online"
                    class="card absolute top-full left-0 z-40 mt-2 w-[min(20rem,calc(100vw-2rem))] space-y-4 p-4 text-sm shadow-lg sm:right-0 sm:left-auto"
                >
                    <label v-if="operation.sound_alerts" class="flex cursor-pointer items-start gap-2.5">
                        <input type="checkbox" class="accent-brand mt-0.5 size-4" :checked="alerts?.wanted" @change="toggleSound" />
                        <span>
                            <span class="block font-semibold">Som de novos pedidos</span>
                            <span class="text-muted">Vale para este aparelho.</span>
                        </span>
                    </label>
                    <div :class="{ 'border-line border-t pt-3': operation.sound_alerts }">
                        <p class="text-muted text-xs">Link da loja</p>
                        <a :href="store.link" target="_blank" rel="noopener" class="text-brand-strong break-all underline">{{ store.link }}</a>
                    </div>
                    <p class="text-muted hidden text-xs lg:block">Dica: arraste o pedido para a próxima coluna para mudar a etapa.</p>
                </div>
            </div>
            <button v-if="store" type="button" class="btn btn-secondary" :disabled="pausing" @click="togglePause">
                {{ store.paused ? 'Reabrir loja' : 'Pausar loja' }}
            </button>
        </PageHeader>

        <div v-if="!store" class="card p-6 text-center">
            <p class="font-semibold">A loja online ainda não foi configurada.</p>
            <Link v-if="can('company.manage')" :href="route('online.settings')" class="btn btn-primary mt-3">Configurar loja online</Link>
        </div>

        <template v-else>
            <p v-if="store.paused" class="text-warn mb-3 text-sm font-semibold" role="status">Loja pausada: clientes não conseguem pedir.</p>
            <p v-if="whatsapp && !whatsapp.connected" class="text-warn mb-3 text-sm font-semibold" role="alert">
                WhatsApp desconectado: clientes não recebem as mensagens.
                <Link v-if="can('company.manage')" :href="route('online.whatsapp')" class="underline">Abrir WhatsApp</Link>
            </p>
            <p v-if="whatsapp && whatsapp.failed_recent > 0" class="text-warn mb-3 text-sm font-semibold" role="alert">
                {{ whatsapp.failed_recent }} {{ whatsapp.failed_recent === 1 ? 'mensagem' : 'mensagens' }} de WhatsApp {{ whatsapp.failed_recent === 1 ? 'não enviada' : 'não enviadas' }} nas últimas 24 horas.
                <Link v-if="can('company.manage')" :href="route('online.whatsapp')" class="underline">Abrir WhatsApp</Link>
            </p>
            <div v-if="waitingCustomers.length" class="border-warn bg-canvas mb-3 rounded-xl border p-3 text-sm" role="alert">
                <p class="text-warn font-semibold">Cliente aguardando atendente no WhatsApp</p>
                <ul class="mt-1 space-y-0.5">
                    <li v-for="customer in waitingCustomers" :key="customer.id">
                        {{ customer.name ?? 'Cliente' }} · {{ customer.phone ?? 'número oculto' }}
                        <a v-if="customer.wa_link" :href="customer.wa_link" target="_blank" rel="noopener" class="text-brand-strong underline">Abrir conversa</a>
                    </li>
                </ul>
            </div>
            <MenuReceiptAlerts ref="alerts" :ids="pendingIds" :operation="operation" :last-updated="lastUpdated" :sound-controls="false" />

            <p v-if="advanceError || moveError" class="text-danger mb-3 text-sm" role="alert">{{ advanceError ?? moveError }}</p>

            <div class="grid items-start gap-4 lg:grid-cols-3">
                <section
                    v-for="column in columns"
                    :key="column.stage"
                    class="overflow-hidden rounded-xl outline-offset-2 transition-all"
                    :class="[column.body, dropTarget === column.stage ? 'outline-brand outline-4' : accepts(column.stage) ? 'outline-brand outline-2 outline-dashed' : '']"
                    :aria-label="column.title"
                    @dragover="onDragOver($event, column.stage)"
                    @dragleave="dropTarget === column.stage && (dropTarget = null)"
                    @drop.prevent="onDrop(column.stage)"
                >
                    <h2 class="flex items-center justify-between gap-2 px-4 py-3 font-bold text-white" :class="column.head">
                        {{ column.title }}
                        <span class="tabular-nums">{{ column.orders.length }}</span>
                    </h2>
                    <div v-if="column.stage === 'analysis' && can('online_orders.accept')" class="px-3 pt-3">
                        <div class="card p-3 text-sm">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input type="checkbox" role="switch" class="peer sr-only" :checked="store.auto_accept" :disabled="autoAccepting" @change="toggleAutoAccept" />
                                <span
                                    class="peer-checked:bg-ok peer-focus-visible:outline-brand relative h-6 w-11 shrink-0 rounded-full bg-stone-300 transition-colors peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 after:absolute after:top-0.5 after:left-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-5"
                                    aria-hidden="true"
                                />
                                <span class="font-semibold">Aceitar os pedidos automaticamente</span>
                            </label>
                            <p v-if="autoAcceptError" class="text-danger mt-2" role="alert">
                                {{ autoAcceptError }}
                                <Link v-if="can('production.configure')" :href="route('production.sectors')" class="underline">Abrir setores</Link>
                            </p>
                        </div>
                    </div>
                    <p v-if="!column.orders.length" class="text-ink/70 px-4 py-10 text-center text-sm">{{ column.empty }}</p>
                    <div v-else class="space-y-3 p-3">
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
