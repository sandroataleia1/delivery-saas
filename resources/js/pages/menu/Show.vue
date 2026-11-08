<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, useHttp } from '@inertiajs/vue3';
import { Minus, Plus, Receipt, ShoppingBag, X } from '@lucide/vue';
import ItemComposer from '@/components/ItemComposer.vue';
import { payloadFields, selectionKey } from '@/lib/composition';
import { formatMoney, formatQuantity } from '@/lib/format';
import { fromCents, lineCents, newUuid } from '@/lib/money';

const props = defineProps({
    token: { type: String, required: true }, company: { type: String, required: true },
    table: { type: Number, required: true }, serviceContext: { type: String, required: true },
    categories: { type: Array, required: true }, products: { type: Array, required: true },
    limits: { type: Object, required: true },
    composition: { type: Object, default: () => ({}) },
});
const category = ref(null);
const cart = ref([]);
const sheet = ref(null);
const journal = ref([]);
const error = ref('');
const storageReady = ref(true);
const clock = ref(Date.now());
const lastUpdated = ref(Date.now());
const form = useHttp({});
const tracking = useHttp({});
const storageKey = `menu-receipts:${props.token}`;
let timer;
let pollTimer;
let generation = 0;
let draftReceiptUuid = null;
let disposed = false;
const uncertain = computed(() => journal.value.find(record => record.uncertain));
const frozen = computed(() => Boolean(uncertain.value) || form.processing);
const categories = computed(() => props.categories.filter(item => props.products.some(product => product.category_id === item.id)));
const visible = computed(() => props.products.filter(product => category.value === null || product.category_id === category.value));
const count = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));
const totalCents = computed(() => cart.value.reduce((sum, item) => sum + lineCents(item.quantity, item.sale_price), 0));
const quantityOf = product => cart.value.filter(item => item.product_id === product.id).reduce((sum, item) => sum + item.quantity, 0);
const isComposed = product => Boolean(props.composition?.[product.id]);
const composing = ref(null);
const stale = computed(() => journal.value.length && clock.value - lastUpdated.value > 30000);
function readRecord(raw) {
    const record = JSON.parse(raw);
    if (!record?.payload?.client_uuid || !/^[a-f0-9]{64}$/.test(record.payload.tracking_key) || !Array.isArray(record.cart)) throw new Error('Invalid journal');
    return record;
}
function mergeRecord(saved, incoming) {
    if (!saved) return incoming;
    let receipt = incoming.receipt ?? saved.receipt;
    if (saved.receipt && saved.receipt.status !== 'pending' && receipt?.status === 'pending') receipt = saved.receipt;
    if (receipt && saved.receipt) {
        receipt = { ...receipt, attendance_cancelled: receipt.attendance_cancelled || saved.receipt.attendance_cancelled,
            items: receipt.items?.map((item, index) => ({ ...item, cancelled: item.cancelled || saved.receipt.items?.[index]?.cancelled,
                production: (saved.receipt.items?.[index]?.production?.version ?? -1) > (item.production?.version ?? -1) ? saved.receipt.items[index].production : item.production })) };
    }
    const validationFailed = saved.validation_failed || incoming.validation_failed;
    return { ...incoming, receipt, validation_failed: validationFailed, uncertain: !receipt && !validationFailed && (saved.uncertain || incoming.uncertain) };
}
function syncJournal() {
    const records = [];
    for (let index = 0; index < localStorage.length; index++) {
        const key = localStorage.key(index);
        if (key?.startsWith(`${storageKey}:`)) records.push(readRecord(localStorage.getItem(key)));
    }
    const active = records.filter(record => !record.validation_failed || record.receipt).sort((a, b) => (a.created_at ?? 0) - (b.created_at ?? 0));
    const known = active.filter(record => !record.uncertain).slice(-20);
    journal.value = [...active.filter(record => record.uncertain), ...known];
    if (active.some(record => record.payload.client_uuid === draftReceiptUuid && record.receipt)) clearOwnDraft(draftReceiptUuid);
}
function persist(record) {
    try {
        const key = `${storageKey}:${record.payload.client_uuid}`;
        const existing = localStorage.getItem(key);
        // Each UUID owns its record: another tab cannot replace its recovery credentials.
        localStorage.setItem(key, JSON.stringify(mergeRecord(existing ? readRecord(existing) : null, record)));
        syncJournal(); return true;
    } catch { error.value = 'Não foi possível guardar o envio neste aparelho. Chame a equipe antes de pedir novamente.'; storageReady.value = false; return false; }
}
function clearOwnDraft(uuid) {
    if (draftReceiptUuid === uuid) { cart.value = []; draftReceiptUuid = null; }
}
function storageChanged(event) {
    if (event.key === null || event.key?.startsWith(`${storageKey}:`)) {
        generation++; tracking.cancel();
        try { syncJournal(); } catch { storageReady.value = false; error.value = 'Falha ao recuperar envios. Chame a equipe.'; }
    }
}
function change(product, delta) {
    if (frozen.value || (delta > 0 && product.sold_out)) return;
    if (isComposed(product)) { if (delta > 0) composing.value = product; return; }
    const line = cart.value.find(item => item.product_id === product.id && !item.key);
    if (delta > 0 && (count.value >= props.limits.max_order_units || (line?.quantity ?? 0) >= props.limits.max_line_units || (!line && cart.value.length >= props.limits.max_lines))) {
        error.value = 'Limite de itens atingido. Confira seu pedido.'; return;
    }
    if (line) { line.quantity += delta; if (line.quantity <= 0) cart.value = cart.value.filter(item => item !== line); }
    else if (delta > 0) cart.value.push({ product_id: product.id, name: product.name, sale_price: product.sale_price, quantity: 1, notes: '' });
}
function withinLimits(line) {
    return count.value < props.limits.max_order_units && (line?.quantity ?? 0) < props.limits.max_line_units && (line || cart.value.length < props.limits.max_lines);
}
function addComposed({ selection, unitCents, label, notes }) {
    const product = composing.value;
    composing.value = null;
    const key = selectionKey(product.id, selection);
    const line = cart.value.find(item => item.key === key && item.notes === notes);
    if (!withinLimits(line)) { error.value = 'Limite de itens atingido. Confira seu pedido.'; return; }
    if (line) line.quantity += 1;
    else cart.value.push({ product_id: product.id, name: product.name, sale_price: fromCents(unitCents), quantity: 1, notes, key, label, fields: payloadFields(selection) });
}
function changeLine(line, delta) {
    if (frozen.value) return;
    if (delta > 0 && !withinLimits(line)) { error.value = 'Limite de itens atingido. Confira seu pedido.'; return; }
    line.quantity += delta;
    if (line.quantity <= 0) cart.value = cart.value.filter(item => item !== line);
}
async function send() {
    if (form.processing || !storageReady.value) return;
    error.value = '';
    const knownUncertain = uncertain.value?.payload.client_uuid;
    try { syncJournal(); } catch { storageReady.value = false; error.value = 'Falha ao recuperar envios. Chame a equipe.'; return; }
    if (uncertain.value && uncertain.value.payload.client_uuid !== knownUncertain) {
        error.value = 'Há um envio sem resposta em outra aba. Confira Meus envios e use Reenviar o mesmo pedido antes de enviar seu novo rascunho.';
        sheet.value = 'receipts'; return;
    }
    generation++; tracking.cancel();
    let record = uncertain.value;
    if (!record) {
        if (!count.value) return;
        const key = Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2, '0')).join('');
        record = { payload: { client_uuid: newUuid(), service_context: props.serviceContext, tracking_key: key,
            items: cart.value.map(({ product_id, quantity, notes, fields }) => ({ product_id, quantity, notes: notes || null, ...(fields ?? {}) })) },
            cart: JSON.parse(JSON.stringify(cart.value)), created_at: Date.now(), uncertain: true, receipt: null };
        draftReceiptUuid = record.payload.client_uuid;
    }
    if (!persist(record)) return;
    const uuid = record.payload.client_uuid;
    const timeout = setTimeout(() => form.cancel(), 15000);
    try {
        const response = await form.transform(() => record.payload).post(route('menu.store', props.token));
        if (!response) {
            error.value = Object.values(form.errors).join(' ') || 'Confira os dados do pedido.';
            persist({ ...record, uncertain: false, validation_failed: true });
            draftReceiptUuid = null; return;
        }
        persist({ ...record, uncertain: false, receipt: response.receipt });
        clearOwnDraft(uuid); sheet.value = 'receipts'; lastUpdated.value = Date.now();
    } catch (exception) {
        const status = exception.response?.status;
        error.value = status === 429 ? 'Muitos envios. Aguarde e use Reenviar o mesmo pedido.' : status === 409
            ? 'Este identificador já foi usado com outro conteúdo. Chame a equipe para conferir.'
            : 'Não foi possível confirmar o recebimento. Seu envio está preservado. Confira com a equipe ou reenvie o mesmo pedido.';
    } finally { clearTimeout(timeout); }
}
async function poll() {
    if (disposed || tracking.processing || form.processing || !journal.value.length) return;
    const responseGeneration = generation;
    let allOk = true;
    for (const record of [...journal.value]) {
        if (disposed || generation !== responseGeneration || form.processing) return;
        const timeout = setTimeout(() => tracking.cancel(), 10000);
        try {
            const response = await tracking.get(route('menu.track', [props.token, record.payload.client_uuid]), { headers: { 'X-Menu-Receipt-Key': record.payload.tracking_key } });
            if (disposed || generation !== responseGeneration) return;
            if (!response) { allOk = false; continue; }
            persist({ ...record, uncertain: false, receipt: response.receipt });
            clearOwnDraft(record.payload.client_uuid);
            if (record.uncertain) error.value = '';
        } catch { allOk = false; } finally { clearTimeout(timeout); }
    }
    if (allOk) lastUpdated.value = Date.now();

}
onMounted(() => {
    try {
        // Preserve any journal from an earlier build before adopting independent UUID records.
        const legacy = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (!Array.isArray(legacy)) throw new Error('Invalid journal');
        for (const record of legacy) {
            const key = `${storageKey}:${record.payload.client_uuid}`;
            if (!localStorage.getItem(key)) localStorage.setItem(key, JSON.stringify(readRecord(JSON.stringify(record))));
        }
        syncJournal();
        if (uncertain.value) { cart.value = JSON.parse(JSON.stringify(uncertain.value.cart)); draftReceiptUuid = uncertain.value.payload.client_uuid; error.value = 'Há um envio sem resposta confirmada. Reenvie o mesmo pedido ou chame a equipe.'; }
        poll();
    } catch { storageReady.value = false; error.value = 'Não foi possível recuperar seus envios neste aparelho. Chame a equipe antes de pedir novamente.'; }
    timer = setInterval(() => clock.value = Date.now(), 1000);
    pollTimer = setInterval(poll, 20000);
    window.addEventListener('storage', storageChanged);
});
onUnmounted(() => { disposed = true; clearInterval(timer); clearInterval(pollTimer); window.removeEventListener('storage', storageChanged); form.cancel(); tracking.cancel(); });
</script>

