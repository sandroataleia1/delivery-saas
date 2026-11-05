<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    suppliers: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = reactive({
    search: props.filters.search ?? '',
    supplier: props.filters.supplier ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('entries.index'), query, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <AppLayout title="Entradas">
        <PageHeader title="Entradas de mercadorias" subtitle="Compras que abastecem o estoque.">
            <Link v-if="can('entries.manage')" :href="route('entries.create')" class="btn btn-primary">
                <Plus :size="16" />
                Nova entrada
            </Link>
        </PageHeader>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input v-model="filters.search" class="input" type="search" placeholder="Número da nota" aria-label="Buscar por número da nota" />
            <select v-model="filters.supplier" class="input" aria-label="Filtrar por fornecedor">
                <option value="">Todos os fornecedores</option>
                <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <EmptyState v-if="entries.data.length === 0" title="Nenhuma entrada encontrada" text="Lance uma entrada quando receber mercadorias." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Entrada</th>
                        <th class="max-md:hidden">Fornecedor</th>
                        <th class="max-sm:hidden">Nota</th>
                        <th class="text-right max-sm:hidden">Itens</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td>
                            <Link :href="route('entries.show', entry.id)" class="text-brand font-semibold hover:underline">#{{ entry.id }}</Link>
                            <span v-if="entry.cancelled" class="badge bg-danger-soft text-danger ml-2">Cancelada</span>
                            <p class="text-muted text-xs tabular-nums">{{ entry.date }}</p>
                        </td>
                        <td class="max-md:hidden">{{ entry.supplier ?? 'Sem fornecedor' }}</td>
                        <td class="whitespace-nowrap max-sm:hidden">{{ entry.invoice_number ?? '—' }}</td>
                        <td class="text-right tabular-nums max-sm:hidden">{{ entry.items_count }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="{ 'text-muted line-through': entry.cancelled }">{{ formatMoney(entry.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="entries" />
    </AppLayout>
</template>
