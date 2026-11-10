<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Check, Plus } from '@lucide/vue';
import BillCreateDialog from '@/components/BillCreateDialog.vue';
import BillStatus from '@/components/BillStatus.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SettleDialog from '@/components/SettleDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    type: { type: String, required: true },
    bills: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    categories: { type: Array, required: true },
    suppliers: { type: Array, default: () => [] },
    methods: { type: Object, required: true },
    today: { type: String, required: true },
});

const { can } = usePermissions();
const isPayable = computed(() => props.type === 'pagar');
const title = computed(() => (isPayable.value ? 'Contas a pagar' : 'Contas a receber'));

const filters = reactive({
    status: props.filters.status ?? '',
    search: props.filters.search ?? '',
    category: props.filters.category ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('bills.index', props.type), query, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const createDialog = ref(null);
</script>

<template>
    <AppLayout :title="title">
        <PageHeader :title="title" :subtitle="isPayable ? 'O que sua loja deve.' : 'O que sua loja tem para receber.'">
            <button v-if="can('finance.manage')" type="button" class="btn btn-primary" @click="createDialog.open()">
                <Plus :size="16" />
                Nova conta
            </button>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-muted text-sm">Em aberto</p>
                <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl">{{ formatMoney(summary.open) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Vencidas</p>
                <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl" :class="{ 'text-danger': Number(summary.overdue) > 0 }">{{ formatMoney(summary.overdue) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">Próximos 7 dias</p>
                <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl">{{ formatMoney(summary.next_days) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-muted text-sm">{{ isPayable ? 'Pago no mês' : 'Recebido no mês' }}</p>
                <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl">{{ formatMoney(summary.paid_month) }}</p>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <input v-model="filters.search" class="input" type="search" placeholder="Buscar" aria-label="Buscar conta" />
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todas as situações</option>
                <option value="open">Em aberto</option>
                <option value="overdue">Vencidas</option>
                <option value="paid">Quitadas</option>
                <option value="cancelled">Canceladas</option>
            </select>
            <select v-model="filters.category" class="input" aria-label="Filtrar por categoria">
                <option value="">Todas as categorias</option>
                <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Vencimento a partir de" />
            <input v-model="filters.to" class="input" type="date" aria-label="Vencimento até" />
        </div>

        <EmptyState v-if="bills.data.length === 0" title="Nenhuma conta encontrada" text="Ajuste os filtros ou cadastre uma conta." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Conta</th>
                        <th class="max-md:hidden">Vencimento</th>
                        <th class="max-sm:hidden">Situação</th>
                        <th class="text-right">Saldo / valor original</th>
                        <th v-if="can('finance.manage')"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bill in bills.data" :key="bill.id">
                        <td>
                            <Link :href="route('bills.show', bill.id)" class="text-brand font-semibold hover:underline">{{ bill.description }}</Link>
                            <p class="text-muted text-xs">{{ bill.party ?? bill.category }}</p>
                            <p class="text-muted text-xs tabular-nums md:hidden">Vence em {{ bill.due_date }}</p>
                            <span class="sm:hidden"><BillStatus :status="bill.status" :label="bill.status_label" /></span>
                        </td>
                        <td class="whitespace-nowrap tabular-nums max-md:hidden">{{ bill.due_date }}</td>
                        <td class="max-sm:hidden"><BillStatus :status="bill.status" :label="bill.status_label" /></td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="{ 'text-muted line-through': bill.status === 'cancelled' }">
                            {{ formatMoney(bill.status === 'open' || bill.status === 'overdue' ? bill.balance : bill.amount) }}
                            <p v-if="Number(bill.paid_amount) > 0 && (bill.status === 'open' || bill.status === 'overdue')" class="text-muted text-xs">Original: {{ formatMoney(bill.amount) }}</p>
                            <p v-if="bill.legacy_review_required" class="text-danger text-xs">Reconciliação pendente</p>
                        </td>
                        <td v-if="can('finance.manage')" class="text-right whitespace-nowrap">
                            <button
                                v-if="(bill.status === 'open' || bill.status === 'overdue') && !bill.legacy_review_required"
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`Dar baixa em ${bill.description}`"
                                title="Dar baixa"
                                @click="target = bill"
                            >
                                <Check :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="bills" />

        <SettleDialog :bill="target" :methods="methods" :today="today" @close="target = null" />
        <BillCreateDialog v-if="can('finance.manage')" ref="createDialog" :type="type" :categories="categories" :suppliers="suppliers" />
    </AppLayout>
</template>
