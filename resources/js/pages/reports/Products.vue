<script setup>
import { Link } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import RankList from '@/components/charts/RankList.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney, formatQuantity } from '@/lib/format';

defineProps({
    summary: { type: Object, required: true },
    products: { type: Array, required: true },
    categories: { type: Array, required: true },
    withCost: { type: Boolean, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});
</script>

<template>
    <ReportLayout title="Produtos" slug="produtos" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <ReportCard label="Faturamento em produtos" :value="formatMoney(summary.revenue)" />
            <ReportCard label="Produtos vendidos" :value="String(summary.items)" />
            <ReportCard v-if="withCost" label="Custo do que foi vendido" :value="formatMoney(summary.cost)" />
        </div>

        <p v-if="withCost && summary.without_cost > 0" class="bg-warn-soft text-warn mb-4 rounded-lg px-4 py-3 text-sm font-medium">
            {{ summary.without_cost }} {{ summary.without_cost === 1 ? 'produto vendido está' : 'produtos vendidos estão' }} sem custo: a margem deles aparece maior do que a real.
            Cadastre o custo ou a ficha técnica em <Link :href="route('products.index')" class="underline">Produtos</Link>.
        </p>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <section class="min-w-0">
                <h2 class="mb-3 font-bold">Produtos mais vendidos</h2>
                <EmptyState v-if="products.length === 0" title="Sem vendas no período" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produto</th>
                                <th class="text-right">Qtd.</th>
                                <th class="text-right">Faturamento</th>
                                <th v-if="withCost" class="text-right max-sm:hidden">Margem</th>
                                <th v-if="withCost" class="text-right">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in products" :key="product.id">
                                <td class="font-semibold">{{ product.name }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(product.quantity, product.unit) }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(product.revenue) }}</td>
                                <td v-if="withCost" class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatMoney(product.margin) }}</td>
                                <td v-if="withCost" class="text-right whitespace-nowrap tabular-nums">{{ product.margin_percent === null ? '—' : `${product.margin_percent.toLocaleString('pt-BR')}%` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card h-fit p-5">
                <h2 class="mb-4 font-bold">Por categoria</h2>
                <RankList :rows="categories" />
            </section>
        </div>
    </ReportLayout>
</template>
