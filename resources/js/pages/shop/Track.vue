<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Check, MessageCircle, XCircle } from '@lucide/vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    store: { type: Object, required: true },
    slug: { type: String, required: true },
    token: { type: String, required: true },
    customer_first_name: { type: String, default: '' },
    progress: { type: Object, required: true },
    whatsapp_updates: { type: Boolean, default: false },
});

const POLL_MS = 10000;
const TERMINAL = ['delivered', 'rejected', 'expired', 'cancelled'];

const current = ref(props.progress);
let timer = null;
let controller = null;

const isTerminal = computed(() => TERMINAL.includes(current.value.state));
const failed = computed(() => ['rejected', 'expired', 'cancelled'].includes(current.value.state));
const whatsappUrl = computed(() => (props.store.whatsapp ? `https://wa.me/55${props.store.whatsapp}` : null));
const heading = computed(() => ({
    pending: 'Aguardando a loja confirmar',
    accepted: 'Pedido aceito',
    preparing: 'Seu pedido está sendo preparado',
    ready: current.value.type === 'pickup' ? 'Pronto para retirar' : 'Saiu para entrega',
    delivered: current.value.type === 'pickup' ? 'Pedido retirado' : 'Pedido entregue',
    rejected: 'Pedido recusado',
    expired: 'Pedido não confirmado',
    cancelled: 'Pedido cancelado',
}[current.value.state] ?? 'Acompanhe seu pedido'));

function formatTime(iso) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
}

function stop() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
    controller?.abort();
}

async function refresh() {
    controller?.abort();
    controller = new AbortController();
    try {
        const response = await fetch(`/loja/${encodeURIComponent(props.slug)}/pedido/${encodeURIComponent(props.token)}/status`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            signal: controller.signal,
        });
        if (!response.ok) {
            return;
        }
        current.value = (await response.json()).progress;
        if (isTerminal.value) {
            stop();
        }
    } catch {
        // Sem rede ou requisição cancelada: tenta de novo no próximo ciclo.
    }
}

onMounted(() => {
    if (!isTerminal.value) {
        timer = setInterval(refresh, POLL_MS);
    }
});

onUnmounted(stop);
</script>

<template>
    <div class="bg-canvas min-h-dvh overflow-x-hidden pb-10">
        <Head :title="`Pedido #${current.number}`" />

        <header class="bg-surface border-line border-b px-4 py-4">
            <div class="mx-auto max-w-2xl">
                <p class="text-muted text-sm">{{ store.name }}</p>
                <h1 class="text-lg font-bold">Pedido #{{ current.number }}</h1>
                <p v-if="customer_first_name" class="text-muted text-sm">{{ customer_first_name }}, acompanhe seu pedido por aqui.</p>
            </div>
        </header>

        <main class="mx-auto max-w-2xl space-y-3 px-4 py-3">
            <section class="card p-4" aria-live="polite">
                <p class="text-base font-bold" :class="failed ? 'text-danger' : ''" role="status">{{ heading }}</p>
                <p v-if="current.reason" class="text-muted mt-1 flex items-start gap-1 text-sm"><XCircle :size="16" class="mt-0.5 shrink-0" /> {{ current.reason }}</p>

                <ol class="mt-4 space-y-3">
                    <li v-for="step in current.steps" :key="step.key" class="flex items-center gap-3" :class="step.done ? '' : 'opacity-50'">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full border" :class="step.done ? 'bg-ok text-white border-transparent' : 'border-line'">
                            <Check v-if="step.done" :size="16" />
                        </span>
                        <span class="min-w-0 flex-1 text-sm" :class="step.current ? 'font-bold' : ''">{{ step.label }}</span>
                        <time v-if="step.at" :datetime="step.at" class="text-muted text-xs">{{ formatTime(step.at) }}</time>
                    </li>
                </ol>

                <p v-if="whatsapp_updates" class="text-muted mt-4 text-sm">Você também recebe cada etapa no WhatsApp.</p>
            </section>

            <section class="card p-4">
                <h2 class="mb-2 text-sm font-bold">Itens</h2>
                <ul class="divide-line divide-y">
                    <li v-for="(item, index) in current.items" :key="index" class="flex items-start justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0">
                            {{ item.quantity }}x {{ item.product_name }}
                            <span v-if="item.options_label" class="text-muted block text-xs">{{ item.options_label }}</span>
                        </span>
                        <span class="shrink-0">{{ formatMoney(item.total) }}</span>
                    </li>
                </ul>
                <p class="border-line mt-2 flex justify-between border-t pt-2 text-sm font-bold"><span>Total</span><span>{{ formatMoney(current.total) }}</span></p>
            </section>

            <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="btn btn-secondary w-full justify-center">
                <MessageCircle :size="16" />
                Falar com a loja no WhatsApp
            </a>
        </main>
    </div>
</template>
