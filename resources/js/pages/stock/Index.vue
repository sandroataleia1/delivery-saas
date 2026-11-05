<script setup>
import { reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { History, PackagePlus, SlidersHorizontal } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
});

const { can } = usePermissions();

const filters = reactive({
    search: props.filters.search ?? '',
    low: Boolean(props.filters.low),
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = {};

        if (filters.search) {
            query.search = filters.search;
        }

        if (filters.low) {
            query.low = 1;
        }

        router.get(route('stock.index'), query, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const adjustForm = useForm({ type: 'adjustment_in', quantity: '', notes: '', stock: null });

function openAdjust(product) {
    adjustForm.reset();
    adjustForm.clearErrors();
    target.value = product;
}

function adjust() {
    adjustForm.post(route('stock.adjust', target.value.id), {
        preserveScroll: true,
        onSuccess: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Estoque">
        <PageHeader title="Estoque" subtitle="Saldo atual de cada produto.">
            <Link :href="route('stock.movements')" class="btn btn-secondary">
                <History :size="16" />
                Movimentações
            </Link>
            <Link v-if="can('entries.manage')" :href="route('entries.create')" class="btn btn-primary">
                <PackagePlus :size="16" />
                Nova entrada
            </Link>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="card p-4">
                <p class="text-muted text-sm">Produtos controlados</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ summary.products }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Com estoque baixo</p>
                <p class="mt-1 text-3xl font-bold tabular-nums" :class="{ 'text-danger': summary.low > 0 }">{{ summary.low }}</p>
            </div>
            <div class="card col-span-2 p-4 lg:col-span-1">
                <p class="text-muted text-sm">Valor em estoque (custo)</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ formatMoney(summary.value) }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <input v-model="filters.search" class="input sm:max-w-sm" type="search" placeholder="Buscar por nome ou código" aria-label="Buscar produto" />
            <label class="flex cursor-pointer items-center gap-2 text-sm">
                <input v-model="filters.low" type="checkbox" class="accent-brand size-4" />
                Só estoque baixo
            </label>
        </div>

        <EmptyState v-if="products.data.length === 0" title="Nenhum produto encontrado" text="Só aparecem produtos ativos com controle de estoque." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="text-right">Saldo</th>
                        <th class="text-right max-sm:hidden">Mínimo</th>
                        <th class="text-right max-md:hidden">Custo médio</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="product in products.data" :key="product.id">
                        <td>
                            <p class="font-semibold">{{ product.name }}</p>
                            <span v-if="product.is_negative" class="badge bg-danger text-white mt-0.5">Estoque negativo</span>
                            <span v-else-if="product.is_low" class="badge bg-danger-soft text-danger mt-0.5">Estoque baixo</span>
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="{ 'text-danger': product.is_negative }">{{ formatQuantity(product.stock_quantity, product.unit) }}</td>
                        <td class="text-muted text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatQuantity(product.min_stock, product.unit) }}</td>
                        <td class="text-muted text-right whitespace-nowrap tabular-nums max-md:hidden">{{ formatMoney(product.cost_price) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <Link
                                :href="route('stock.movements', { product: product.id })"
                                class="btn btn-ghost"
                                :aria-label="`Histórico de ${product.name}`"
                                title="Histórico"
                            >
                                <History :size="16" />
                            </Link>
                            <button
                                v-if="can('stock.adjust')"
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`Ajustar ${product.name}`"
                                title="Ajustar"
                                @click="openAdjust(product)"
                            >
                                <SlidersHorizontal :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="products" />

        <ConfirmDialog
            :open="target !== null"
            :title="`Ajustar ${target?.name ?? ''}`"
            :message="target ? `Saldo atual: ${formatQuantity(target.stock_quantity, target.unit)}` : null"
            confirm-label="Confirmar ajuste"
            :processing="adjustForm.processing"
            @confirm="adjust"
            @cancel="target = null"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Tipo" required :error="adjustForm.errors.type">
                    <select :id="id" v-model="adjustForm.type" class="input">
                        <option value="adjustment_in">Ajuste de entrada (+)</option>
                        <option value="adjustment_out">Ajuste de saída (−)</option>
                        <option value="loss">Perda (−)</option>
                    </select>
                </Field>

                <Field v-slot="{ id }" label="Quantidade" required :error="adjustForm.errors.quantity">
                    <DecimalInput :id="id" v-model="adjustForm.quantity" :decimals="3" />
                </Field>

                <Field v-slot="{ id }" label="Motivo" required :error="adjustForm.errors.notes" hint="Fica registrado na auditoria.">
                    <input :id="id" v-model="adjustForm.notes" class="input" maxlength="255" />
                </Field>

                <p v-if="adjustForm.errors.stock" class="text-danger text-sm" role="alert">{{ adjustForm.errors.stock }}</p>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
