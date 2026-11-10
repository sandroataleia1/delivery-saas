import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const CHECK_EVERY_MS = 15_000;

/**
 * Quantos pedidos online e pedidos de mesa (QR) esperam a equipe, para o número no menu.
 * Começa com o que veio na página e se atualiza sozinho, sem recarregar a tela.
 */
export function useNavCounts() {
    const page = usePage();
    const online = ref(page.props.onlinePendingCount ?? null);
    const tables = ref(page.props.tablePendingCount ?? null);
    let timer = null;

    watch(() => page.props.onlinePendingCount, (value) => (online.value = value ?? null));
    watch(() => page.props.tablePendingCount, (value) => (tables.value = value ?? null));

    async function check() {
        if (document.visibilityState !== 'visible') {
            return;
        }
        try {
            const response = await fetch(route('nav.counts'), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.ok) {
                const counts = await response.json();
                online.value = counts.online;
                tables.value = counts.tables;
            }
        } catch {
            // Sem rede agora: tenta de novo na próxima rodada.
        }
    }

    onMounted(() => {
        if (online.value === null && tables.value === null) {
            return;
        }
        timer = setInterval(check, CHECK_EVERY_MS);
        document.addEventListener('visibilitychange', check);
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', check);
    });

    return { online, tables };
}
