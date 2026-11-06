<script setup>
import { computed } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import RankList from '@/components/charts/RankList.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    summary: { type: Object, required: true },
    daily: { type: Array, required: true },
    hourly: { type: Array, required: true },
    origins: { type: Array, required: true },
    payments: { type: Array, required: true },
    operators: { type: Array, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});

const tooltip = (row) => `${formatMoney(row.total)} · ${row.count} ${row.count === 1 ? 'venda' : 'vendas'}`;
const toPoints = (rows) => rows.map((row) => ({ label: row.label, value: Number(row.total), tooltip: tooltip(row) }));

const dailyPoints = computed(() => toPoints(props.daily));
// Só as horas de funcionamento: a madrugada vazia comprimiria as barras.
const hourlyPoints = computed(() => {
    const open = props.hourly.filter((row) => row.count > 0).map((row) => row.hour);

    return open.length === 0
        ? []
        : toPoints(props.hourly.filter((row) => row.hour >= Math.min(...open) && row.hour <= Math.max(...open)));
});
const bestDay = computed(() => props.daily.reduce((best, day) => (Number(day.total) > Number(best?.total ?? 0) ? day : best), null));
</script>

<template>
    <ReportLayout title="Vendas" slug="vendas" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportCard label="Total vendido" :value="formatMoney(summary.total)" :note="`${summary.count} vendas`" />
            <ReportCard label="Ticket médio" :value="formatMoney(summary.average)" />
            <ReportCard label="Descontos" :value="formatMoney(summary.discount)" />
            <ReportCard label="Canceladas" :value="String(summary.cancelled_count)" :note="formatMoney(summary.cancelled_total)" :tone="summary.cancelled_count > 0 ? 'bad' : null" />
        </div>

        <section class="card mb-4 p-5">
            <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-bold">Vendas por dia</h2>
                <p v-if="bestDay" class="text-muted text-sm">Melhor dia: {{ bestDay.label }}, {{ formatMoney(bestDay.total) }}</p>
            </div>
            <BarChart :points="dailyPoints" label="Total vendido por dia no período" />
        </section>

        <div class="mb-4 grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <h2 class="mb-4 font-bold">Horário de pico</h2>
                <BarChart :points="hourlyPoints" :height="150" label="Total vendido por hora do dia" />
            </section>
            <section class="card p-5">
                <h2 class="mb-4 font-bold">Origem das vendas</h2>
                <RankList :rows="origins" />
            </section>
            <section class="card p-5">
                <h2 class="mb-4 font-bold">Formas de pagamento</h2>
                <RankList :rows="payments" />
            </section>
            <section class="card p-5">
                <h2 class="mb-4 font-bold">Por operador</h2>
                <RankList :rows="operators" />
            </section>
        </div>

        <details class="card p-5 text-sm">
            <summary class="cursor-pointer font-semibold">Ver vendas por dia em tabela</summary>
            <div class="table-wrap mt-3">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dia</th>
                            <th class="text-right">Vendas</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in daily" :key="day.day">
                            <td class="tabular-nums">{{ day.label }}</td>
                            <td class="text-right tabular-nums">{{ day.count }}</td>
                            <td class="text-right tabular-nums">{{ formatMoney(day.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </ReportLayout>
</template>
