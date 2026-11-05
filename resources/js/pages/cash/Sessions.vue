<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    sessions: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const filters = reactive({
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(route('cash.sessions'), query, { preserveState: true, replace: true });
    }, 300);
});

const differenceClass = (value) => (Number(value) === 0 ? 'text-muted' : Number(value) < 0 ? 'text-danger' : 'text-ok');
</script>

<template>
    <AppLayout title="Histórico de caixas">
        <PageHeader title="Histórico de caixas" subtitle="Aberturas e fechamentos." />

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todos</option>
                <option value="open">Abertos</option>
                <option value="closed">Fechados</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <EmptyState v-if="sessions.data.length === 0" title="Nenhum caixa encontrado" />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Caixa</th>
                        <th class="max-sm:hidden">Abertura</th>
                        <th class="max-md:hidden">Fechamento</th>
                        <th class="text-right">Diferença</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="session in sessions.data" :key="session.id">
                        <td>
                            <Link :href="route('cash.show', session.id)" class="text-brand font-semibold hover:underline">#{{ session.number }}</Link>
                            <span v-if="session.is_open" class="badge bg-ok-soft text-ok ml-2">Aberto</span>
                            <p class="text-muted text-xs">{{ session.user }}</p>
                        </td>
                        <td class="whitespace-nowrap tabular-nums max-sm:hidden">{{ session.opened_at }}</td>
                        <td class="whitespace-nowrap tabular-nums max-md:hidden">{{ session.closed_at ?? '—' }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="differenceClass(session.difference)">
                            {{ session.is_open ? '—' : formatMoney(session.difference) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="sessions" />
    </AppLayout>
</template>
