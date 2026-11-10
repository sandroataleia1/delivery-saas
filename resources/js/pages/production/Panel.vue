<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    sector: { type: Object, default: null },
    sectors: { type: Array, required: true },
    orders: { type: Array, required: true },
    operation: { type: Object, required: true },
});

const { can } = usePermissions();
const STORAGE_KEY = 'production-panel-sector';
const canOperate = computed(() => can('production.operate'));

function remember(id) {
    try {
        id ? localStorage.setItem(STORAGE_KEY, String(id)) : localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* navegador sem armazenamento: o setor só não fica lembrado */
    }
}

function choose(id) {
    remember(id);
    router.get(route('production.panel'), id ? { setor: id } : {}, { replace: true });
}

onMounted(() => {
    if (props.sector) {
        remember(props.sector.id);
        return;
    }
    let saved = null;
    try {
        saved = localStorage.getItem(STORAGE_KEY);
    } catch {
        saved = null;
    }
    if (saved && props.sectors.some((sector) => String(sector.id) === saved)) {
        choose(saved);
    }
});

/* Atualização automática e aviso de conexão parada. */
const now = ref(Date.now());
const refreshedAt = ref(Date.now());
const stale = computed(() => props.sector && now.value - refreshedAt.value > 30000);
usePoll(5000, { only: ['orders', 'operation'], onSuccess: () => { refreshedAt.value = Date.now(); } });
let clock;
onMounted(() => { clock = setInterval(() => { now.value = Date.now(); }, 1000); });
onUnmounted(() => { clearInterval(clock); audio?.close(); });

const time = computed(() => new Date(now.value).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }));
const minutes = (createdAt) => Math.max(0, Math.floor((now.value - Date.parse(createdAt)) / 60000));
const late = (order) => minutes(order.created_at) >= (props.operation.late_after_minutes ?? 15);
const itemCount = computed(() => props.orders.reduce((total, order) => total + order.items.length, 0));
const quantity = (value) => String(Number(value)).replace('.', ',');

/* Avançar um item (toque) ou o pedido inteiro. */
const stepForm = useForm({ expected_version: 0, target: '' });
const readyForm = useForm({ works: [] });
const errors = computed(() => ({ ...stepForm.errors, ...readyForm.errors }));
const busy = computed(() => stepForm.processing || readyForm.processing);

function advance(item) {
    if (!canOperate.value || busy.value) {
        return;
    }
    stepForm.expected_version = item.version;
    stepForm.target = item.status === 'waiting' ? 'preparing' : 'ready';
    stepForm.post(route('production.transition', item.id), { preserveScroll: true, preserveState: true });
}

function finish(order) {
    if (!canOperate.value || busy.value) {
        return;
    }
    readyForm.works = order.items.map((item) => ({ id: item.id, version: item.version }));
    readyForm.post(route('production.panel.ready'), { preserveScroll: true, preserveState: true });
}

/* Tela cheia. */
const fullscreen = ref(false);
function syncFullscreen() { fullscreen.value = Boolean(document.fullscreenElement); }
async function toggleFullscreen() {
    try {
        document.fullscreenElement ? await document.exitFullscreen() : await document.documentElement.requestFullscreen();
    } catch {
        /* navegador não permite: segue em janela */
    }
}
onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onUnmounted(() => document.removeEventListener('fullscreenchange', syncFullscreen));

/* Som a cada item novo. */
const audioEnabled = ref(false);
let audio;
const seen = new Set();
function beep() {
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.connect(gain);
    gain.connect(audio.destination);
    gain.gain.value = 0.12;
    oscillator.frequency.value = 880;
    oscillator.start();
    oscillator.stop(audio.currentTime + 0.25);
}
const SOUND_KEY = 'production-panel-sound';
const soundWanted = ref(false);
async function enableAudio() {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        await audio.resume();
        audioEnabled.value = true;
        soundWanted.value = true;
        try { localStorage.setItem(SOUND_KEY, '1'); } catch { /* sem armazenamento */ }
        beep();
    } catch {
        audioEnabled.value = false;
    }
}

/*
 * O navegador só libera som depois de um toque na página, a cada carregamento.
 * Quem já ativou não precisa achar o botão: o som volta sozinho no primeiro
 * toque em qualquer lugar da tela (ou na hora, se o navegador já permitir).
 */
/* O Chrome só libera o som ao soltar o toque (click/touchend), não ao encostar. */
const GESTURES = ['click', 'touchend', 'keydown'];
function resumeOnGesture() {
    GESTURES.forEach((name) => document.removeEventListener(name, resumeOnGesture, true));
    audio?.resume().then(() => { audioEnabled.value = audio.state === 'running'; }).catch(() => {});
}
onMounted(() => {
    let wanted = false;
    try { wanted = localStorage.getItem(SOUND_KEY) === '1'; } catch { /* sem armazenamento */ }
    if (!wanted || !props.operation.sound_alerts) {
        return;
    }
    soundWanted.value = true;
    try {
        audio = new (window.AudioContext || window.webkitAudioContext)();
    } catch {
        return;
    }
    audio.onstatechange = () => { audioEnabled.value = audio.state === 'running'; };
    audioEnabled.value = audio.state === 'running';
    if (!audioEnabled.value) {
        GESTURES.forEach((name) => document.addEventListener(name, resumeOnGesture, true));
    }
});
onUnmounted(() => GESTURES.forEach((name) => document.removeEventListener(name, resumeOnGesture, true)));
watch(() => props.orders.flatMap((order) => order.items.map((item) => item.id)), (ids, previous) => {
    const fresh = ids.some((id) => !seen.has(id));
    ids.forEach((id) => seen.add(id));
    if (previous && fresh && audioEnabled.value && props.operation.sound_alerts) {
        try { beep(); } catch { /* sem som; o cartão novo aparece na tela */ }
    }
}, { immediate: true });
</script>

