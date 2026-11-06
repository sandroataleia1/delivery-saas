<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatQuantity } from '@/lib/format';

const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Object, required: true },
    selectedProduct: { type: Object, default: null },
});

const filters = reactive({
    product: props.filters.product ?? '',
    type: props.filters.type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('stock.movements'), query, { preserveState: true, replace: true });
    }, 300);
});

const signed = (movement) => `${Number(movement.quantity) > 0 ? '+' : ''}${formatQuantity(movement.quantity, movement.unit)}`;
</script>

<template>
    <AppLayout title="Movimentações">
        <PageHeader title="Movimentações de estoque" subtitle="Tudo o que entrou e saiu. Os lançamentos não podem ser alterados." />

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <select v-model="filters.type" class="input" aria-label="Filtrar por tipo">
                <option value="">Todos os tipos</option>
                <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <p v-if="selectedProduct" class="mb-4 text-sm">
            <button type="button" class="badge bg-brand-soft text-brand-strong cursor-pointer gap-1" @click="filters.product = ''">
                {{ selectedProduct.name }}
                <X :size="12" />
                <span class="sr-only">Remover filtro de produto</span>
            </button>
        </p>

        <EmptyState v-if="movements.data.length === 0" title="Nenhuma movimentação encontrada" text="Ajuste os filtros da busca." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="max-md:hidden">Tipo</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right max-sm:hidden">Saldo depois</th>
                        <th class="max-lg:hidden">Usuário</th>
                        <th class="max-sm:hidden">Data</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="movement in movements.data" :key="movement.id">
                        <td>
                            <p class="font-semibold">{{ movement.product }}</p>
                            <p class="text-muted text-xs md:hidden">{{ movement.type }}</p>
                            <p v-if="movement.notes" class="text-muted text-xs">{{ movement.notes }}</p>
                            <Link v-if="movement.sale_number" :href="route('sales.show', movement.sale_id)" class="text-brand text-xs font-semibold hover:underline">
                                Venda #{{ movement.sale_number }}
                            </Link>
                            <Link v-if="movement.stock_entry_id" :href="route('entries.show', movement.stock_entry_id)" class="text-brand text-xs font-semibold hover:underline">
                                Entrada #{{ movement.stock_entry_id }}
                            </Link>
                        </td>
                        <td class="whitespace-nowrap max-md:hidden">{{ movement.type }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="Number(movement.quantity) > 0 ? 'text-ok' : 'text-danger'">{{ signed(movement) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatQuantity(movement.balance_after, movement.unit) }}</td>
                        <td class="whitespace-nowrap max-lg:hidden">{{ movement.user }}</td>
                        <td class="text-muted whitespace-nowrap tabular-nums max-sm:hidden">{{ movement.date }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="movements" />
    </AppLayout>
</template>
