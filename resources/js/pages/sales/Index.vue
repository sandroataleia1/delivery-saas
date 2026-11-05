<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    sales: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    methods: { type: Object, required: true },
    users: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = reactive({
    number: props.filters.number ?? '',
    status: props.filters.status ?? '',
    method: props.filters.method ?? '',
    user: props.filters.user ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('sales.index'), query, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <AppLayout title="Vendas">
        <PageHeader title="Vendas" subtitle="Histórico do que foi vendido.">
            <Link v-if="can('sales.create')" :href="route('pos')" class="btn btn-primary">
                <ShoppingCart :size="16" />
                Abrir PDV
            </Link>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="card p-4">
                <p class="text-muted text-sm">Vendas concluídas</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ summary.count }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Descontos</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ formatMoney(summary.discount) }}</p>
            </div>
            <div class="card col-span-2 p-4 lg:col-span-1">
                <p class="text-muted text-sm">Total vendido</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ formatMoney(summary.total) }}</p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <input v-model="filters.number" class="input" inputmode="numeric" placeholder="Nº da venda" aria-label="Número da venda" />
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todas</option>
                <option value="completed">Concluídas</option>
                <option value="cancelled">Canceladas</option>
            </select>
            <select v-model="filters.method" class="input" aria-label="Filtrar por pagamento">
                <option value="">Todos os pagamentos</option>
                <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
            </select>
            <select v-model="filters.user" class="input" aria-label="Filtrar por operador">
                <option value="">Todos os operadores</option>
                <option v-for="user in users" :key="user.user_id" :value="user.user_id">{{ user.user_name }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <EmptyState v-if="sales.data.length === 0" title="Nenhuma venda encontrada" text="Ajuste os filtros ou faça uma venda no PDV." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Venda</th>
                        <th class="max-md:hidden">Operador</th>
                        <th class="max-sm:hidden">Pagamento</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="sale in sales.data" :key="sale.id">
                        <td>
                            <Link :href="route('sales.show', sale.id)" class="text-brand font-semibold hover:underline">#{{ sale.number }}</Link>
                            <span v-if="sale.cancelled" class="badge bg-danger-soft text-danger ml-2">Cancelada</span>
                            <p class="text-muted text-xs tabular-nums">{{ sale.date }}</p>
                        </td>
                        <td class="whitespace-nowrap max-md:hidden">{{ sale.user }}</td>
                        <td class="max-sm:hidden">{{ sale.payments || '—' }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="{ 'text-muted line-through': sale.cancelled }">{{ formatMoney(sale.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="sales" />
    </AppLayout>
</template>
