<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCnpj } from '@/lib/format';

const props = defineProps({
    companies: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.search ?? '');
let timer = null;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('admin.companies.index'), value ? { search: value } : {}, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const statusForm = useForm({});

function toggleStatus() {
    statusForm.patch(route('admin.companies.status', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Empresas">
        <PageHeader title="Empresas" subtitle="Clientes da plataforma.">
            <Link :href="route('admin.companies.create')" class="btn btn-primary">
                <Plus :size="16" />
                Nova empresa
            </Link>
        </PageHeader>

        <input v-model="search" class="input mb-4 max-w-sm" type="search" placeholder="Buscar por nome ou CNPJ" aria-label="Buscar empresa" />

        <EmptyState v-if="companies.data.length === 0" title="Nenhuma empresa encontrada" />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th class="max-md:hidden">CNPJ</th>
                        <th class="max-sm:hidden">Usuários</th>
                        <th>Situação</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="company in companies.data" :key="company.id">
                        <td>
                            <p class="font-semibold">{{ company.trade_name }}</p>
                            <p class="text-muted text-xs">{{ company.legal_name }}</p>
                        </td>
                        <td class="whitespace-nowrap tabular-nums max-md:hidden">{{ formatCnpj(company.document) }}</td>
                        <td class="tabular-nums max-sm:hidden">{{ company.users_count }}</td>
                        <td><StatusBadge :active="company.active" /></td>
                        <td class="text-right whitespace-nowrap">
                            <Link
                                :href="route('admin.companies.edit', company.id)"
                                class="btn btn-ghost"
                                :aria-label="`Editar ${company.trade_name}`"
                                title="Editar"
                            >
                                <Pencil :size="16" />
                            </Link>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`${company.active ? 'Inativar' : 'Ativar'} ${company.trade_name}`"
                                :title="company.active ? 'Inativar' : 'Ativar'"
                                @click="target = company"
                            >
                                <Power :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="companies" />

        <ConfirmDialog
            :open="target !== null"
            :title="target?.active ? `Inativar ${target?.trade_name}?` : `Ativar ${target?.trade_name}?`"
            :message="target?.active ? 'Todos os usuários da empresa perdem o acesso na hora. Os dados são mantidos.' : 'Os usuários da empresa voltam a acessar o sistema.'"
            :confirm-label="target?.active ? 'Inativar' : 'Ativar'"
            :danger="target?.active"
            :processing="statusForm.processing"
            @confirm="toggleStatus"
            @cancel="target = null"
        />
    </AppLayout>
</template>
