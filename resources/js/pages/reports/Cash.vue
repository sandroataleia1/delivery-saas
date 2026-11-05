<script setup>
import { Link } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    summary: { type: Object, required: true },
    operators: { type: Array, required: true },
    sessions: { type: Array, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});

const tone = (value) => (Number(value) === 0 ? 'text-muted' : Number(value) < 0 ? 'text-danger' : 'text-ok');
</script>

<template>
    <ReportLayout title="Caixa" slug="caixa" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportCard label="Caixas fechados" :value="String(summary.sessions)" :note="`${summary.with_difference} com diferença`" />
            <ReportCard label="Faltas" :value="formatMoney(summary.short)" :tone="Number(summary.short) < 0 ? 'bad' : null" />
            <ReportCard label="Sobras" :value="formatMoney(summary.over)" />
            <ReportCard label="Sangrias" :value="formatMoney(summary.withdrawals)" :note="`Suprimentos: ${formatMoney(summary.deposits)}`" />
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_2fr]">
            <section>
                <h2 class="mb-3 font-bold">Por operador</h2>
                <EmptyState v-if="operators.length === 0" title="Nenhum caixa fechado" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Operador</th>
                                <th class="text-right">Caixas</th>
                                <th class="text-right">Diferença</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="operator in operators" :key="operator.label">
                                <td class="font-semibold">{{ operator.label }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">{{ operator.with_difference }} de {{ operator.sessions }}</td>
                                <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="tone(operator.total)">{{ formatMoney(operator.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="mb-3 font-bold">Fechamentos com diferença</h2>
                <EmptyState v-if="sessions.length === 0" title="Todos os fechamentos conferiram" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Caixa</th>
                                <th class="max-sm:hidden">Data</th>
                                <th class="text-right">Diferença</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="session in sessions" :key="session.id">
                                <td>
                                    <Link :href="route('cash.show', session.id)" class="text-brand font-semibold hover:underline">#{{ session.number }}</Link>
                                    <span class="ml-2">{{ session.user }}</span>
                                    <p v-if="session.notes" class="text-muted text-xs">{{ session.notes }}</p>
                                </td>
                                <td class="whitespace-nowrap tabular-nums max-sm:hidden">{{ session.date }}</td>
                                <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="tone(session.difference)">{{ formatMoney(session.difference) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </ReportLayout>
</template>
