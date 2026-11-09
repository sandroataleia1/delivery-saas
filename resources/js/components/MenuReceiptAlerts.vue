<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({ ids: { type: Array, required: true }, operation: { type: Object, required: true }, lastUpdated: { type: Number, required: true } });

/** Lembra neste navegador que o som foi ativado, para não pedir o botão de novo a cada recarga. */
const STORAGE_KEY = 'order-sound-enabled';
const remember = (value) => {
    try {
        value ? localStorage.setItem(STORAGE_KEY, '1') : localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Navegação privada pode bloquear o armazenamento: o som segue valendo só nesta tela.
    }
};
const remembered = () => {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
};

const wanted = ref(false);
const unlocked = ref(false);
const audioError = ref('');
const now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 1000);
const stale = computed(() => now.value - props.lastUpdated > 30000);
const seen = new Set();
let audio;

function beep() {
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.connect(gain);
    gain.connect(audio.destination);
    gain.gain.value = 0.08;
    oscillator.frequency.value = 660;
    oscillator.start();
    oscillator.stop(audio.currentTime + 0.18);
}

/** O navegador só libera o áudio depois de um gesto na página; tenta já e, se não der, no primeiro toque. */
async function unlock() {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        await audio.resume();
        unlocked.value = audio.state === 'running';
    } catch {
        audioError.value = 'Som indisponível neste navegador. Acompanhe os alertas visuais.';
    }

    if (unlocked.value) {
        stopWaitingForGesture();
    }

    return unlocked.value;
}

const gestureEvents = ['pointerdown', 'keydown', 'touchend'];
const onGesture = () => unlock();
const waitForGesture = () => gestureEvents.forEach((name) => document.addEventListener(name, onGesture, { capture: true }));
const stopWaitingForGesture = () => gestureEvents.forEach((name) => document.removeEventListener(name, onGesture, { capture: true }));

async function enable() {
    wanted.value = true;
    remember(true);

    if (await unlock()) {
        beep();
    }
}

function disable() {
    wanted.value = false;
    remember(false);
    stopWaitingForGesture();
}

onMounted(async () => {
    if (props.operation.sound_alerts && remembered()) {
        wanted.value = true;

        if (!(await unlock())) {
            waitForGesture();
        }
    }
});

watch(() => props.ids, (ids) => {
    const fresh = ids.some((id) => !seen.has(id));
    ids.forEach((id) => seen.add(id));

    if (fresh && wanted.value && unlocked.value && props.operation.sound_alerts && !stale.value) {
        try {
            beep();
        } catch {
            audioError.value = 'Não foi possível tocar o alerta. Confira os pedidos na tela.';
        }
    }
}, { immediate: true });

onUnmounted(() => {
    clearInterval(timer);
    stopWaitingForGesture();
    audio?.close();
});
</script>

<template>
    <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
        <p v-if="stale" class="text-danger font-semibold" role="alert">Sem atualização há mais de 30 segundos. Confira a conexão antes de operar.</p>
        <p v-if="ids.length && operation.visual_alerts" class="text-warn font-semibold" role="status">{{ ids.length }} pedido(s) aguardando confirmação.</p>
        <template v-if="operation.sound_alerts">
            <button v-if="!wanted" type="button" class="btn btn-secondary" @click="enable">Ativar som de novos pedidos</button>
            <template v-else>
                <span v-if="unlocked" class="text-muted">Som de novos pedidos ativado</span>
                <span v-else class="text-warn font-semibold" role="status">Toque em qualquer lugar da tela para liberar o som</span>
                <button type="button" class="text-muted cursor-pointer underline" @click="disable">Desativar som</button>
            </template>
        </template>
        <span v-if="audioError" class="text-danger" role="alert">{{ audioError }}</span>
    </div>
</template>
