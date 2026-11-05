<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Bike, ChefHat, House, PackageCheck, ReceiptText, Store } from '@lucide/vue';

const CYCLE_MS = 12000;
const RIDE_START = 0.5;
const RIDE_END = 0.8;
const FIRST_ORDER = 1042;

const stages = [
    { label: 'Pedido recebido', detail: 'Direto no seu painel', icon: ReceiptText, startsAt: 0 },
    { label: 'Na cozinha', detail: 'Preparando o pedido', icon: ChefHat, startsAt: 0.25 },
    { label: 'Saiu para entrega', detail: 'Entregador a caminho', icon: Bike, startsAt: RIDE_START },
    { label: 'Entregue', detail: 'Caixa e estoque em dia', icon: PackageCheck, startsAt: RIDE_END },
];

const stageIndex = ref(0);
const orderNumber = ref(FIRST_ORDER);
const route = ref(null);
const courier = ref(null);
const trail = ref(null);

const stage = computed(() => stages[stageIndex.value]);

let frame = null;

const easeInOut = (t) => (t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2);

function placeCourier(progress) {
    const length = route.value.getTotalLength();
    const point = route.value.getPointAtLength(length * progress);

    courier.value.setAttribute('transform', `translate(${point.x} ${point.y})`);
    trail.value.setAttribute('stroke-dasharray', `${length * progress} ${length}`);
}

// Um único relógio move o entregador, e o cartão, para nada sair de sincronia.
function tick(now) {
    const cycle = (now % CYCLE_MS) / CYCLE_MS;
    const ride = Math.min(Math.max((cycle - RIDE_START) / (RIDE_END - RIDE_START), 0), 1);

    placeCourier(easeInOut(ride));

    stageIndex.value = stages.findLastIndex((item) => cycle >= item.startsAt);
    orderNumber.value = FIRST_ORDER + Math.floor(now / CYCLE_MS);

    frame = requestAnimationFrame(tick);
}

onMounted(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        stageIndex.value = 2;
        placeCourier(0.55);

        return;
    }

    frame = requestAnimationFrame(tick);
});

onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <aside class="bg-sidebar sticky top-0 hidden h-dvh w-[55%] flex-col overflow-hidden p-10 lg:flex xl:p-14" aria-hidden="true">
        <div class="glow bg-brand/30 absolute -top-32 -left-32 size-[28rem] rounded-full blur-3xl" />
        <div class="glow glow-late bg-brand/20 absolute -right-24 -bottom-40 size-[26rem] rounded-full blur-3xl" />

        <div class="relative flex items-center gap-2.5 text-white">
            <span class="bg-brand flex size-10 items-center justify-center rounded-lg">
                <Bike :size="22" />
            </span>
            <span class="text-lg font-bold tracking-tight">Delivery SaaS</span>
        </div>

        <div class="border-line/15 relative z-10 mt-6 w-72 self-end rounded-xl border bg-white/8 p-4 text-white backdrop-blur-md">
            <div class="flex items-center justify-between">
                <span class="text-sidebar-text text-xs font-semibold tabular-nums">Pedido #{{ orderNumber }}</span>
                <span class="flex gap-1">
                    <span
                        v-for="(item, index) in stages"
                        :key="item.label"
                        class="h-1 w-6 rounded-full transition-colors duration-500"
                        :class="index <= stageIndex ? 'bg-brand' : 'bg-white/15'"
                    />
                </span>
            </div>
            <Transition name="swap" mode="out-in">
                <div :key="stage.label" class="mt-3 flex items-center gap-3">
                    <span class="bg-brand/20 flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <component :is="stage.icon" :size="20" />
                    </span>
                    <span>
                        <span class="block text-sm font-bold">{{ stage.label }}</span>
                        <span class="text-sidebar-text block text-xs">{{ stage.detail }}</span>
                    </span>
                </div>
            </Transition>
        </div>

        <div class="relative -mt-4 min-h-0 flex-1">
            <svg viewBox="0 0 640 400" preserveAspectRatio="xMidYMin meet" class="absolute inset-0 size-full" fill="none">
                <g stroke="white" stroke-opacity="0.07" stroke-width="14" stroke-linecap="round">
                    <path d="M0 330H640M0 210H640M0 110H640M90 0V400M230 0V400M400 0V400M550 0V400" />
                </g>

                <path
                    ref="route"
                    d="M90 330H218Q230 330 230 318V222Q230 210 242 210H388Q400 210 400 198V122Q400 110 412 110H550"
                    stroke="white"
                    stroke-opacity="0.22"
                    stroke-width="4"
                    stroke-dasharray="2 12"
                    stroke-linecap="round"
                />
                <path
                    ref="trail"
                    d="M90 330H218Q230 330 230 318V222Q230 210 242 210H388Q400 210 400 198V122Q400 110 412 110H550"
                    stroke="var(--color-brand)"
                    stroke-width="5"
                    stroke-linecap="round"
                    stroke-dasharray="0 1000"
                />

                <circle cx="550" cy="110" r="24" fill="var(--color-brand)" class="ping" />
                <circle cx="90" cy="330" r="22" fill="var(--color-sidebar-hover)" stroke="white" stroke-opacity="0.2" />
                <Store x="79" y="319" :size="22" color="white" />
                <circle cx="550" cy="110" r="22" fill="var(--color-sidebar-hover)" stroke="white" stroke-opacity="0.2" />
                <House x="539" y="99" :size="22" color="white" />

                <g ref="courier" transform="translate(90 330)">
                    <circle r="24" fill="var(--color-brand)" fill-opacity="0.25" />
                    <circle r="17" fill="var(--color-brand)" />
                    <Bike x="-10" y="-10" :size="20" color="white" />
                </g>
            </svg>

        </div>
    </aside>
</template>

<style scoped>
.glow {
    animation: drift 14s ease-in-out infinite alternate;
}

.glow-late {
    animation-delay: -7s;
}

.ping {
    transform-box: fill-box;
    transform-origin: center;
    animation: ping 2.4s ease-out infinite;
}

.swap-enter-active,
.swap-leave-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s ease;
}

.swap-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.swap-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

@keyframes drift {
    to {
        transform: translate(48px, 32px) scale(1.15);
    }
}

@keyframes ping {
    from {
        opacity: 0.5;
        transform: scale(1);
    }

    to {
        opacity: 0;
        transform: scale(2.2);
    }
}

@media (prefers-reduced-motion: reduce) {
    .glow,
    .ping {
        animation: none;
    }

    .ping {
        opacity: 0;
    }
}
</style>
