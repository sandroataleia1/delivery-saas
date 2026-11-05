<script setup>
import { Link } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import BillStatus from '@/components/BillStatus.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatPhone } from '@/lib/format';

defineProps({
    customer: { type: Object, required: true },
    bills: { type: Array, required: true },
});

const { can } = usePermissions();
</script>

<template>
    <AppLayout :title="customer.name">
        <PageHeader :title="customer.name" :subtitle="formatPhone(customer.phone)">
            <Link v-if="can('customers.manage')" :href="route('customers.edit', customer.id)" class="btn btn-secondary">
                <Pencil :size="16" />
                Editar
            </Link>
        </PageHeader>

        <div class="mb-6 grid max-w-3xl grid-cols-2 gap-3 lg:grid-cols-3">
            <div class="card p-4">
                <p class="text-muted text-sm">Deve hoje</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(customer.open_credit) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Limite de fiado</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ !customer.allow_credit ? 'Não liberado' : customer.credit_limit === null ? 'Sem limite' : formatMoney(customer.credit_limit) }}</p>
            </div>
            <div class="card col-span-2 p-4 lg:col-span-1">
                <p class="text-muted text-sm">Prazo</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ customer.credit_days }} dias</p>
            </div>
        </div>

        <h2 class="mb-3 text-lg font-bold">Endereços</h2>
        <EmptyState v-if="customer.addresses.length === 0" title="Nenhum endereço cadastrado" />
        <ul v-else class="card divide-line mb-6 max-w-3xl divide-y">
            <li v-for="address in customer.addresses" :key="address.id" class="px-4 py-3 text-sm">
                {{ address.street }}, {{ address.number }}{{ address.complement ? ` - ${address.complement}` : '' }} - {{ address.district }}
                <span v-if="address.reference" class="text-muted block text-xs">{{ address.reference }}</span>
            </li>
        </ul>

        <h2 class="mt-6 mb-3 text-lg font-bold">Fiado</h2>
        <EmptyState v-if="bills.length === 0" title="Nenhuma venda fiado" />
        <div v-else class="table-wrap max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Conta</th>
                        <th class="max-sm:hidden">Vencimento</th>
                        <th>Situação</th>
                        <th class="text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bill in bills" :key="bill.id">
                        <td>
                            <Link v-if="can('finance.view')" :href="route('bills.show', bill.id)" class="text-brand font-semibold hover:underline">{{ bill.description }}</Link>
                            <span v-else class="font-semibold">{{ bill.description }}</span>
                        </td>
                        <td class="whitespace-nowrap tabular-nums max-sm:hidden">{{ bill.due_date }}</td>
                        <td><BillStatus :status="bill.status" :label="bill.status_label" /></td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums">{{ formatMoney(bill.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
