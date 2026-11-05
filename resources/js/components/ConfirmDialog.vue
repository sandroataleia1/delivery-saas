<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    message: { type: String, default: null },
    confirmLabel: { type: String, default: 'Confirmar' },
    danger: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    cancelLabel: { type: String, default: 'Cancelar' },
});

const emit = defineEmits(['confirm', 'cancel']);
const dialog = ref(null);

watch(
    () => props.open,
    (open) => (open ? dialog.value?.showModal() : dialog.value?.close()),
);
</script>

<template>
    <dialog
        ref="dialog"
        class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-md rounded-xl p-6 shadow-xl backdrop:bg-black/40"
        @cancel.prevent="emit('cancel')"
    >
        <h2 class="text-lg font-bold">{{ title }}</h2>
        <p v-if="message" class="text-muted mt-2 text-sm">{{ message }}</p>
        <slot />
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" @click="emit('cancel')">{{ cancelLabel }}</button>
            <button
                type="button"
                class="btn"
                :class="danger ? 'btn-danger' : 'btn-primary'"
                :disabled="processing || disabled"
                @click="emit('confirm')"
            >
                {{ confirmLabel }}
            </button>
        </div>
    </dialog>
</template>
