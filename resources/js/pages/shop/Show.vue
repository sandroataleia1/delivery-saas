<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, useHttp } from '@inertiajs/vue3';
import { Clock, Minus, Plus, Receipt, ShoppingBag, X } from '@lucide/vue';
import ItemComposer from '@/components/ItemComposer.vue';
import { lineError, payloadFields, selectionKey } from '@/lib/composition';
import { formatMoney } from '@/lib/format';
import { fromCents, newUuid, toCents } from '@/lib/money';
import { addLine, recallCustomer, recallOrders, rememberCustomer, rememberOrder, subtotalCents, toPayload, totalCents } from '@/lib/shopCart';

const props = defineProps({
    store: { type: Object, required: true },
    categories: { type: Array, required: true },
    products: { type: Array, required: true },
    composition: { type: Object, default: () => ({}) },
    zones: { type: Array, required: true },
    payment_methods: { type: Object, required: true },
});

const storage = (() => {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
})();

const category = ref(null);
const cart = ref([]);
const sheet = ref(null);
const composing = ref(null);
const error = ref('');
const myOrders = ref([]);
const http = useHttp({});
let pendingUuid = null;

const checkout = reactive({
    type: props.store.accepts_delivery ? 'delivery' : 'pickup',
    name: '',
    phone: '',
    address: { street: '', number: '', complement: '', reference: '', delivery_zone_id: '' },
    payment_method: Object.keys(props.payment_methods)[0] ?? 'cash',
    change_for: '',
    notes: '',
});

const categories = computed(() => props.categories.filter((item) => props.products.some((product) => product.category_id === item.id)));
const visible = computed(() => props.products.filter((product) => category.value === null || product.category_id === category.value));
const count = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));
const subtotal = computed(() => subtotalCents(cart.value));
const zone = computed(() => props.zones.find((item) => item.id === Number(checkout.address.delivery_zone_id)) ?? null);
const feeCents = computed(() => (checkout.type === 'delivery' && zone.value ? toCents(zone.value.fee) : 0));
const total = computed(() => totalCents(cart.value, feeCents.value));
const minOrderCents = computed(() => toCents(props.store.min_order));
const belowMinimum = computed(() => count.value > 0 && subtotal.value < minOrderCents.value);
const isComposed = (product) => Boolean(props.composition?.[product.id]);
const quantityOf = (product) => cart.value.filter((item) => item.product_id === product.id).reduce((sum, item) => sum + item.quantity, 0);
const topErrors = computed(() => Object.entries(http.errors).filter(([key]) => !/^items\.\d+(\.|$)/.test(key)).map(([, message]) => message));
const canSend = computed(() => props.store.open && count.value > 0 && !http.processing);

/** Um envio sem resposta confirmada mantém o mesmo client_uuid; mudar a sacola ou os dados gera outro. */
watch([cart, checkout], () => {
    pendingUuid = null;
}, { deep: true });

/** Erros de linha usam o índice do envio; ao mudar a sacola eles deixam de apontar para a linha certa. */
watch(cart, () => http.clearErrors(), { deep: true });

function add(product) {
    if (product.sold_out) {
        return;
    }
    if (isComposed(product)) {
        composing.value = product;

        return;
    }
    cart.value = addLine(cart.value, { product_id: product.id, name: product.name, unit_cents: toCents(product.sale_price), quantity: 1, notes: '', key: String(product.id), label: null, fields: {} });
}

function addComposed({ selection, unitCents, label, notes }) {
    const product = composing.value;
    composing.value = null;
    cart.value = addLine(cart.value, { product_id: product.id, name: product.name, unit_cents: unitCents, quantity: 1, notes, key: selectionKey(product.id, selection), label, fields: payloadFields(selection) });
}

function changeLine(line, delta) {
    cart.value = cart.value
        .map((item) => (item.uid === line.uid ? { ...item, quantity: item.quantity + delta } : item))
        .filter((item) => item.quantity > 0);
}

function setNotes(line, notes) {
    cart.value = cart.value.map((item) => (item.uid === line.uid ? { ...item, notes } : item));
}

function payload() {
    const delivery = checkout.type === 'delivery';

    return {
        client_uuid: pendingUuid,
        type: checkout.type,
        customer: { name: checkout.name.trim(), phone: checkout.phone.trim() },
        ...(delivery ? { address: { ...checkout.address, delivery_zone_id: Number(checkout.address.delivery_zone_id) || null } } : {}),
        items: toPayload(cart.value),
        payment_method: checkout.payment_method,
        change_for: checkout.payment_method === 'cash' && checkout.change_for !== '' ? checkout.change_for.replace(',', '.') : null,
        notes: checkout.notes.trim() || null,
    };
}

