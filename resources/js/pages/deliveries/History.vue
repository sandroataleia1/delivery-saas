<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    couriers: { type: Array, required: true },
    statuses: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    courier: props.filters.courier ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('deliveries.history'), query, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <AppLayout title="Histórico de entregas">
        <PageHeader title="Histórico de entregas" />

        <div class="mb-6 grid grid-cols-2 gap-3">
            <div class="card p-4">
                <p class="text-muted text-sm">Entregues no filtro</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ summary.count }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Taxas de entrega</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(summary.fees) }}</p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <input v-model="filters.search" class="input" type="search" placeholder="Cliente ou número" aria-label="Buscar por cliente ou número do pedido" />
            <select v-model="filters.status" class="input" aria-label="Filtrar por etapa">
                <option value="">Todas as etapas</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <select v-model="filters.courier" class="input" aria-label="Filtrar por entregador">
                <option value="">Todos os entregadores</option>
                <option v-for="courier in couriers" :key="courier.id" :value="courier.id">{{ courier.name }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <EmptyState v-if="orders.data.length === 0" title="Nenhum pedido encontrado" />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pedido</th>
                        <th class="max-md:hidden">Entregador</th>
                        <th class="max-sm:hidden">Etapa</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <td>
                            <Link :href="route('deliveries.show', order.id)" class="text-brand font-semibold hover:underline">#{{ order.number }}</Link>
                            <span v-if="order.online_number" class="text-muted ml-2 text-xs">Online #{{ order.online_number }}</span>
                            <span class="ml-2 font-semibold">{{ order.customer }}</span>
                            <p class="text-muted text-xs tabular-nums">{{ order.date }}</p>
                        </td>
                        <td class="whitespace-nowrap max-md:hidden">{{ order.courier ?? '—' }}</td>
                        <td class="whitespace-nowrap max-sm:hidden">{{ order.status_label }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums">{{ formatMoney(order.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="orders" />
    </AppLayout>
</template>
