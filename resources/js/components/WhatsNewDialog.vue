<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';

/**
 * Depois de uma atualização, mostra uma vez o que mudou no sistema.
 * Fica guardado no aparelho, por usuário, qual atualização já foi vista.
 */
const page = usePage();
const release = computed(() => page.props.whatsNew);
const storageKey = computed(() => `whats-new-seen:${page.props.auth?.user?.id ?? 'guest'}`);
const dialog = ref(null);

function seen(id) {
    try {
        return localStorage.getItem(storageKey.value) === id;
    } catch {
        return true;
    }
}

function close() {
    try {
        localStorage.setItem(storageKey.value, release.value.id);
    } catch {
        /* sem armazenamento: o aviso some agora e não insiste */
    }
    dialog.value?.close();
}

watch(
    release,
    async (current) => {
        if (!current || seen(current.id)) {
            return;
        }
        await nextTick();
        if (!dialog.value?.open) {
            dialog.value?.showModal();
        }
    },
    { immediate: true },
);
</script>

<template>
    <dialog
        v-if="release"
        ref="dialog"
        class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl p-0 shadow-xl backdrop:bg-black/40 print:hidden"
        aria-labelledby="whats-new-title"
        @cancel.prevent="close"
    >
        <div class="max-h-[80vh] overflow-y-auto p-6">
            <div class="flex items-center gap-3">
                <span class="bg-brand/10 text-brand flex size-10 shrink-0 items-center justify-center rounded-full">
                    <Sparkles :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2 id="whats-new-title" class="text-lg font-bold">O que mudou no sistema</h2>
                    <p class="text-muted text-sm">Atualização de {{ release.date }}</p>
                </div>
            </div>
            <ul class="mt-5 space-y-4">
                <li v-for="item in release.items" :key="item.title" class="border-brand border-l-4 pl-3">
                    <p class="font-semibold">{{ item.title }}</p>
                    <p class="text-muted text-sm">{{ item.text }}</p>
                </li>
            </ul>
        </div>
        <div class="border-line flex justify-end border-t px-6 py-4">
            <button type="button" class="btn btn-primary" @click="close">Entendi</button>
        </div>
    </dialog>
</template>