<template>
    <div class="flex min-h-screen flex-col bg-slate-950 text-slate-100">
        <Head :title="sector ? `Painel ${sector.name}` : 'Painel de produção'" />

        <header class="flex flex-wrap items-center gap-3 border-b border-slate-800 px-4 py-3">
            <h1 class="text-2xl font-black tracking-tight lg:text-3xl">{{ sector?.name ?? 'Painel de produção' }}</h1>
            <span v-if="sector" class="rounded-full bg-slate-800 px-3 py-1 text-lg font-bold tabular-nums">{{ itemCount }} {{ itemCount === 1 ? 'item' : 'itens' }}</span>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <select class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-base" aria-label="Setor" :value="sector?.id ?? ''" @change="choose($event.target.value)">
                    <option value="">Escolher setor…</option>
                    <option v-for="option in sectors" :key="option.id" :value="option.id">{{ option.name }}</option>
                </select>
                <button v-if="operation.sound_alerts && !audioEnabled && !soundWanted" type="button" class="rounded-lg bg-amber-500 px-3 py-2 font-bold text-slate-950" @click="enableAudio">Ativar som</button>
                <button type="button" class="rounded-lg bg-slate-800 px-3 py-2 font-semibold hover:bg-slate-700" @click="toggleFullscreen">{{ fullscreen ? 'Sair da tela cheia' : 'Tela cheia' }}</button>
                <Link v-if="!fullscreen" :href="route('production.index')" class="rounded-lg px-3 py-2 text-slate-400 hover:text-white">Voltar</Link>
                <span class="text-3xl font-black tabular-nums">{{ time }}</span>
            </div>
        </header>

        <p v-if="stale" class="bg-red-700 px-4 py-2 text-lg font-bold" role="alert">Sem atualização há mais de 30 segundos. Confira a conexão.</p>
        <p v-for="(error, key) in errors" :key="key" class="bg-red-700 px-4 py-2 text-lg font-bold" role="alert">{{ error }}</p>

        <main class="flex-1 p-4">
            <div v-if="!sector" class="mx-auto mt-16 max-w-xl text-center">
                <p class="text-3xl font-bold">Qual setor esta tela mostra?</p>
                <p class="mt-2 text-lg text-slate-400">A escolha fica salva neste aparelho.</p>
                <div class="mt-8 grid gap-3 sm:grid-cols-2">
                    <button v-for="option in sectors" :key="option.id" type="button" class="rounded-xl bg-slate-800 px-6 py-6 text-2xl font-bold hover:bg-slate-700" @click="choose(option.id)">{{ option.name }}</button>
                </div>
                <p v-if="!sectors.length" class="mt-6 text-slate-400">Nenhum setor ativo. Cadastre os setores em Setores.</p>
            </div>

            <div v-else-if="!orders.length" class="mt-24 text-center">
                <p class="text-4xl font-black text-emerald-400">Tudo em dia</p>
                <p class="mt-2 text-xl text-slate-400">Nenhum item aguardando no setor {{ sector.name }}.</p>
            </div>

            <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(18rem,1fr))] items-start gap-4">
                <article v-for="order in orders" :key="order.origin" class="flex flex-col overflow-hidden rounded-xl border-2 bg-slate-900" :class="late(order) ? 'border-red-500' : 'border-slate-700'">
                    <header class="flex items-center justify-between gap-2 px-4 py-3" :class="late(order) ? 'bg-red-600' : 'bg-slate-800'">
                        <h2 class="text-xl leading-tight font-black">{{ order.origin }}</h2>
                        <span class="text-2xl font-black tabular-nums">{{ minutes(order.created_at) }}′</span>
                    </header>
                    <ul class="flex-1 divide-y divide-slate-800">
                        <li v-for="item in order.items" :key="item.id">
                            <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left disabled:cursor-default" :class="item.status === 'preparing' ? 'bg-amber-500/15' : 'hover:bg-slate-800'" :disabled="!canOperate || busy" :title="item.status === 'waiting' ? 'Tocar para começar o preparo' : 'Tocar para marcar como pronto'" @click="advance(item)">
                                <span class="min-w-10 text-3xl font-black tabular-nums" :class="item.status === 'preparing' ? 'text-amber-400' : 'text-white'">{{ quantity(item.quantity) }}×</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-2xl leading-tight font-bold">{{ item.product_name }}</span>
                                    <span v-if="item.options_label" class="block text-lg text-slate-300">{{ item.options_label }}</span>
                                    <span v-if="item.notes" class="mt-1 block rounded bg-yellow-300 px-2 py-0.5 text-lg font-bold text-slate-950">{{ item.notes }}</span>
                                    <span v-if="item.status === 'preparing'" class="mt-1 block text-sm font-bold tracking-wide text-amber-400 uppercase">Preparando</span>
                                </span>
                            </button>
                        </li>
                    </ul>
                    <button v-if="canOperate" type="button" class="bg-emerald-600 px-4 py-4 text-xl font-black hover:bg-emerald-500 disabled:opacity-50" :disabled="busy" @click="finish(order)">Tudo pronto</button>
                </article>
            </div>
        </main>
    </div>
</template>
