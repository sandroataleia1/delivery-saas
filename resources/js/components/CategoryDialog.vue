<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    open: { type: Boolean, required: true },
    sectors: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);
const dialog = ref(null);
const form = useForm({ name: '', production_sector_id: null });

watch(
    () => props.open,
    (open) => {
        if (open) {
            // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
            form.name = '';
            form.production_sector_id = null;
            form.clearErrors();
            dialog.value?.showModal();
        } else {
            dialog.value?.close();
        }
    },
);

function submit() {
    form.post(route('categories.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <dialog
        ref="dialog"
        class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-md rounded-xl p-6 shadow-xl backdrop:bg-black/40"
        aria-labelledby="category-dialog-title"
        @cancel.prevent="emit('close')"
    >
        <form @submit.prevent="submit">
            <h2 id="category-dialog-title" class="text-lg font-bold">Nova categoria</h2>

            <label for="category-dialog-name" class="mt-4 mb-1.5 block text-sm font-semibold">Nome</label>
            <input id="category-dialog-name" v-model="form.name" class="input" maxlength="60" required autofocus />
            <p v-if="form.errors.name" class="text-danger mt-1.5 text-sm" role="alert">{{ form.errors.name }}</p>

            <template v-if="sectors.length">
                <label for="category-dialog-sector" class="mt-3 mb-1.5 block text-sm font-semibold">Setor</label>
                <select id="category-dialog-sector" v-model="form.production_sector_id" class="input">
                    <option :value="null">Padrão da empresa</option>
                    <option v-for="sector in sectors" :key="sector.id" :value="sector.id">{{ sector.name }}</option>
                </select>
                <p v-if="form.errors.production_sector_id" class="text-danger mt-1.5 text-sm" role="alert">{{ form.errors.production_sector_id }}</p>
            </template>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn btn-secondary" @click="emit('close')">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Cadastrar</button>
            </div>
        </form>
    </dialog>
</template>
