<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from '@lucide/vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    open: { type: Boolean, required: true },
    categories: { type: Array, required: true },
    sectors: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);
const { can } = usePermissions();
const dialog = ref(null);

const form = useForm({ name: '', production_sector_id: null });
const editing = ref(null);
const deleting = ref(null);
const deleteForm = useForm({});

watch(
    () => props.open,
    (open) => {
        if (open) {
            dialog.value?.showModal();
        } else {
            stopEdit();
            deleting.value = null;
            dialog.value?.close();
        }
    },
);

function startEdit(category) {
    editing.value = category;
    deleting.value = null;
    form.name = category.name;
    form.production_sector_id = category.production_sector_id;
    form.clearErrors();
}

function stopEdit() {
    editing.value = null;
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    form.name = '';
    form.production_sector_id = null;
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, preserveState: true, onSuccess: stopEdit };

    if (editing.value) {
        form.put(route('categories.update', editing.value.id), options);
    } else {
        form.post(route('categories.store'), options);
    }
}

function destroy(category) {
    deleteForm.delete(route('categories.destroy', category.id), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (deleting.value = null),
    });
}
</script>

<template>
    <dialog
        ref="dialog"
        class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl p-0 shadow-xl backdrop:bg-black/40"
        aria-labelledby="category-dialog-title"
        @cancel.prevent="emit('close')"
    >
        <div class="border-line flex items-center justify-between border-b px-5 py-4">
            <h2 id="category-dialog-title" class="text-lg font-bold">Categorias</h2>
            <button type="button" class="btn btn-ghost" aria-label="Fechar" @click="emit('close')">
                <X :size="18" />
            </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto p-5">
            <form v-if="can('products.manage')" class="mb-4" @submit.prevent="submit">
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

                <label v-if="sectors.length" class="mt-3 block text-sm">
                    Setor de produção
                    <select v-model="form.production_sector_id" class="input">
                        <option :value="null">Padrão da empresa</option>
                        <option v-for="sector in sectors" :key="sector.id" :value="sector.id">{{ sector.name }}</option>
                    </select>
                </label>
                <p v-if="form.errors.production_sector_id" class="text-danger mt-1.5 text-sm" role="alert">{{ form.errors.production_sector_id }}</p>
            </form>

            <p v-if="categories.length === 0" class="text-muted py-6 text-center text-sm">Nenhuma categoria cadastrada.</p>

            <ul v-else class="divide-line border-line divide-y rounded-lg border">
                <li v-for="category in categories" :key="category.id" class="flex items-center gap-2 px-3 py-2">
                    <template v-if="deleting?.id === category.id">
                        <span class="flex-1 text-sm">Excluir <strong>{{ category.name }}</strong>?</span>
                        <button type="button" class="btn btn-secondary" @click="deleting = null">Não</button>
                        <button type="button" class="btn btn-danger" :disabled="deleteForm.processing" @click="destroy(category)">Excluir</button>
                    </template>
                    <template v-else>
                        <span class="flex-1 font-semibold">{{ category.name }}</span>
                        <span class="text-muted text-xs tabular-nums">{{ category.products_count }} produto(s)</span>
                        <template v-if="can('products.manage')">
                            <button type="button" class="btn btn-ghost" :aria-label="`Editar ${category.name}`" title="Editar" @click="startEdit(category)">
                                <Pencil :size="16" />
                            </button>
                            <button type="button" class="btn btn-ghost" :aria-label="`Excluir ${category.name}`" title="Excluir" @click="deleting = category">
                                <Trash2 :size="16" />
                            </button>
                        </template>
                    </template>
                </li>
            </ul>
        </div>
    </dialog>
</template>
