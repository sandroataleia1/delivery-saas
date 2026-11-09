<script setup>
import { computed } from 'vue';
import { Link, usePage, usePoll } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight, Bike, ChefHat, CircleCheck, Clock, Globe, LayoutGrid } from '@lucide/vue';
import BarChart from '@/components/charts/BarChart.vue';
import RankList from '@/components/charts/RankList.vue';
import KpiTile from '@/components/KpiTile.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    live: { type: Object, default: null },
    sales: { type: Object, default: null },
    analytics: { type: Object, default: null },
    finance: { type: Object, default: null },
    lowStock: { type: Object, default: null },
    recent: { type: Array, default: () => [] },
    generatedAt: { type: String, required: true },
});

const page = usePage();
const { can } = usePermissions();

// A operação e as vendas de hoje mudam o tempo todo; o resto do painel não precisa ser refeito.
usePoll(60000, { only: ['live', 'sales', 'generatedAt'] }, { keepAlive: true });

const firstName = computed(() => page.props.auth.user.name.split(' ')[0]);
const todayLabel = new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
const updatedAt = computed(() => new Date(props.generatedAt).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }));

const liveTiles = computed(() => {
    const live = props.live;

    if (!live) {
        return [];
    }

    return [
        'online_pending' in live && { label: 'Aguardando aceite', value: live.online_pending, icon: Globe, href: route('online.orders'), alert: live.online_pending > 0 },
        'producing' in live && { label: 'Em produção', value: live.producing, icon: ChefHat, href: can('production.view') ? route('production.index') : route('deliveries.board') },
        'ready' in live && { label: 'Prontos para sair', value: live.ready, icon: CircleCheck, href: route('deliveries.board') },
        'out' in live && { label: 'Na rua', value: live.out, icon: Bike, href: route('deliveries.board') },
        'open_tables' in live && { label: 'Mesas ocupadas', value: live.open_tables, icon: LayoutGrid, href: route('tables.index') },
        'delivery_minutes' in live && { label: 'Tempo médio de entrega hoje', value: live.delivery_minutes === null ? '—' : `${live.delivery_minutes} min`, icon: Clock, href: null },
    ].filter(Boolean);
});

const salesTooltip = (row) => `${formatMoney(row.total)} · ${row.count} ${row.count === 1 ? 'venda' : 'vendas'}`;
const toPoints = (rows) => rows.map((row) => ({ label: row.label, value: Number(row.total), tooltip: salesTooltip(row) }));

const dailyPoints = computed(() => (props.analytics ? toPoints(props.analytics.daily) : []));
const dailyTotal = computed(() => (props.analytics ? props.analytics.daily.reduce((sum, row) => sum + Number(row.total), 0) : 0));
// Só as horas com movimento: a madrugada vazia comprimiria as barras.
const hourlyPoints = computed(() => {
    const rows = props.analytics?.hourly ?? [];
    const open = rows.filter((row) => row.count > 0).map((row) => row.hour);

    return open.length === 0 ? [] : toPoints(rows.filter((row) => row.hour >= Math.min(...open) && row.hour <= Math.max(...open)));
});

const financeAlerts = computed(() => {
    const finance = props.finance;

    if (!finance) {
        return [];
    }

    return [
        { label: 'Contas a pagar vencidas', ...finance.payable_overdue, tone: finance.payable_overdue.count > 0 ? 'bad' : null, href: route('bills.index', { type: 'pagar', status: 'overdue' }) },
        { label: 'A pagar nos próximos 7 dias', ...finance.payable_week, tone: null, href: route('bills.index', { type: 'pagar' }) },
        { label: 'A receber em aberto', ...finance.receivable_open, tone: null, href: route('bills.index', { type: 'receber' }) },
        { label: 'A receber vencido', ...finance.receivable_overdue, tone: finance.receivable_overdue.count > 0 ? 'warn' : null, href: route('bills.index', { type: 'receber', status: 'overdue' }) },
    ];
});

const nothingToShow = computed(() => !props.live && !props.sales && !props.analytics && !props.finance && !props.lowStock && props.recent.length === 0);
</script>

