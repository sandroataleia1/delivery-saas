<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatPhone } from '@/lib/format';

const props = defineProps({
    customers: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const { can } = usePermissions();
const search = ref(props.filters.search ?? '');
let timer = null;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('customers.index'), value ? { search: value } : {}, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <AppLayout title="Clientes">
        <PageHeader title="Clientes" subtitle="Quem compra da sua loja.">
            <Link v-if="can('customers.manage')" :href="route('customers.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo cliente
            </Link>
        </PageHeader>

        <input v-model="search" class="input mb-4 max-w-sm" type="search" placeholder="Buscar por nome ou telefone" aria-label="Buscar cliente" />

        <EmptyState v-if="customers.data.length === 0" title="Nenhum cliente encontrado" />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th class="max-sm:hidden">Telefone</th>
                        <th>Fiado</th>
                        <th class="text-right">Deve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="customer in customers.data" :key="customer.id">
                        <td><Link :href="route('customers.show', customer.id)" class="text-brand font-semibold hover:underline">{{ customer.name }}</Link></td>
                        <td class="whitespace-nowrap tabular-nums max-sm:hidden">{{ formatPhone(customer.phone) || '—' }}</td>
                        <td>
                            <span class="badge" :class="customer.allow_credit ? 'bg-ok-soft text-ok' : 'bg-canvas text-muted'">{{ customer.allow_credit ? 'Liberado' : 'Não' }}</span>
                        </td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums">{{ formatMoney(customer.open_credit) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="customers" />
    </AppLayout>
</template>
