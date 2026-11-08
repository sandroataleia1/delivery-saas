<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePoll } from '@inertiajs/vue3';
import MenuReceiptAlerts from '@/components/MenuReceiptAlerts.vue';
import OnlineOrderCard from '@/components/OnlineOrderCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    pending: { type: Array, required: true },
    recent: { type: Array, required: true },
    store: { type: Object, default: null },
    operation: { type: Object, required: true },
    paymentMethods: { type: Object, default: () => ({}) },
});

const { can } = usePermissions();
const lastUpdated = ref(Date.now());
usePoll(10000, { only: ['pending', 'recent', 'store', 'operation', 'onlinePendingCount'], onSuccess: () => (lastUpdated.value = Date.now()) }, { keepAlive: true });
const pendingIds = computed(() => props.pending.map((order) => order.id));

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
            <MenuReceiptAlerts :ids="pendingIds" :operation="operation" :last-updated="lastUpdated" />

            <h2 class="mb-3 text-lg font-bold">Aguardando aceite</h2>
            <EmptyState v-if="!pending.length" title="Nenhum pedido aguardando" text="Novos pedidos da loja aparecem aqui sozinhos." />
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <OnlineOrderCard v-for="order in pending" :key="order.id" :order="order" :payment-methods="paymentMethods" />
            </div>

            <h2 class="mt-8 mb-3 text-lg font-bold">Decididos nas últimas 12 horas</h2>
            <EmptyState v-if="!recent.length" title="Nenhum pedido decidido recentemente" />
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <OnlineOrderCard v-for="order in recent" :key="order.id" :order="order" :payment-methods="paymentMethods" decided />
            </div>
        </template>
    </AppLayout>
</template>
