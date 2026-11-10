<script setup>
import { nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { KeyRound, Lock, Plus, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

defineProps({
    roles: { type: Array, required: true },
});

const { can } = usePermissions();
const target = ref(null);
const deleteForm = useForm({});

/* Novo perfil: só nome e descrição; as permissões são definidas depois, abrindo o perfil na lista. */
const createDialog = ref(null);
const nameInput = ref(null);
const createForm = useForm({ name: '', description: '', permissions: [] });

async function openCreate() {
    createForm.name = '';
    createForm.description = '';
    createForm.clearErrors();
    createDialog.value?.showModal();
    await nextTick();
    nameInput.value?.focus();
}

function closeCreate() {
    createDialog.value?.close();
}

function store() {
    createForm
        .transform((data) => ({ ...data, description: data.description || null, permissions: [] }))
        .post(route('roles.store'), { preserveScroll: true, onSuccess: closeCreate });
}

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
            <button v-if="can('roles.manage')" type="button" class="btn btn-primary" @click="openCreate">
                <Plus :size="16" />
                Novo perfil
            </button>
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
                            <Link v-if="role.can_manage" :href="route('roles.edit', role.id)" class="text-brand font-semibold hover:underline">{{ role.name }}</Link>
                            <p v-else class="font-semibold">{{ role.name }}</p>
                            <p class="text-muted text-xs">{{ role.description }}</p>
                        </td>
                        <td class="tabular-nums">{{ role.users_count }}</td>
                        <td class="whitespace-nowrap">
                            <template v-if="role.is_admin">Todas</template>
                            <span v-else-if="role.permissions_count === 0" class="text-danger font-semibold">Nenhuma ainda</span>
                            <template v-else>{{ role.permissions_count }}</template>
                        </td>
                        <td v-if="can('roles.manage')" class="text-right whitespace-nowrap">
                            <template v-if="role.can_manage">
                                <Link :href="route('roles.edit', role.id)" class="btn btn-ghost" :aria-label="`Permissões de ${role.name}`" title="Abrir para definir as permissões">
                                    <KeyRound :size="16" />
                                    Permissões
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

        <dialog
            ref="createDialog"
            class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-md rounded-xl p-6 shadow-xl backdrop:bg-black/40"
            aria-labelledby="role-create-title"
            @cancel.prevent="closeCreate"
        >
            <form class="space-y-4" @submit.prevent="store">
                <div>
                    <h2 id="role-create-title" class="text-lg font-bold">Novo perfil</h2>
                    <p class="text-muted text-sm">Depois de criar, abra o perfil na lista para escolher as permissões.</p>
                </div>
                <Field v-slot="{ id }" label="Nome" required :error="createForm.errors.name">
                    <input :id="id" ref="nameInput" v-model="createForm.name" class="input" maxlength="60" required placeholder="Ex.: Cozinheiro" />
                </Field>
                <Field v-slot="{ id }" label="Descrição" :error="createForm.errors.description">
                    <input :id="id" v-model="createForm.description" class="input" maxlength="255" placeholder="Ex.: Prepara os pedidos na cozinha." />
                </Field>
                <p v-if="createForm.errors.permissions" class="text-danger text-sm">{{ createForm.errors.permissions }}</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" @click="closeCreate">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Criar perfil</button>
                </div>
            </form>
        </dialog>

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
