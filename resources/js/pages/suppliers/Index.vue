<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCnpj, formatPhone } from '@/lib/format';

const props = defineProps({
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('suppliers.index'), query, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const statusForm = useForm({});
const willDeactivate = computed(() => target.value?.active);

function toggleStatus() {
    statusForm.patch(route('suppliers.status', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Fornecedores">
        <PageHeader title="Fornecedores" subtitle="De quem sua loja compra.">
            <Link :href="route('suppliers.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo fornecedor
            </Link>
        </PageHeader>

        <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_12rem]">
            <input v-model="filters.search" class="input" type="search" placeholder="Buscar por nome ou CNPJ" aria-label="Buscar fornecedor" />
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todas as situações</option>
                <option value="ativos">Ativos</option>
                <option value="inativos">Inativos</option>
            </select>
        </div>

        <EmptyState v-if="suppliers.data.length === 0" title="Nenhum fornecedor encontrado" text="Ajuste os filtros ou cadastre um fornecedor." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Fornecedor</th>
                        <th class="max-md:hidden">Telefone</th>
                        <th class="max-sm:hidden">Situação</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="supplier in suppliers.data" :key="supplier.id">
                        <td>
                            <p class="font-semibold">{{ supplier.name }}</p>
                            <p class="text-muted text-xs tabular-nums">{{ supplier.document ? formatCnpj(supplier.document) : 'Sem CNPJ' }}</p>
                        </td>
                        <td class="whitespace-nowrap tabular-nums max-md:hidden">{{ formatPhone(supplier.phone) || '—' }}</td>
                        <td class="max-sm:hidden"><StatusBadge :active="supplier.active" /></td>
                        <td class="text-right whitespace-nowrap">
                            <Link :href="route('suppliers.edit', supplier.id)" class="btn btn-ghost" :aria-label="`Editar ${supplier.name}`" title="Editar">
                                <Pencil :size="16" />
                            </Link>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`${supplier.active ? 'Inativar' : 'Ativar'} ${supplier.name}`"
                                :title="supplier.active ? 'Inativar' : 'Ativar'"
                                @click="target = supplier"
                            >
                                <Power :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="suppliers" />

        <ConfirmDialog
            :open="target !== null"
            :title="willDeactivate ? `Inativar ${target?.name}?` : `Ativar ${target?.name}?`"
            :message="willDeactivate ? 'O fornecedor deixa de aparecer nas novas entradas. O histórico é mantido.' : 'O fornecedor volta a aparecer nas novas entradas.'"
            :confirm-label="willDeactivate ? 'Inativar' : 'Ativar'"
            :danger="willDeactivate"
            :processing="statusForm.processing"
            @confirm="toggleStatus"
            @cancel="target = null"
        />
    </AppLayout>
</template>
