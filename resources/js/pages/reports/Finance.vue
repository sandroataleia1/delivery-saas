<script setup>
import { Link } from '@inertiajs/vue3';
import RankList from '@/components/charts/RankList.vue';
import ReportCard from '@/components/ReportCard.vue';
import ReportLayout from '@/layouts/ReportLayout.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    statement: { type: Object, required: true },
    expenses: { type: Array, required: true },
    open: { type: Object, required: true },
    debtors: { type: Array, required: true },
    purchases: { type: String, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
});
</script>

<template>
    <ReportLayout title="Financeiro" slug="financeiro" :period="period" :presets="presets">
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <ReportCard label="Receita de vendas" :value="formatMoney(statement.revenue)" />
            <ReportCard label="Lucro bruto" :value="formatMoney(statement.gross_profit)" />
            <ReportCard label="Despesas pagas" :value="formatMoney(statement.expenses)" />
            <ReportCard
                label="Resultado"
                :value="formatMoney(statement.result)"
                :note="statement.margin_percent === null ? null : `${statement.margin_percent.toLocaleString('pt-BR')}% da receita`"
                :tone="Number(statement.result) < 0 ? 'bad' : 'good'"
            />
        </div>

        <div class="mb-4 grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <h2 class="mb-4 font-bold">Resultado do período</h2>
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">Vendas de produtos</dt><dd class="tabular-nums">{{ formatMoney(statement.subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Taxas de serviço</dt><dd class="tabular-nums">{{ formatMoney(statement.service_fee) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Taxas de entrega</dt><dd class="tabular-nums">{{ formatMoney(statement.delivery_fee) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Descontos</dt><dd class="tabular-nums">− {{ formatMoney(statement.discount) }}</dd></div>
                    <div class="border-line flex justify-between border-t pt-1.5 font-semibold"><dt>Receita de vendas</dt><dd class="tabular-nums">{{ formatMoney(statement.revenue) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Custo do que foi vendido</dt><dd class="tabular-nums">− {{ formatMoney(statement.cost) }}</dd></div>
                    <div class="border-line flex justify-between border-t pt-1.5 font-semibold"><dt>Lucro bruto</dt><dd class="tabular-nums">{{ formatMoney(statement.gross_profit) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Outras receitas</dt><dd class="tabular-nums">{{ formatMoney(statement.other_income) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Descontos na quitação de fiado</dt><dd class="tabular-nums">− {{ formatMoney(statement.credit_discounts) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Despesas pagas</dt><dd class="tabular-nums">− {{ formatMoney(statement.expenses) }}</dd></div>
                    <div class="border-line flex justify-between border-t pt-1.5 text-base font-bold"><dt>Resultado</dt><dd class="tabular-nums">{{ formatMoney(statement.result) }}</dd></div>
                </dl>
                <p class="text-muted mt-3 text-xs">
                    Vendas contam no dia em que foram feitas, inclusive fiado. Compras de mercadoria não entram nas despesas: viram custo quando vendidas.
                    Compras pagas no período: {{ formatMoney(purchases) }}.
                </p>
            </section>

            <section class="card p-5">
                <h2 class="mb-4 font-bold">Despesas por categoria</h2>
                <RankList :rows="expenses" empty="Nenhuma despesa paga no período." />
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <h2 class="mb-1 font-bold">Situação hoje</h2>
                <p class="text-muted mb-4 text-xs">Não depende do período escolhido.</p>
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-muted">A pagar</dt>
                        <dd class="text-lg font-bold tabular-nums">{{ formatMoney(open.payable) }}</dd>
                        <dd class="text-xs tabular-nums" :class="Number(open.payable_overdue) > 0 ? 'text-danger' : 'text-muted'">{{ formatMoney(open.payable_overdue) }} vencidos</dd>
                    </div>
                    <div>
                        <dt class="text-muted">A receber</dt>
                        <dd class="text-lg font-bold tabular-nums">{{ formatMoney(open.receivable) }}</dd>
                        <dd class="text-xs tabular-nums" :class="Number(open.receivable_overdue) > 0 ? 'text-danger' : 'text-muted'">{{ formatMoney(open.receivable_overdue) }} vencidos</dd>
                    </div>
                </dl>
            </section>

            <section class="card p-5">
                <h2 class="mb-1 font-bold">Maiores devedores</h2>
                <p class="text-muted mb-4 text-xs">Fiado em aberto hoje.</p>
                <RankList :rows="debtors" :share="false" empty="Nenhum cliente devendo." />
                <Link :href="route('bills.index', 'receber')" class="text-brand mt-3 inline-block text-sm font-semibold hover:underline print:hidden">Ver contas a receber</Link>
            </section>
        </div>
    </ReportLayout>
</template>
