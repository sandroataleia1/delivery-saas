<script setup>
import EmptyState from '@/components/EmptyState.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney, formatQuantity } from '@/lib/format';

defineProps({
    summary: { type: Object, required: true },
    alerts: { type: Array, required: true },
    losses: { type: Array, required: true },
    consumption: { type: Array, required: true },
    withCost: { type: Boolean, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});
</script>

<template>
    <ReportLayout title="Estoque" slug="estoque" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportCard v-if="withCost" label="Valor em estoque hoje" :value="formatMoney(summary.value)" />
            <ReportCard label="Estoque baixo" :value="String(summary.low)" :tone="summary.low > 0 ? 'bad' : null" />
            <ReportCard label="Saldo negativo" :value="String(summary.negative)" :tone="summary.negative > 0 ? 'bad' : null" />
            <ReportCard v-if="withCost" label="Perdas no período" :value="formatMoney(summary.losses)" note="Pelo custo médio atual" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section>
                <h2 class="mb-3 font-bold">Precisa de atenção hoje</h2>
                <EmptyState v-if="alerts.length === 0" title="Nenhum produto em alerta" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="text-right">Saldo</th>
                                <th class="text-right">Mínimo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in alerts" :key="product.id">
                                <td class="font-semibold">{{ product.name }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums" :class="{ 'text-danger font-semibold': product.is_negative }">{{ formatQuantity(product.stock_quantity, product.unit) }}</td>
                                <td class="text-muted text-right whitespace-nowrap tabular-nums">{{ formatQuantity(product.min_stock, product.unit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-3 font-bold">Mais consumidos no período</h2>
                <EmptyState v-if="consumption.length === 0" title="Sem consumo no período" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produto ou insumo</th>
                                <th class="text-right">Consumo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in consumption" :key="item.name">
                                <td class="font-semibold">{{ item.name }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(item.quantity, item.unit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="losses.length" class="lg:col-span-2">
                <h2 class="mb-3 font-bold">Perdas no período</h2>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="text-right">Quantidade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in losses" :key="item.name">
                                <td class="font-semibold">{{ item.name }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(item.quantity, item.unit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </ReportLayout>
</template>
