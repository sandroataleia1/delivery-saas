<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from '@lucide/vue';

// Em telas com ações no rodapé, o aviso fica no topo para não cobrir os botões.
defineProps({
    top: { type: Boolean, default: false },
});

const page = usePage();
const message = ref(null);
let timer = null;

watch(
    () => page.props.flash,
    (flash) => {
        const text = flash?.error ?? flash?.success;

        if (!text) {
            return;
        }

        message.value = { text, error: Boolean(flash.error) };

        clearTimeout(timer);
        timer = setTimeout(() => (message.value = null), flash.error ? 8000 : 4000);
    },
    { immediate: true },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-4 z-50 flex justify-end" :class="top ? 'top-16' : 'bottom-4'" aria-live="polite">
        <div
            v-if="message"
            class="pointer-events-auto flex max-w-md items-start gap-2.5 rounded-lg border px-4 py-3 text-sm font-medium shadow-lg"
            :class="message.error ? 'border-danger/30 bg-danger-soft text-danger' : 'border-ok/30 bg-ok-soft text-ok'"
        >
            <CircleAlert v-if="message.error" :size="18" class="mt-px shrink-0" />
            <CircleCheck v-else :size="18" class="mt-px shrink-0" />
            <span>{{ message.text }}</span>
            <button type="button" class="ml-1 cursor-pointer" aria-label="Fechar aviso" @click="message = null">
                <X :size="16" />
            </button>
        </div>
    </div>
</template>
