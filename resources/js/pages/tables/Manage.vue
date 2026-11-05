<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Power } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    tables: { type: Array, required: true },
    nextNumber: { type: Number, required: true },
});

const form = useForm({ number: props.nextNumber, until: '', area: '', seats: 4 });

function create() {
    form.post(route('tables.store'), {
        preserveScroll: true,
        onSuccess: () => Object.assign(form, { number: props.nextNumber, until: '' }),
    });
}

const editing = ref(null);
const editForm = useForm({ number: '', area: '', seats: 4 });

function startEdit(table) {
    Object.assign(editForm, { number: table.number, area: table.area ?? '', seats: table.seats });
    editForm.clearErrors();
    editing.value = table;
}

function saveEdit() {
    editForm.put(route('tables.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

const statusForm = useForm({});

function toggle(table) {
    statusForm.patch(route('tables.status', table.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Gerenciar mesas">
        <PageHeader title="Gerenciar mesas" subtitle="Cadastro das mesas do salão.">
            <Link :href="route('tables.index')" class="btn btn-secondary">
                <ArrowLeft :size="16" />
                Voltar ao mapa
            </Link>
        </PageHeader>

        <form class="card mb-6 max-w-3xl p-5" @submit.prevent="create">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Field v-slot="{ id }" label="Número" required :error="form.errors.number">
                    <input :id="id" v-model.number="form.number" class="input" type="number" min="1" required />
                </Field>
                <Field v-slot="{ id }" label="Até o número" :error="form.errors.until" hint="Para criar várias.">
                    <input :id="id" v-model.number="form.until" class="input" type="number" min="1" />
                </Field>
                <Field v-slot="{ id }" label="Área" :error="form.errors.area">
                    <input :id="id" v-model="form.area" class="input" maxlength="40" placeholder="Salão" />
                </Field>
                <Field v-slot="{ id }" label="Lugares" required :error="form.errors.seats">
                    <input :id="id" v-model.number="form.seats" class="input" type="number" min="1" required />
                </Field>
            </div>
            <button type="submit" class="btn btn-primary mt-4" :disabled="form.processing">Adicionar</button>
        </form>

        <EmptyState v-if="tables.length === 0" title="Nenhuma mesa cadastrada" />

        <div v-else class="table-wrap max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mesa</th>
                        <th>Área</th>
                        <th class="max-sm:hidden">Lugares</th>
                        <th class="max-sm:hidden">Situação</th>
                        <th><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="table in tables" :key="table.id">
                        <td class="font-semibold tabular-nums">{{ table.number }}</td>
                        <td>{{ table.area ?? '—' }}</td>
                        <td class="tabular-nums max-sm:hidden">{{ table.seats }}</td>
                        <td class="max-sm:hidden"><StatusBadge :active="table.active" /></td>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="btn btn-ghost" :aria-label="`Editar mesa ${table.number}`" title="Editar" @click="startEdit(table)">
                                <Pencil :size="16" />
                            </button>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :aria-label="`${table.active ? 'Inativar' : 'Ativar'} mesa ${table.number}`"
                                :title="table.active ? 'Inativar' : 'Ativar'"
                                @click="toggle(table)"
                            >
                                <Power :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="editing !== null"
            :title="`Editar mesa ${editing?.number ?? ''}`"
            confirm-label="Salvar"
            :processing="editForm.processing"
            @confirm="saveEdit"
            @cancel="editing = null"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Número" required :error="editForm.errors.number">
                    <input :id="id" v-model.number="editForm.number" class="input" type="number" min="1" />
                </Field>
                <Field v-slot="{ id }" label="Área" :error="editForm.errors.area">
                    <input :id="id" v-model="editForm.area" class="input" maxlength="40" />
                </Field>
                <Field v-slot="{ id }" label="Lugares" required :error="editForm.errors.seats">
                    <input :id="id" v-model.number="editForm.seats" class="input" type="number" min="1" />
                </Field>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
