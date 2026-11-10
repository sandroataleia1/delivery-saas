<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';

/**
 * Avisa que saiu uma versão nova do sistema enquanto a tela estava aberta.
 * Quem decide a hora de atualizar é a pessoa: a tela só recarrega quando ela clica,
 * ou na próxima vez que mudar de página.
 */
defineProps({
    hint: { type: String, default: null },
});

const CHECK_EVERY_MS = 60_000;

const page = usePage();
const available = ref(false);
let timer = null;

async function check() {
    if (available.value || document.visibilityState !== 'visible' || !page.version) {
        return;
    }
    try {
        const response = await fetch(route('app.version'), { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (response.ok) {
            const { version } = await response.json();
            available.value = Boolean(version) && version !== page.version;
        }
    } catch {
        // Sem rede agora: tenta de novo na próxima rodada.
    }
}

function update() {
    window.location.reload();
}

onMounted(() => {
    timer = setInterval(check, CHECK_EVERY_MS);
    document.addEventListener('visibilitychange', check);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', check);
});
</script>

<template>
    <div
        v-if="available"
        class="bg-sidebar fixed inset-x-3 bottom-3 z-50 mx-auto flex max-w-xl flex-wrap items-center gap-3 rounded-xl px-4 py-3 text-white shadow-xl print:hidden"
        role="status"
    >
        <RefreshCw :size="18" class="shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1 text-sm">
            <span class="font-semibold">Nova versão do sistema disponível.</span>
            <span v-if="hint" class="block text-white/70">{{ hint }}</span>
        </p>
        <button type="button" class="btn btn-primary shrink-0" @click="update">Atualizar agora</button>
    </div>
</template>