<template>
    <AppLayout title="Início">
        <PageHeader :title="`Olá, ${firstName}`" :subtitle="`${page.props.auth.company?.trade_name ?? ''}${page.props.auth.company ? ' · ' : ''}${todayLabel}`">
            <span class="text-muted text-xs">Atualizado às {{ updatedAt }}</span>
        </PageHeader>

        <section v-if="liveTiles.length" class="mb-6" aria-labelledby="live-title">
            <h2 id="live-title" class="text-muted mb-2 text-xs font-bold tracking-wide uppercase">Operação agora</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <component
                    :is="tile.href ? Link : 'div'"
                    v-for="tile in liveTiles"
                    :key="tile.label"
                    :href="tile.href"
                    class="card flex items-center gap-3 p-3"
                    :class="[tile.href ? 'hover:border-brand transition-colors' : '', tile.alert ? 'border-warn bg-warn-soft' : '']"
                >
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg" :class="tile.alert ? 'bg-warn text-white' : 'bg-brand-soft text-brand-strong'">
                        <component :is="tile.icon" :size="18" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xl leading-tight font-bold tabular-nums">{{ tile.value }}</span>
                        <span class="text-muted block truncate text-xs">{{ tile.label }}</span>
                    </span>
                </component>
            </div>
        </section>

        <section v-if="sales" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Vendas">
            <KpiTile
                label="Faturamento hoje"
                :value="formatMoney(sales.today.total)"
                :current="sales.today.total"
                :previous="sales.last_week.total"
                compared-to="vs. mesmo dia da semana passada"
                :href="route('sales.index')"
            />
            <KpiTile label="Vendas hoje" :value="sales.today.count" :current="sales.today.count" :previous="sales.last_week.count" compared-to="vs. semana passada" :href="route('sales.index')" />
            <KpiTile label="Ticket médio hoje" :value="formatMoney(sales.today.average)" :current="sales.today.count ? sales.today.average : null" :previous="sales.last_week.count ? sales.last_week.average : null" compared-to="vs. semana passada" />
            <KpiTile
                label="Faturamento do mês"
                :value="formatMoney(sales.month.total)"
                :current="sales.month.total"
                :previous="sales.last_month.total"
                compared-to="vs. mês passado até hoje"
                :note="`${sales.month.count} vendas · ticket ${formatMoney(sales.month.average)}`"
                :href="can('reports.view') ? route('reports.sales') : null"
            />
        </section>

        <template v-if="analytics">
            <div class="mb-4 grid gap-4 lg:grid-cols-3">
                <section class="card p-5 lg:col-span-2">
                    <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-bold">Vendas dos últimos 14 dias</h2>
                        <p class="text-muted text-sm tabular-nums">{{ formatMoney(dailyTotal) }}</p>
                    </div>
                    <BarChart :points="dailyPoints" label="Total vendido por dia nos últimos 14 dias" />
                </section>
                <section class="card p-5">
                    <h2 class="mb-4 font-bold">Canais de venda no mês</h2>
                    <RankList :rows="analytics.channels" empty="Nenhuma venda no mês ainda." />
                </section>
            </div>

            <div class="mb-6 grid gap-4 lg:grid-cols-3">
                <section class="card p-5">
                    <h2 class="mb-4 font-bold">Movimento por hora hoje</h2>
                    <BarChart v-if="hourlyPoints.length" :points="hourlyPoints" :height="150" label="Total vendido por hora hoje" />
                    <p v-else class="text-muted py-10 text-center text-sm">Nenhuma venda hoje ainda.</p>
                </section>
                <section class="card p-5">
                    <div class="mb-4 flex items-baseline justify-between gap-2">
                        <h2 class="font-bold">Mais vendidos no mês</h2>
                        <Link :href="route('reports.products')" class="text-brand text-sm font-semibold hover:underline">Ver todos</Link>
                    </div>
                    <p v-if="!analytics.top_products.length" class="text-muted py-6 text-center text-sm">Nenhuma venda no mês ainda.</p>
                    <ol v-else class="space-y-2.5">
                        <li v-for="(product, index) in analytics.top_products" :key="product.label" class="flex items-center gap-3 text-sm">
                            <span class="bg-canvas text-muted flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold">{{ index + 1 }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ product.label }}</span>
                                <span class="text-muted text-xs tabular-nums">{{ formatQuantity(product.quantity) }} vendidos</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums">{{ formatMoney(product.total) }}</span>
                        </li>
                    </ol>
                </section>
                <section class="card p-5">
                    <h2 class="mb-4 font-bold">Formas de pagamento no mês</h2>
                    <RankList :rows="analytics.payments" empty="Nenhum pagamento no mês ainda." />
                </section>
            </div>
        </template>

        <div v-if="finance || lowStock" class="mb-6 grid gap-4 lg:grid-cols-2">
            <section v-if="finance" class="card p-5">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h2 class="font-bold">Financeiro</h2>
                    <Link v-if="can('reports.finance')" :href="route('reports.finance')" class="text-brand text-sm font-semibold hover:underline">Relatório</Link>
                </div>
                <ul class="divide-line divide-y">
                    <li v-for="item in financeAlerts" :key="item.label">
                        <Link :href="item.href" class="group flex items-center justify-between gap-3 py-2.5 text-sm">
                            <span class="flex min-w-0 items-center gap-2">
                                <AlertTriangle v-if="item.tone" :size="16" :class="item.tone === 'bad' ? 'text-danger' : 'text-warn'" aria-hidden="true" />
                                <span class="truncate">{{ item.label }}</span>
                                <span class="text-muted text-xs">({{ item.count }})</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1 font-semibold tabular-nums" :class="{ 'text-danger': item.tone === 'bad', 'text-warn': item.tone === 'warn' }">
                                {{ formatMoney(item.total) }}
                                <ArrowRight :size="14" class="text-muted opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section v-if="lowStock" class="card p-5">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h2 class="font-bold">Estoque baixo <span class="text-muted text-sm font-normal">({{ lowStock.count }})</span></h2>
                    <Link v-if="lowStock.count" :href="route('stock.index', { low: 1 })" class="text-brand text-sm font-semibold hover:underline">Ver todos</Link>
                </div>
                <p v-if="!lowStock.count" class="text-ok flex items-center gap-2 py-4 text-sm font-medium">
                    <CircleCheck :size="16" aria-hidden="true" />
                    Nenhum produto abaixo do mínimo.
                </p>
                <ul v-else class="divide-line divide-y">
                    <li v-for="product in lowStock.items" :key="product.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="min-w-0 truncate">{{ product.name }}</span>
                        <span class="shrink-0 tabular-nums">
                            <span class="font-semibold" :class="Number(product.stock_quantity) < 0 ? 'text-danger' : 'text-warn'">{{ formatQuantity(product.stock_quantity, product.unit) }}</span>
                            <span v-if="Number(product.min_stock) > 0" class="text-muted text-xs"> / mín. {{ formatQuantity(product.min_stock, product.unit) }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <section v-if="can('audit.view') && recent.length">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-bold">Atividade recente</h2>
                <Link :href="route('audit.index')" class="text-brand text-sm font-semibold hover:underline">Ver tudo</Link>
            </div>
            <ul class="card divide-line divide-y">
                <li v-for="log in recent" :key="log.id" class="flex flex-col gap-0.5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <span class="min-w-0 text-sm">
                        <span class="font-semibold">{{ log.user }}</span>
                        <span class="text-muted"> · {{ log.description }}</span>
                    </span>
                    <time class="text-muted shrink-0 text-xs tabular-nums">{{ log.date }}</time>
                </li>
            </ul>
        </section>

        <div v-if="nothingToShow" class="card p-6 text-center">
            <p class="font-semibold">Bem-vindo!</p>
            <p class="text-muted mt-1 text-sm">Use o menu ao lado para acessar as telas do seu perfil.</p>
        </div>
    </AppLayout>
</template>
