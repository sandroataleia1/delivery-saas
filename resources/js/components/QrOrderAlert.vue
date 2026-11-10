<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { BellRing, X } from '@lucide/vue';
import { usePermissions } from '@/lib/can';

/**
 * Aviso de pedido feito pelo QR da mesa, em qualquer tela de quem confirma pedidos.
 * Na própria comanda os pedidos dela já aparecem na tela, então não avisa de novo.
 */
const props = defineProps({
    exceptOrderId: { type: Number, default: null },
});

const CHECK_EVERY_MS = 10_000;
const STORAGE_KEY = 'qr-alert-dismissed';

const { can } = usePermissions();
const receipts = ref([]);
const dismissed = ref(readDismissed());
let timer = null;

function readDismissed() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
}

const visible = computed(() => receipts.value.filter((receipt) => receipt.order_id !== props.exceptOrderId && !dismissed.value.includes(receipt.id)));
const tables = computed(() => [...new Set(visible.value.map((receipt) => receipt.table))]);
const first = computed(() => visible.value[0] ?? null);

async function check() {
    if (document.visibilityState !== 'visible') {
        return;
    }
    try {
        const response = await fetch(route('receipts.pending'), { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (response.ok) {
            receipts.value = (await response.json()).receipts ?? [];
            const pendingIds = receipts.value.map((receipt) => receipt.id);
            dismissed.value = dismissed.value.filter((id) => pendingIds.includes(id));
        }
    } catch {
        // Sem rede agora: tenta de novo na próxima rodada.
    }
}

function dismiss() {
    dismissed.value = [...dismissed.value, ...visible.value.map((receipt) => receipt.id)];
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(dismissed.value));
    } catch {
        // Sem armazenamento: o aviso só volta ao trocar de página.
    }
}

onMounted(() => {
    if (!can('tables.confirm')) {
        return;
    }
    check();
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
        v-if="first"
        class="bg-brand fixed inset-x-3 top-3 z-[60] mx-auto flex max-w-md items-center gap-3 rounded-xl px-4 py-3 text-white shadow-xl sm:right-4 sm:left-auto print:hidden"
        role="alert"
    >
        <BellRing :size="20" class="shrink-0 animate-pulse" aria-hidden="true" />
        <p class="min-w-0 flex-1 text-sm">
            <span class="block font-bold">{{ tables.length > 1 ? `Pedidos pelo QR nas mesas ${tables.join(', ')}` : `Pedido pelo QR na mesa ${first.table}` }}</span>
            <span class="text-white/80">Confira e confirme o pedido.</span>
        </p>
        <Link :href="route('orders.show', first.order_id)" class="btn shrink-0 bg-white text-brand hover:bg-white/90">Ver</Link>
        <button type="button" class="shrink-0 rounded p-1 text-white/80 hover:bg-white/15 hover:text-white" aria-label="Fechar aviso" @click="dismiss">
            <X :size="18" />
        </button>
    </div>
</template>