async function send() {
    if (!canSend.value) {
        return;
    }
    error.value = '';
    pendingUuid ??= newUuid();
    const body = payload();
    const timeout = setTimeout(() => http.cancel(), 15000);
    try {
        const response = await http.transform(() => body).post(route('shop.store', props.store.slug), { headers: { Accept: 'application/json' } });
        if (!response) {
            // 422: nada foi gravado, então o próximo envio pode usar outro identificador.
            pendingUuid = null;

            return;
        }
        rememberCustomer(storage, props.store.slug, { name: body.customer.name, phone: body.customer.phone, address: body.address ?? recallCustomer(storage, props.store.slug)?.address ?? null });
        rememberOrder(storage, props.store.slug, response);
        window.location.href = response.tracking_url;
    } catch (exception) {
        const status = exception.response?.status;
        error.value = status === 429
            ? 'Muitos envios em pouco tempo. Aguarde um instante e tente de novo.'
            : status === 409
                ? 'Este pedido mudou depois de um envio sem resposta. Confira em "Meus pedidos" antes de enviar de novo.'
                : 'Não foi possível confirmar o envio. Sua sacola está guardada: tente de novo, o mesmo pedido não será duplicado.';
        if (status === 409) {
            pendingUuid = null;
        }
    } finally {
        clearTimeout(timeout);
    }
}

onMounted(() => {
    const saved = recallCustomer(storage, props.store.slug);
    if (saved) {
        checkout.name = saved.name;
        checkout.phone = saved.phone;
        if (saved.address) {
            const known = props.zones.some((item) => item.id === Number(saved.address.delivery_zone_id));
            Object.assign(checkout.address, { ...saved.address, delivery_zone_id: known ? saved.address.delivery_zone_id : '' });
        }
    }
    myOrders.value = recallOrders(storage, props.store.slug);
});

onUnmounted(() => http.cancel());
</script>

