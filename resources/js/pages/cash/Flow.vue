<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    date: { type: String, required: true },
    dateLabel: { type: String, required: true },
    today: { type: String, required: true },
    inflows: { type: Array, required: true },
    gross: { type: String, required: true },
    cancellations: { type: String, required: true },
    net: { type: String, required: true },
    deposits: { type: String, required: true },
    withdrawals: { type: String, required: true },
    refunds: { type: String, required: true },
    differences: { type: String, required: true },
    sessions: { type: Array, required: true },
});

const date = ref(props.date);

watch(date, (value) => {
    if (value) {
        router.get(route('cash.flow'), { date: value }, { preserveState: true, replace: true });
    }
});

const differenceClass = (value) => (Number(value) === 0 ? 'text-muted' : Number(value) < 0 ? 'text-danger' : 'text-ok');
</script>

<template>
    <AppLayout title="Fluxo de caixa">
        <PageHeader title="Fluxo de caixa diário" :subtitle="`Movimento de ${dateLabel}`">
            <input v-model="date" class="input w-auto" type="date" :max="today" aria-label="Data" />
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card col-span-2 p-4 lg:col-span-1">
                <p class="text-muted text-sm">Resultado do dia</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ formatMoney(net) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Suprimentos</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(deposits) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Sangrias</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(withdrawals) }}</p>
            </div>
            <div class="card col-span-2 p-4 lg:col-span-1">
                <p class="text-muted text-sm">Diferenças nos fechamentos</p>
                <p class="mt-1 text-2xl font-bold tabular-nums" :class="differenceClass(differences)">{{ formatMoney(differences) }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section>
                <h2 class="mb-3 text-lg font-bold">Vendas por forma de pagamento</h2>
                <dl class="card space-y-1.5 p-5 text-sm">
                    <div v-for="inflow in inflows" :key="inflow.label" class="flex justify-between">
                        <dt class="text-muted">{{ inflow.label }}</dt>
                        <dd class="tabular-nums">{{ formatMoney(inflow.total) }}</dd>
                    </div>
                    <div class="border-line flex justify-between border-t pt-1.5 font-semibold">
                        <dt>Total vendido</dt>
                        <dd class="tabular-nums">{{ formatMoney(gross) }}</dd>
                    </div>
                    <div v-if="Number(cancellations) > 0" class="flex justify-between">
                        <dt class="text-muted">Cancelamentos de dias anteriores</dt>
                        <dd class="text-danger tabular-nums">− {{ formatMoney(cancellations) }}</dd>
                    </div>
                    <div class="flex justify-between text-base font-bold">
                        <dt>Resultado do dia</dt>
                        <dd class="tabular-nums">{{ formatMoney(net) }}</dd>
                    </div>
                </dl>
                <p v-if="Number(refunds) > 0" class="text-muted mt-2 text-sm">
                    {{ formatMoney(refunds) }} devolvidos em dinheiro por cancelamentos, já descontados acima.
                </p>
            </section>

            <section>
                <h2 class="mb-3 text-lg font-bold">Caixas do dia</h2>
                <EmptyState v-if="sessions.length === 0" title="Nenhum caixa neste dia" />
                <div v-else class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Caixa</th>
                                <th class="max-sm:hidden">Período</th>
                                <th class="text-right">Diferença</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="session in sessions" :key="session.id">
                                <td>
                                    <Link :href="route('cash.show', session.id)" class="text-brand font-semibold hover:underline">#{{ session.number }}</Link>
                                    <span v-if="session.is_open" class="badge bg-ok-soft text-ok ml-2">Aberto</span>
                                    <p class="text-muted text-xs">{{ session.user }}</p>
                                </td>
                                <td class="whitespace-nowrap tabular-nums max-sm:hidden">{{ session.opened_at }} – {{ session.closed_at ?? 'agora' }}</td>
                                <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="differenceClass(session.difference)">
                                    {{ session.is_open ? '—' : formatMoney(session.difference) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