<template>
    <div class="bg-canvas min-h-dvh pb-24">
        <Head :title="`Cardápio · ${company}`" />


        <header class="bg-sidebar sticky top-0 z-20 px-4 py-3 text-white">
            <p class="truncate font-bold">{{ company }}</p>
            <p class="text-sidebar-text text-sm">Mesa {{ table }}</p>
        </header>

        <nav v-if="categories.length" class="bg-surface border-line sticky top-[3.75rem] z-10 flex gap-2 overflow-x-auto border-b p-3" aria-label="Categorias">
            <button type="button" class="btn shrink-0" :class="category === null ? 'btn-primary' : 'btn-secondary'" @click="category = null">Tudo</button>
            <button v-for="item in categories" :key="item.id" type="button" class="btn shrink-0" :class="category === item.id ? 'btn-primary' : 'btn-secondary'" @click="category = item.id">{{ item.name }}</button>
        </nav>

        <main class="mx-auto max-w-2xl p-3">
            <p class="text-muted mb-3 text-sm">Seu pedido aguarda confirmação da equipe. Limite por envio: {{ formatMoney(limits.max_order_value) }}.</p>
            <p v-if="error" class="text-danger mb-3 text-sm" role="alert">{{ error }}</p>
            <p v-if="stale" class="text-danger mb-3 text-sm" role="alert">Acompanhamento sem atualização há mais de 30 segundos. Confira a conexão e chame a equipe se necessário.</p>
            <button v-if="uncertain" type="button" class="btn btn-primary mb-3" :disabled="form.processing || !storageReady" @click="send">Reenviar o mesmo pedido</button>
            <p v-if="visible.length === 0" class="text-muted py-12 text-center">Cardápio indisponível no momento.</p>
            <ul class="space-y-2">
                <li v-for="product in visible" :key="product.id" class="card flex items-center gap-3 p-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ product.name }}</p><p v-if="product.sold_out" class="text-danger text-sm font-bold">Esgotado</p>
                        <p v-if="product.description" class="text-muted text-sm">{{ product.description }}</p>
                        <p v-if="isComposed(product) && quantityOf(product)" class="text-muted text-sm">{{ quantityOf(product) }} no pedido</p>
                        <p class="text-brand-strong mt-1 font-bold tabular-nums">{{ formatMoney(product.sale_price) }}</p>
                    </div>
                    <div v-if="quantityOf(product) && !isComposed(product)" class="flex items-center gap-2">
                        <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Diminuir ${product.name}`" :disabled="frozen" @click="change(product, -1)"><Minus :size="16" /></button>
                        <span class="w-5 text-center font-bold tabular-nums">{{ quantityOf(product) }}</span>
                        <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Aumentar ${product.name}`" :disabled="frozen || product.sold_out" @click="change(product, 1)"><Plus :size="16" /></button>
                    </div>
                    <button v-else type="button" class="btn btn-primary size-10 px-0" :aria-label="`Adicionar ${product.name}`" :disabled="frozen || product.sold_out" @click="change(product, 1)"><Plus :size="18" /></button>
                </li>
            </ul>
        </main>

        <div class="bg-surface border-line fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t p-3">
            <button v-if="journal.length" type="button" class="btn btn-secondary shrink-0" @click="sheet = 'receipts'">
                <Receipt :size="16" />
                Meus envios
            </button>
            <button type="button" class="btn btn-primary min-h-12 flex-1" :disabled="count === 0" @click="sheet = 'cart'">
                <ShoppingBag :size="18" />
                {{ count ? `Ver pedido (${count}) · ${formatMoney(fromCents(totalCents))}` : 'Escolha seus itens' }}
            </button>
        </div>

        <ItemComposer v-if="composing" :product="composing" :composition="composition[composing.id]" @add="addComposed" @cancel="composing = null" />

        <div v-if="sheet" class="fixed inset-0 z-30 bg-black/40" @click.self="sheet = null">
            <section class="bg-surface absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-t-2xl p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ sheet === 'cart' ? 'Seu pedido' : 'Meus envios' }}</h2>
                    <button type="button" class="btn btn-ghost" aria-label="Fechar" @click="sheet = null"><X :size="18" /></button>
                </div>

                <template v-if="sheet === 'cart'">
                    <ul class="space-y-3">
                        <li v-for="item in cart" :key="item.key ?? item.product_id">
                            <div class="flex justify-between gap-3 text-sm font-semibold">
                                <span>{{ item.quantity }} × {{ item.name }}<span v-if="item.label" class="text-muted block text-sm font-normal">{{ item.label }}</span></span>
                                <span class="tabular-nums">{{ formatMoney(fromCents(lineCents(item.quantity, item.sale_price))) }}</span>
                            </div>
                            <div v-if="item.key" class="mt-1 flex items-center gap-2">
                                <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Diminuir ${item.name}`" :disabled="frozen" @click="changeLine(item, -1)"><Minus :size="16" /></button>
                                <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Aumentar ${item.name}`" :disabled="frozen" @click="changeLine(item, 1)"><Plus :size="16" /></button>
                            </div>
                            <input v-model="item.notes" :disabled="frozen" class="input mt-1" maxlength="120" placeholder="Alguma observação?" :aria-label="`Observação de ${item.name}`" />
                        </li>
                    </ul>
                    <p v-if="form.errors.items" class="text-danger mt-3 text-sm" role="alert">{{ form.errors.items }}</p>
                    <button type="button" class="btn btn-primary mt-4 min-h-12 w-full" :disabled="form.processing || count === 0 || !storageReady" @click="send">
                        {{ uncertain ? 'Reenviar o mesmo pedido' : 'Enviar pedido' }} · {{ formatMoney(fromCents(totalCents)) }}
                    </button>
                </template>

                <template v-else>
                    <article v-for="record in [...journal].reverse()" :key="record.payload.client_uuid" class="border-line mb-3 rounded-lg border p-3 text-sm">
                        <p class="font-bold" role="status">{{ record.receipt?.message ?? 'Aguardando resposta do envio. Não faça outro pedido com os mesmos itens.' }}</p>
                        <ul><li v-for="(item, index) in record.receipt?.items ?? record.cart" :key="index" :class="{ 'line-through': item.cancelled }">{{ formatQuantity(item.quantity) }} × {{ item.product_name ?? item.name }} <span v-if="item.options_label ?? item.label" class="text-muted block text-sm">{{ item.options_label ?? item.label }}</span><span v-if="item.notes">· {{ item.notes }}</span><span v-if="item.production" class="text-muted block text-sm">{{ item.production.cancelled_at ? 'Cancelado' : item.production.delivered_at ? 'Entregue' : { waiting: 'Aguardando preparo', preparing: 'Preparando', ready: 'Pronto' }[item.production.status] }}</span></li></ul>
                        <p v-if="record.receipt" class="mt-2 font-semibold">Valor deste envio: {{ formatMoney(record.receipt.total) }}</p>
                    </article>
                    <p class="text-muted text-sm">Estes são apenas os envios deste aparelho. Para conferir ou fechar a conta completa, chame um atendente. Rejeitados e cancelados não são cobrados.</p>
                </template>
            </section>
        </div>
    </div>
</template>
