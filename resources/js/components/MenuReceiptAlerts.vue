<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
const props = defineProps({ ids: { type: Array, required: true }, operation: { type: Object, required: true }, lastUpdated: { type: Number, required: true } });
const enabled = ref(false);
const audioError = ref('');
const now = ref(Date.now());
const timer = setInterval(() => now.value = Date.now(), 1000);
const stale = computed(() => now.value - props.lastUpdated > 30000);
const seen = new Set();
let audio;
function beep() {
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    oscillator.connect(gain); gain.connect(audio.destination);
    gain.gain.value = 0.08; oscillator.frequency.value = 660;
    oscillator.start(); oscillator.stop(audio.currentTime + 0.18);
}
async function enable() {
    try { audio ??= new (window.AudioContext || window.webkitAudioContext)(); await audio.resume(); enabled.value = true; beep(); }
    catch { audioError.value = 'Som indisponível neste navegador. Acompanhe os alertas visuais.'; }
}
watch(() => props.ids, (ids) => {
    const fresh = ids.some(id => !seen.has(id));
    ids.forEach(id => seen.add(id));
    if (fresh && enabled.value && props.operation.sound_alerts && !stale.value) {
        try { beep(); } catch { audioError.value = 'Não foi possível tocar o alerta. Confira os pedidos na tela.'; }
    }
}, { immediate: true });
onUnmounted(() => { clearInterval(timer); audio?.close(); });
</script>
<template>
    <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
        <p v-if="stale" class="text-danger font-semibold" role="alert">Sem atualização há mais de 30 segundos. Confira a conexão antes de operar.</p>
        <p v-if="ids.length && operation.visual_alerts" class="text-warn font-semibold" role="status">{{ ids.length }} pedido(s) aguardando confirmação.</p>
        <button v-if="operation.sound_alerts && !enabled" type="button" class="btn btn-secondary" @click="enable">Ativar som de novos pedidos</button>
        <span v-if="enabled && operation.sound_alerts" class="text-muted">Som ativado nesta tela</span>
        <span v-if="audioError" class="text-danger" role="alert">{{ audioError }}</span>
    </div>
</template>
