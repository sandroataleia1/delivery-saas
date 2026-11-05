<script setup>
import { useForm, usePoll } from '@inertiajs/vue3';
import { MapPin, Phone } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    orders: { type: Array, required: true },
    deliveredToday: { type: Number, required: true },
});

usePoll(15000, { only: ['orders', 'deliveredToday'] });

const form = useForm({ expected_status: 'out_for_delivery' });

function finish(order) {
    form.post(route('deliveries.advance', order.id), { preserveScroll: true });
}

const changeToBring = (order) => Math.max(Number(order.change_for) - Number(order.total), 0);

const mapUrl = (order) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(order.address)}`;
</script>

<template>
    <AppLayout title="Minhas entregas">
        <PageHeader title="Minhas entregas" :subtitle="`${deliveredToday} entregues hoje`" />

        <EmptyState v-if="orders.length === 0" title="Nenhuma entrega em rota" text="Os pedidos despachados para você aparecem aqui." />

        <ul class="max-w-xl space-y-3">
            <li v-for="order in orders" :key="order.id" class="card p-4">
                <div class="flex items-center justify-between">
                    <p class="font-bold">Pedido #{{ order.number }}</p>
                    <p class="font-bold tabular-nums">{{ formatMoney(order.total) }}</p>
                </div>
                <p class="mt-2 font-semibold">{{ order.customer }}</p>
                <p class="text-sm">{{ order.address }}</p>
                <p v-if="order.reference" class="text-muted text-sm">{{ order.reference }}</p>
                <p class="text-warn mt-2 text-sm font-semibold">
                    {{ order.payment }}<template v-if="changeToBring(order) > 0"> · levar {{ formatMoney(changeToBring(order)) }} de troco</template>
                </p>
                <p v-if="order.notes" class="text-muted text-sm">{{ order.notes }}</p>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <a :href="mapUrl(order)" target="_blank" rel="noopener" class="btn btn-secondary">
                        <MapPin :size="16" />
                        Mapa
                    </a>
                    <a v-if="order.phone" :href="`tel:${order.phone.replace(/\D/g, '')}`" class="btn btn-secondary">
                        <Phone :size="16" />
                        Ligar
                    </a>
                </div>
                <button type="button" class="btn btn-primary mt-2 min-h-12 w-full" :disabled="form.processing" @click="finish(order)">Entreguei</button>
            </li>
        </ul>
    </AppLayout>
</template>
