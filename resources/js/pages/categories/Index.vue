<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

defineProps({
    categories: { type: Array, required: true },
});

const { can } = usePermissions();

const form = useForm({ name: '' });
const editing = ref(null);
const target = ref(null);
const deleteForm = useForm({});

function startEdit(category) {
    editing.value = category;
    form.name = category.name;
    form.clearErrors();
}

function stopEdit() {
    editing.value = null;
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    form.name = '';
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: stopEdit };

    if (editing.value) {
        form.put(route('categories.update', editing.value.id), options);
    } else {
        form.post(route('categories.store'), options);
    }
}

function destroy() {
    deleteForm.delete(route('categories.destroy', target.value.id), {
        preserveScroll: true,
        onFinish: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Categorias">
        <PageHeader title="Categorias" subtitle="Organize seus produtos." />

        <form v-if="can('products.manage')" class="card mb-4 max-w-xl p-4" @submit.prevent="submit">
            <label for="category-name" class="mb-1.5 block text-sm font-semibold">
                {{ editing ? `Editar ${editing.name}` : 'Nova categoria' }}
            </label>
            <div class="flex gap-2">
                <input id="category-name" v-model="form.name" class="input" maxlength="60" required />
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <Plus v-if="!editing" :size="16" />
                    {{ editing ? 'Salvar' : 'Adicionar' }}
                </button>
                <button v-if="editing" type="button" class="btn btn-secondary" @click="stopEdit">Cancelar</button>
            </div>
            <p v-if="form.errors.name" class="text-danger mt-1.5 text-sm" role="alert">{{ form.errors.name }}</p>
        </form>

        <EmptyState v-if="categories.length === 0" title="Nenhuma categoria cadastrada" />

        <div v-else class="table-wrap max-w-xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th class="text-right">Produtos</th>
                        <th v-if="can('products.manage')"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.id">
                        <td class="font-semibold">{{ category.name }}</td>
                        <td class="text-right tabular-nums">{{ category.products_count }}</td>
                        <td v-if="can('products.manage')" class="text-right whitespace-nowrap">
                            <button type="button" class="btn btn-ghost" :aria-label="`Editar ${category.name}`" title="Editar" @click="startEdit(category)">
                                <Pencil :size="16" />
                            </button>
                            <button type="button" class="btn btn-ghost" :aria-label="`Excluir ${category.name}`" title="Excluir" @click="target = category">
                                <Trash2 :size="16" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="target !== null"
            :title="`Excluir a categoria ${target?.name}?`"
            confirm-label="Excluir"
            danger
            :processing="deleteForm.processing"
            @confirm="destroy"
            @cancel="target = null"
        />
    </AppLayout>
</template>