<template>
    <div class="bg-canvas min-h-dvh overflow-x-hidden pb-24">
        <Head :title="store.name" />

        <header class="bg-surface border-line border-b px-4 py-4">
            <div class="mx-auto flex max-w-2xl items-center gap-3">
                <img v-if="store.logo_url" :src="store.logo_url" alt="" class="size-14 shrink-0 rounded-xl object-cover" />
                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-lg font-bold">{{ store.name }}</h1>
                    <p class="text-sm font-semibold" :class="store.open ? 'text-ok' : 'text-danger'" role="status">{{ store.label }}</p>
                </div>
                <button v-if="myOrders.length" type="button" class="btn btn-secondary shrink-0" @click="sheet = 'orders'">
                    <Receipt :size="16" />
                    Meus pedidos
                </button>
            </div>
            <ul class="text-muted mx-auto mt-2 flex max-w-2xl flex-wrap gap-x-4 gap-y-1 text-sm">
                <li v-if="store.eta_text" class="flex items-center gap-1"><Clock :size="14" /> {{ store.eta_text }}</li>
                <li v-if="store.accepts_delivery && zones.length">Taxa por bairro</li>
                <li v-if="store.accepts_pickup">Retirada no local</li>
                <li v-if="minOrderCents > 0">Pedido mínimo {{ formatMoney(store.min_order) }}</li>
            </ul>
        </header>

        <div v-if="!store.open" class="bg-danger-soft text-danger px-4 py-2 text-center text-sm font-semibold" role="alert">
            {{ store.label }}. Você pode montar a sacola, mas o envio só abre no horário de funcionamento.
        </div>

        <nav v-if="categories.length" class="bg-surface border-line sticky top-0 z-10 border-b" aria-label="Categorias">
            <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-3">
                <button type="button" class="btn shrink-0" :class="category === null ? 'btn-primary' : 'btn-secondary'" @click="category = null">Tudo</button>
                <button v-for="item in categories" :key="item.id" type="button" class="btn shrink-0" :class="category === item.id ? 'btn-primary' : 'btn-secondary'" @click="category = item.id">{{ item.name }}</button>
            </div>
        </nav>

        <main class="mx-auto max-w-2xl px-4 py-3">
            <p v-if="visible.length === 0" class="text-muted py-12 text-center">Cardápio indisponível no momento.</p>
            <ul class="space-y-2">
                <li v-for="product in visible" :key="product.id" class="card flex items-center gap-3 p-3" :class="{ 'opacity-60': product.sold_out }">
                    <img v-if="product.image_url" :src="product.image_url" alt="" loading="lazy" class="size-20 shrink-0 rounded-lg object-cover" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ product.name }}</p>
                        <p v-if="product.sold_out" class="text-danger text-sm font-bold">Esgotado</p>
                        <p v-if="product.description" class="text-muted line-clamp-2 text-sm">{{ product.description }}</p>
                        <p class="text-brand-strong mt-1 font-bold tabular-nums">
                            {{ isComposed(product) ? `a partir de ${formatMoney(composition[product.id].from_price)}` : formatMoney(product.sale_price) }}
                        </p>
                        <p v-if="quantityOf(product)" class="text-muted text-sm">{{ quantityOf(product) }} na sacola</p>
                    </div>
                    <button type="button" class="btn btn-primary size-10 shrink-0 px-0" :aria-label="`Adicionar ${product.name}`" :disabled="product.sold_out" @click="add(product)"><Plus :size="18" /></button>
                </li>
            </ul>
        </main>

        <div class="bg-surface border-line fixed inset-x-0 bottom-0 z-20 border-t px-4 py-3">
            <button type="button" class="btn btn-primary mx-auto flex min-h-12 w-full max-w-2xl" :disabled="count === 0" @click="sheet = 'bag'">
                <ShoppingBag :size="18" />
                {{ count ? `Ver sacola · ${count} ${count === 1 ? 'item' : 'itens'} · ${formatMoney(fromCents(subtotal))}` : 'Sua sacola está vazia' }}
            </button>
        </div>

        <ItemComposer v-if="composing" :product="composing" :composition="composition[composing.id]" @add="addComposed" @cancel="composing = null" />

        <div v-if="sheet" class="fixed inset-0 z-30 bg-black/40" @click.self="sheet = null">
            <section class="bg-surface absolute inset-x-0 bottom-0 mx-auto max-h-[90dvh] max-w-2xl overflow-y-auto rounded-t-2xl px-4 py-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ sheet === 'bag' ? 'Sua sacola' : 'Meus pedidos' }}</h2>
                    <button type="button" class="btn btn-ghost" aria-label="Fechar" @click="sheet = null"><X :size="18" /></button>
                </div>

                <form v-if="sheet === 'bag'" class="space-y-4" @submit.prevent="send">
                    <div v-if="error || topErrors.length" class="bg-danger-soft text-danger space-y-1 rounded-lg p-3 text-sm" role="alert">
                        <p v-if="error">{{ error }}</p>
                        <p v-for="(message, index) in topErrors" :key="index">{{ message }}</p>
                    </div>
                    <p v-if="!store.open" class="text-danger text-sm font-semibold" role="status">{{ store.label }}</p>

                    <ul class="divide-line divide-y">
                        <li v-for="(item, index) in cart" :key="item.uid" class="py-3">
                            <div class="flex justify-between gap-3 text-sm font-semibold">
                                <span class="min-w-0">{{ item.quantity }} × {{ item.name }}<span v-if="item.label" class="text-muted block font-normal">{{ item.label }}</span></span>
                                <span class="shrink-0 tabular-nums">{{ formatMoney(fromCents(item.unit_cents * item.quantity)) }}</span>
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button" class="btn btn-secondary size-10 shrink-0 px-0" :aria-label="`Diminuir ${item.name}`" @click="changeLine(item, -1)"><Minus :size="16" /></button>
                                <button type="button" class="btn btn-secondary size-10 shrink-0 px-0" :aria-label="`Aumentar ${item.name}`" @click="changeLine(item, 1)"><Plus :size="16" /></button>
                                <input :value="item.notes" class="input min-w-0 flex-1" maxlength="120" placeholder="Observação" :aria-label="`Observação de ${item.name}`" @change="setNotes(item, $event.target.value)" />
                            </div>
                            <p v-if="lineError(http.errors, index)" class="text-danger mt-1 text-sm" role="alert">{{ lineError(http.errors, index) }}</p>
                        </li>
                    </ul>

                    <fieldset v-if="store.accepts_delivery && store.accepts_pickup" class="grid grid-cols-2 gap-2">
                        <legend class="sr-only">Entrega ou retirada</legend>
                        <button type="button" class="btn" :class="checkout.type === 'delivery' ? 'btn-primary' : 'btn-secondary'" @click="checkout.type = 'delivery'">Entrega</button>
                        <button type="button" class="btn" :class="checkout.type === 'pickup' ? 'btn-primary' : 'btn-secondary'" @click="checkout.type = 'pickup'">Retirar</button>
                    </fieldset>
                    <p v-else class="text-sm font-semibold">{{ checkout.type === 'delivery' ? 'Somente entrega' : 'Somente retirada no local' }}</p>

                    <div class="grid gap-3">
                        <label class="grid gap-1 text-sm font-medium">Nome
                            <input v-model="checkout.name" class="input" autocomplete="name" required minlength="2" maxlength="80" />
                        </label>
                        <label class="grid gap-1 text-sm font-medium">WhatsApp com DDD
                            <input v-model="checkout.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" required maxlength="30" placeholder="(11) 99999-0000" />
                        </label>
                    </div>

                    <div v-if="checkout.type === 'delivery'" class="grid gap-3">
                        <label class="grid gap-1 text-sm font-medium">Bairro
                            <select v-model="checkout.address.delivery_zone_id" class="input" required>
                                <option value="" disabled>Escolha o bairro</option>
                                <option v-for="item in zones" :key="item.id" :value="item.id">{{ item.name }} · taxa {{ formatMoney(item.fee) }}</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-[1fr_6rem] gap-2">
                            <label class="grid min-w-0 gap-1 text-sm font-medium">Rua
                                <input v-model="checkout.address.street" class="input" autocomplete="address-line1" required maxlength="120" />
                            </label>
                            <label class="grid min-w-0 gap-1 text-sm font-medium">Número
                                <input v-model="checkout.address.number" class="input" required maxlength="20" />
                            </label>
                        </div>
                        <label class="grid gap-1 text-sm font-medium">Complemento
                            <input v-model="checkout.address.complement" class="input" maxlength="100" />
                        </label>
                        <label class="grid gap-1 text-sm font-medium">Referência
                            <input v-model="checkout.address.reference" class="input" maxlength="150" />
                        </label>
                    </div>

                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">Pagamento na entrega</legend>
                        <label v-for="(name, method) in payment_methods" :key="method" class="border-line flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm">
                            <input v-model="checkout.payment_method" type="radio" name="payment_method" :value="method" class="accent-brand size-4" />
                            {{ name }}
                        </label>
                    </fieldset>
                    <label v-if="checkout.payment_method === 'cash'" class="grid gap-1 text-sm font-medium">Troco para quanto? (opcional)
                        <input v-model="checkout.change_for" class="input" inputmode="decimal" maxlength="9" placeholder="Ex.: 100" />
                    </label>

                    <label class="grid gap-1 text-sm font-medium">Observação do pedido
                        <textarea v-model="checkout.notes" class="input" rows="2" maxlength="255"></textarea>
                    </label>

                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatMoney(fromCents(subtotal)) }}</dd></div>
                        <div class="flex justify-between"><dt>Taxa de entrega</dt><dd class="tabular-nums">{{ checkout.type === 'delivery' && !zone ? 'escolha o bairro' : formatMoney(fromCents(feeCents)) }}</dd></div>
                        <div class="flex justify-between text-base font-bold"><dt>Total</dt><dd class="tabular-nums">{{ formatMoney(fromCents(total)) }}</dd></div>
                    </dl>
                    <p v-if="belowMinimum" class="text-warn text-sm font-semibold">Pedido mínimo de {{ formatMoney(store.min_order) }} (sem a taxa).</p>

                    <button type="submit" class="btn btn-primary min-h-12 w-full" :disabled="!canSend">
                        {{ http.processing ? 'Enviando…' : `Enviar pedido · ${formatMoney(fromCents(total))}` }}
                    </button>
                </form>

                <template v-else>
                    <ul class="space-y-2">
                        <li v-for="order in myOrders" :key="order.tracking_url">
                            <a :href="order.tracking_url" class="border-line flex justify-between rounded-lg border p-3 text-sm font-semibold">
                                Pedido #{{ order.number }}
                                <span class="text-brand-strong">Acompanhar</span>
                            </a>
                        </li>
                    </ul>
                    <p class="text-muted mt-3 text-sm">Só os pedidos feitos neste aparelho aparecem aqui.</p>
                </template>
            </section>
        </div>
    </div>
</template>
