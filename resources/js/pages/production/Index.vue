<script setup>
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { Link, router, useForm, usePoll } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
import { usePermissions } from '@/lib/can';
const props = defineProps({ works: Array, notices: Array, sectors: Array, filters: Object, operation: Object });
const { can } = usePermissions();
const form = useForm({ expected_version: 0, target: '' });
const reconciliation = useForm({ observed: 'waiting', reason: '' });
const printForm = useForm({});
const printWork = (work) => printForm.post(route('production.print', work.id), { preserveScroll: true });
const selected = ref(null);
const now = ref(Date.now());
const refreshedAt = ref(Date.now());
const stale = computed(() => now.value - refreshedAt.value > 30000);
usePoll(10000, { only: ['works', 'notices', 'operation'], onSuccess: () => { refreshedAt.value = Date.now(); } });
let timer;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
const columns = [{ state: 'waiting', label: 'Aguardando' }, { state: 'preparing', label: 'Preparando' }, { state: 'ready', label: 'Pronto' }];
const legacy = computed(() => props.works.filter(w => w.legacy && !w.reconciled_at));
const lines = state => props.works.filter(w => w.status === state && (!w.legacy || w.reconciled_at));
const minutes = work => Math.max(0, Math.floor((now.value - Date.parse(work.created_at)) / 60000));
function transition(work, target) {
    form.expected_version = work.version; form.target = target;
    form.post(route('production.transition', work.id), { preserveScroll: true });
}
function filter(event) { router.get(route('production.index'), event.target.value ? { sector: event.target.value } : {}, { preserveState: true }); }
function reconcile() { reconciliation.post(route('production.reconcile', selected.value.id), { preserveScroll: true, onSuccess: () => { selected.value = null; reconciliation.reset(); } }); }
const audioEnabled = ref(false);
const audioError = ref('');
let audio;
const seen = new Set();
function beep() { const oscillator = audio.createOscillator(); const gain = audio.createGain(); oscillator.connect(gain); gain.connect(audio.destination); gain.gain.value = 0.08; oscillator.frequency.value = 660; oscillator.start(); oscillator.stop(audio.currentTime + 0.18); }
async function enableAudio() { try { audio ??= new (window.AudioContext || window.webkitAudioContext)(); await audio.resume(); audioEnabled.value = true; beep(); } catch { audioError.value = 'Som indisponível. Acompanhe os avisos visuais.'; } }
watch(() => [props.works.map(w => `work:${w.id}`), props.notices.map(e => `notice:${e.id}`)].flat(), ids => {
    const fresh = ids.some(id => !seen.has(id)); ids.forEach(id => seen.add(id));
    if (fresh && audioEnabled.value && props.operation.sound_alerts && !stale.value) { try { beep(); } catch { audioError.value = 'Confira os avisos na tela; o som falhou.'; } }
}, { immediate: true });
onUnmounted(() => { clearInterval(timer); audio?.close(); });
</script>
<template>
    <AppLayout title="Produção">
        <PageHeader title="Produção" subtitle="Cada item segue seu setor. Preparo e entrega são registros distintos." />
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <label class="text-sm font-semibold" for="production-sector">Setor</label>
            <select id="production-sector" class="input max-w-xs" :value="filters.sector || ''" @change="filter"><option value="">Todos os setores</option><option v-for="sector in sectors" :key="sector.id" :value="sector.id">{{ sector.name }}{{ sector.active ? '' : ' (inativo)' }}</option></select>
            <Link class="btn btn-primary" :href="route('production.panel', filters.sector ? { setor: filters.sector } : {})">Painel tela cheia</Link>
            <Link v-if="can('production.configure')" class="btn btn-secondary" :href="route('production.sectors')">Configurar setores</Link>
            <button v-if="operation.sound_alerts && !audioEnabled" class="btn btn-secondary" @click="enableAudio">Ativar som</button>
            <span v-else-if="audioEnabled && operation.sound_alerts" class="text-muted text-sm">Som ativado nesta tela</span>
        </div>
        <p v-if="stale" class="mb-3 rounded-lg bg-red-50 p-3 font-semibold text-red-800" role="alert">Sem atualização há mais de 30 segundos. Confira a conexão antes de operar.</p>
        <p v-if="audioError" class="text-danger mb-3" role="alert">{{ audioError }}</p>
        <p v-for="(error, key) in form.errors" :key="key" class="text-danger mb-3" role="alert">{{ error }}</p>
        <p v-if="printForm.errors.print" class="text-danger mb-3" role="alert">{{ printForm.errors.print }}</p>
        <section v-if="notices.length" class="mb-5 space-y-3" aria-label="Cancelamentos aguardando ciência">
            <article v-for="notice in notices" :key="notice.id" class="rounded-xl border-2 border-red-400 bg-red-50 p-4 text-red-950">
                <h2 class="font-bold">Cancelado — {{ notice.item.quantity }} {{ notice.item.unit }} {{ notice.item.product_name }}<span v-if="notice.item.options_label" class="text-muted block text-sm font-normal">{{ notice.item.options_label }}</span></h2>
                <p>{{ notice.item.origin_label }} · {{ notice.item.sector_name }}</p><p class="mt-1">{{ notice.reason }}</p>
                <button v-if="can('production.operate')" class="btn btn-secondary mt-2" @click="router.post(route('production.acknowledge', notice.id), {}, { preserveScroll: true })">Registrar ciência</button>
            </article>
        </section>
        <section v-if="legacy.length" class="card mb-5 border-amber-300 p-4">
            <h2 class="font-bold">Legado aguardando conferência</h2><p class="text-muted text-sm">Impressão não comprova preparo ou entrega. Fechamento e acerto ficam bloqueados.</p>
            <div v-for="work in legacy" :key="work.id" class="mt-3 flex flex-wrap items-center gap-3"><span>{{ work.origin_label }} · {{ work.quantity }} {{ work.unit }} {{ work.product_name }}<template v-if="work.options_label"> ({{ work.options_label }})</template></span><button v-if="can('production.manage')" class="btn btn-secondary" @click="selected = work">Classificar estágio observado</button></div>
        </section>
        <form v-if="selected" class="card mb-5 space-y-3 p-4" @submit.prevent="reconcile">
            <h2 class="font-bold">Conferência de {{ selected.product_name }}</h2>
            <label class="block">Estágio observado<select v-model="reconciliation.observed" class="input"><option value="waiting">Aguardando</option><option value="preparing">Preparando</option><option value="ready">Pronto</option><option value="delivered">Entregue</option></select></label>
            <label class="block">Motivo e conferência realizada<textarea v-model="reconciliation.reason" class="input" required minlength="3" maxlength="255" /></label>
            <p v-for="error in reconciliation.errors" :key="error" class="text-danger" role="alert">{{ error }}</p>
            <div class="flex gap-2"><button class="btn btn-primary" :disabled="reconciliation.processing">Registrar observação atual</button><button type="button" class="btn btn-secondary" @click="selected = null">Voltar</button></div>
        </form>
        <div class="grid gap-4 lg:grid-cols-3">
            <section v-for="column in columns" :key="column.state" class="min-w-0 rounded-xl bg-slate-100 p-3" :aria-label="column.label">
                <h2 class="mb-3 flex items-center justify-between text-lg font-bold">{{ column.label }} <span class="text-muted">{{ lines(column.state).length }}</span></h2>
                <p v-if="!lines(column.state).length" class="text-muted p-3 text-sm">Nenhum item nesta etapa.</p>
                <article v-for="work in lines(column.state)" :key="work.id" class="card mb-3 space-y-3 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm"><span class="font-bold">{{ work.origin_label }}</span><span class="flex items-center gap-2"><span :class="minutes(work) >= operation.late_after_minutes ? 'text-danger font-bold' : 'text-muted'">{{ minutes(work) }} min</span><button type="button" class="btn btn-ghost min-h-8 px-2 py-1 text-xs" :disabled="printForm.processing" :title="`Imprimir a comanda de ${work.origin_label}`" @click="printWork(work)">Imprimir</button></span></div>
                    <p class="text-xl font-bold">{{ work.quantity }} {{ work.unit }} {{ work.product_name }}</p><p v-if="work.options_label" class="text-muted text-sm">{{ work.options_label }}</p><p class="text-muted text-sm">{{ work.sector_name }}</p>
                    <p v-if="work.notes" class="rounded-lg bg-amber-50 p-2 font-semibold text-amber-950">{{ work.notes }}</p>
                    <button v-if="column.state !== 'ready' && can('production.operate')" class="btn btn-primary w-full" :disabled="form.processing || stale" @click="transition(work, column.state === 'waiting' ? 'preparing' : 'ready')">{{ column.state === 'waiting' ? 'Iniciar preparo' : 'Marcar pronto' }}</button>
                    <button v-if="column.state === 'ready' && work.table_order_item_id && can('production.deliver')" class="btn btn-primary w-full" :disabled="form.processing || stale" @click="transition(work, 'delivered')">Registrar entrega à mesa</button>
                    <p v-if="column.state === 'ready' && !work.table_order_item_id" class="text-muted text-sm">Saída e conclusão pelo quadro de entregas.</p>
                </article>
            </section>
        </div>
    </AppLayout>
</template>
