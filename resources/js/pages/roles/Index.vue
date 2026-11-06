<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Lock, Pencil, Plus, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

defineProps({
    roles: { type: Array, required: true },
});

const { can } = usePermissions();
const target = ref(null);
const deleteForm = useForm({});

function destroy() {
    deleteForm.delete(route('roles.destroy', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Perfis de acesso">
        <PageHeader title="Perfis de acesso" subtitle="O que cada função pode fazer no sistema.">
            <Link v-if="can('roles.manage')" :href="route('roles.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo perfil
            </Link>
        </PageHeader>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Perfil</th>
                        <th>Usuários</th>
                        <th>Permissões</th>
                        <th v-if="can('roles.manage')"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="role in roles" :key="role.id">
                        <td>
                            <p class="font-semibold">{{ role.name }}</p>
                            <p class="text-muted text-xs">{{ role.description }}</p>
                        </td>
                        <td class="tabular-nums">{{ role.users_count }}</td>
                        <td class="whitespace-nowrap">{{ role.is_admin ? 'Todas' : role.permissions_count }}</td>
                        <td v-if="can('roles.manage')" class="text-right whitespace-nowrap">
                            <template v-if="role.can_manage">
                                <Link :href="route('roles.edit', role.id)" class="btn btn-ghost" :aria-label="`Editar ${role.name}`" title="Editar">
                                    <Pencil :size="16" />
                                </Link>
                                <button type="button" class="btn btn-ghost" :aria-label="`Excluir ${role.name}`" title="Excluir" @click="target = role">
                                    <Trash2 :size="16" />
                                </button>
                            </template>
                            <span v-else-if="role.is_admin" class="text-muted inline-flex items-center gap-1 px-2 text-xs">
                                <Lock :size="14" />
                                Fixo
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="target !== null"
            :title="`Excluir o perfil ${target?.name}?`"
            message="Esta ação não pode ser desfeita, mas ficará registrada na auditoria."
            confirm-label="Excluir"
            danger
            :processing="deleteForm.processing"
            @confirm="destroy"
            @cancel="target = null"
        />
    </AppLayout>
</template>
