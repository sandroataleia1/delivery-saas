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
import { usePermissions } from '@/lib/can';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, required: true },
});

const { can } = usePermissions();

const filters = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('users.index'), { ...filters }, { preserveState: true, replace: true });
    }, 300);
});

const target = ref(null);
const statusForm = useForm({});
const willDeactivate = computed(() => target.value?.active);

function toggleStatus() {
    statusForm.patch(route('users.status', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Usuários">
        <PageHeader title="Usuários" subtitle="Quem acessa o sistema da sua loja.">
            <Link v-if="can('users.manage')" :href="route('users.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo usuário
            </Link>
        </PageHeader>

        <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_12rem_12rem]">
            <input v-model="filters.search" class="input" type="search" placeholder="Buscar por nome ou e-mail" aria-label="Buscar usuário" />
            <select v-model="filters.role" class="input" aria-label="Filtrar por perfil">
                <option value="">Todos os perfis</option>
                <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
            </select>
            <select v-model="filters.status" class="input" aria-label="Filtrar por situação">
                <option value="">Todas as situações</option>
                <option value="ativos">Ativos</option>
                <option value="inativos">Inativos</option>
            </select>
        </div>

        <EmptyState v-if="users.data.length === 0" title="Nenhum usuário encontrado" text="Ajuste os filtros da busca." />

        <div v-else class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th class="max-sm:hidden">Perfil</th>
                        <th>Situação</th>
                        <th class="max-md:hidden">Último acesso</th>
                        <th v-if="can('users.manage')"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users.data" :key="user.id">
                        <td>
                            <p class="font-semibold">{{ user.name }}</p>
                            <p class="text-muted text-xs break-all">{{ user.email }}</p>
                            <p class="text-muted text-xs sm:hidden">{{ user.role ?? 'Sem perfil' }}</p>
                        </td>
                        <td class="whitespace-nowrap max-sm:hidden">{{ user.role ?? '—' }}</td>
                        <td><StatusBadge :active="user.active" /></td>
                        <td class="text-muted whitespace-nowrap tabular-nums max-md:hidden">{{ user.last_login_at ?? 'Nunca entrou' }}</td>
                        <td v-if="can('users.manage')" class="text-right whitespace-nowrap">
                            <template v-if="user.can_manage">
                                <Link
                                    :href="route('users.edit', user.id)"
                                    class="btn btn-ghost"
                                    :aria-label="`Editar ${user.name}`"
                                    title="Editar"
                                >
                                    <Pencil :size="16" />
                                </Link>
                                <button
                                    v-if="!user.is_self"
                                    type="button"
                                    class="btn btn-ghost"
                                    :aria-label="`${user.active ? 'Inativar' : 'Ativar'} ${user.name}`"
                                    :title="user.active ? 'Inativar' : 'Ativar'"
                                    @click="target = user"
                                >
                                    <Power :size="16" />
                                </button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :meta="users" />

        <ConfirmDialog
            :open="target !== null"
            :title="willDeactivate ? `Inativar ${target?.name}?` : `Ativar ${target?.name}?`"
            :message="willDeactivate ? 'A pessoa perde o acesso na hora, e o histórico dela continua na auditoria.' : 'A pessoa volta a acessar o sistema.'"
            :confirm-label="willDeactivate ? 'Inativar' : 'Ativar'"
            :danger="willDeactivate"
            :processing="statusForm.processing"
            @confirm="toggleStatus"
            @cancel="target = null"
        />
    </AppLayout>
</template>
