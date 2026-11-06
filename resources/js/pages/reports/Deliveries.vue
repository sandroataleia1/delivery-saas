<script setup>
import EmptyState from '@/components/EmptyState.vue';
import RankList from '@/components/charts/RankList.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    summary: { type: Object, required: true },
    couriers: { type: Array, required: true },
    districts: { type: Array, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});
</script>

<template>
    <ReportLayout title="Entregas" slug="entregas" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportCard label="Entregas feitas" :value="String(summary.deliveries)" :note="`${summary.pickups} retiradas`" />
            <ReportCard label="Taxas de entrega" :value="formatMoney(summary.fees)" />
            <ReportCard label="Tempo médio" :value="summary.average_minutes === null ? '—' : `${summary.average_minutes} min`" note="Do pedido até a entrega" />
            <ReportCard label="Cancelados" :value="String(summary.cancelled)" :tone="summary.cancelled > 0 ? 'bad' : null" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section>
                <h2 class="mb-3 font-bold">Por entregador</h2>
                <EmptyState v-if="couriers.length === 0" title="Sem entregas no período" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Entregador</th>
                                <th class="text-right">Entregas</th>
                                <th class="text-right">Taxas</th>
                                <th class="text-right max-sm:hidden">Tempo em rota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="courier in couriers" :key="courier.label">
                                <td class="font-semibold">{{ courier.label }}</td>
                                <td class="text-right tabular-nums">{{ courier.count }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(courier.fees) }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ courier.route_minutes === null ? '—' : `${courier.route_minutes} min` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card p-5">
                <h2 class="mb-4 font-bold">Por bairro</h2>
                <RankList :rows="districts" />
            </section>
        </div>
    </ReportLayout>
</template>
