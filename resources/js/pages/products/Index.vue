<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Tags } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    canSeeCost: { type: Boolean, required: true },
});

const { can } = usePermissions();

const filters = reactive({
    search: props.filters.search ?? '',
    category: props.filters.category ?? '',
    status: props.filters.status ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('products.index'), query, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const statusForm = useForm({});
const willDeactivate = computed(() => target.value?.active);

function toggleStatus() {
    statusForm.patch(route('products.status', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Produtos">
        <PageHeader title="Produtos" subtitle="O que sua loja vende e compra.">
            <Link :href="route('categories.index')" class="btn btn-secondary">
                <Tags :size="16" />
                Categorias
            </Link>
            <Link v-if="can('products.manage')" :href="route('products.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo produto
            </Link>
        </PageHeader>

        <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_12rem_12rem]">
            <input v-model="filters.search" class="input" type="search" placeholder="Buscar por nome ou código" aria-label="Buscar produto" />
            <select v-model="filters.category" class="input" aria-label="Filtrar por categoria">
                <option value="">Todas as categorias</option>
                <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todas as situações</option>
                <option value="ativos">Ativos</option>
                <option value="inativos">Inativos</option>
            </select>
        </div>

        <EmptyState v-if="products.data.length === 0" title="Nenhum produto encontrado" text="Ajuste os filtros ou cadastre um produto." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="max-md:hidden">Categoria</th>
                        <th class="text-right">Venda</th>
                        <th v-if="canSeeCost" class="text-right max-lg:hidden">Custo médio</th>
                        <th v-if="canSeeCost" class="text-right max-sm:hidden">Estoque</th>
                        <th class="max-sm:hidden">Situação</th>
                        <th v-if="can('products.manage')"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="product in products.data" :key="product.id">
                        <td>
                            <p class="font-semibold">{{ product.name }}</p>
                            <p class="text-muted text-xs">{{ product.barcode ?? product.sku ?? product.unit }}</p>
                            <span v-if="product.has_recipe" class="badge bg-brand-soft text-brand-strong mt-0.5">Ficha técnica</span>
                        </td>
                        <td class="whitespace-nowrap max-md:hidden">{{ product.category ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(product.sale_price) }}</td>
                        <td v-if="canSeeCost" class="text-right whitespace-nowrap tabular-nums max-lg:hidden">{{ formatMoney(product.cost_price) }}</td>
                        <td v-if="canSeeCost" class="text-right whitespace-nowrap tabular-nums max-sm:hidden">
                            {{ product.track_stock ? formatQuantity(product.stock_quantity, product.unit) : product.has_recipe ? 'Pelos insumos' : 'Não controla' }}
                        </td>
                        <td class="max-sm:hidden"><StatusBadge :active="product.active" /></td>
                        <td v-if="can('products.manage')" class="text-right whitespace-nowrap">
                            <Link :href="route('products.edit', product.id)" class="btn btn-ghost" :aria-label="`Editar ${product.name}`" title="Editar">
                                <Pencil :size="16" />
                            </Link>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`${product.active ? 'Inativar' : 'Ativar'} ${product.name}`"
                                :title="product.active ? 'Inativar' : 'Ativar'"
                                @click="target = product"
                            >
                                <Power :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="products" />

        <ConfirmDialog
            :open="target !== null"
            :title="willDeactivate ? `Inativar ${target?.name}?` : `Ativar ${target?.name}?`"
            :message="willDeactivate ? 'O produto sai das telas de venda e de entrada. O histórico é mantido.' : 'O produto volta a aparecer nas telas de venda e de entrada.'"
            :confirm-label="willDeactivate ? 'Inativar' : 'Ativar'"
            :danger="willDeactivate"
            :processing="statusForm.processing"
            @confirm="toggleStatus"
            @cancel="target = null"
        />
    </AppLayout>
</template>
