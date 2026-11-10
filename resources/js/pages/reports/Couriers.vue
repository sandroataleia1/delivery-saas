<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    courier: { type: String, default: null },
    couriers: { type: Array, required: true },
    summary: { type: Object, required: true },
    orders: { type: Array, required: true },
    ranking: { type: Array, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});

const extraQuery = computed(() => (props.courier ? { courier: props.courier } : {}));
const courierName = computed(() => props.couriers.find((courier) => courier.key === props.courier)?.name ?? null);

function choose(key) {
    router.get(route('reports.couriers'), { from: props.period.from, to: props.period.to, ...(key ? { courier: key } : {}) }, { preserveScroll: true, replace: true });
}

const minutes = (value) => (value === null ? '—' : `${value} min`);
</script>

<template>
    <ReportLayout title="Entregadores" slug="entregadores" :period="period" :presets="presets" :extra-query="extraQuery">
        <div class="mb-6 max-w-sm print:hidden">
            <label for="courier-filter" class="mb-1 block text-sm font-semibold">Entregador</label>
            <select id="courier-filter" class="input" :value="courier ?? ''" @change="choose($event.target.value)">
                <option value="">Todos (resumo)</option>
                <option v-for="option in couriers" :key="option.key" :value="option.key">{{ option.name }}</option>
            </select>
        </div>

        <template v-if="courier">
            <h2 class="mb-3 text-lg font-bold">{{ courierName ?? 'Entregador' }}</h2>
            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <ReportCard label="Entregues" :value="String(summary.delivered)" :note="summary.out ? `${summary.out} em rota agora` : null" />
                <ReportCard label="Taxas de entrega" :value="formatMoney(summary.fees)" note="Só das entregues" />
                <ReportCard label="Valor entregue" :value="formatMoney(summary.total)" />
                <ReportCard label="Não entregues" :value="String(summary.returned)" :tone="summary.returned > 0 ? 'bad' : null" :note="`Tempo médio em rota: ${minutes(summary.route_minutes)}`" />
            </div>

            <EmptyState v-if="orders.length === 0" title="Nenhuma entrega no período" />
            <div v-else class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Saída</th>
                            <th>Pedido</th>
                            <th class="max-md:hidden">Cliente</th>
                            <th class="max-sm:hidden">Bairro</th>
                            <th>Situação</th>
                            <th class="text-right">Taxa</th>
                            <th class="text-right max-sm:hidden">Total</th>
                            <th class="text-right max-lg:hidden">Em rota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                            <td class="whitespace-nowrap tabular-nums">{{ order.date }}</td>
                            <td>
                                <Link :href="route('deliveries.show', order.id)" class="text-brand font-semibold hover:underline print:no-underline">#{{ order.number }}</Link>
                            </td>
                            <td class="max-md:hidden">{{ order.customer }}</td>
                            <td class="max-sm:hidden">{{ order.district }}</td>
                            <td>
                                <span :class="{ 'text-danger font-semibold': order.status === 'returned' }">{{ order.status_label }}</span>
                                <span v-if="order.return_reason" class="text-muted block text-xs">{{ order.return_reason }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(order.fee) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatMoney(order.total) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums max-lg:hidden">{{ minutes(order.route_minutes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <template v-else>
            <EmptyState v-if="ranking.length === 0" title="Nenhuma entrega no período" />
            <div v-else class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Entregador</th>
                            <th class="text-right">Entregues</th>
                            <th class="text-right">Não entregues</th>
                            <th class="text-right">Taxas</th>
                            <th class="text-right max-sm:hidden">Valor entregue</th>
                            <th class="text-right max-md:hidden">Tempo em rota</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in ranking" :key="row.key ?? row.name">
                            <td class="font-semibold">
                                <button v-if="row.key" type="button" class="text-brand cursor-pointer hover:underline" @click="choose(row.key)">{{ row.name }}</button>
                                <template v-else>{{ row.name }}</template>
                            </td>
                            <td class="text-right tabular-nums">{{ row.delivered }}</td>
                            <td class="text-right tabular-nums" :class="{ 'text-danger font-semibold': row.returned > 0 }">{{ row.returned }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(row.fees) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatMoney(row.total) }}</td>
                            <td class="text-right whitespace-nowrap tabular-nums max-md:hidden">{{ minutes(row.route_minutes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted mt-3 text-sm">Escolha um entregador para ver entrega por entrega e exportar a planilha dele.</p>
        </template>
    </ReportLayout>
</template>
