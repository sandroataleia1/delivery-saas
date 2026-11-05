<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Banknote, CreditCard, Minus, Plus, QrCode, Search, Ticket, Trash2, TriangleAlert } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';
import { fromCents, lineCents, toCents } from '@/lib/money';

const props = defineProps({
    products: { type: Array, required: true },
    categories: { type: Array, required: true },
    paymentMethods: { type: Object, required: true },
    completed: { type: Object, default: null },
});

const page = usePage();
const { can } = usePermissions();

const methodIcons = { cash: Banknote, pix: QrCode, credit: CreditCard, debit: CreditCard, voucher: Ticket };
const storageKey = `pos-cart-${page.props.auth.company.id}`;

const newUuid = () => (crypto.randomUUID
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16)));

function loadDraft() {
    try {
        return JSON.parse(localStorage.getItem(storageKey)) ?? {};
    } catch {
        return {};
    }
}

const draft = loadDraft();
const cart = ref(draft.cart ?? []);
const saleUuid = ref(draft.uuid ?? newUuid());

const term = ref('');
const category = ref(null);
const notFound = ref(false);
const view = ref('products');
const searchField = ref(null);

const paying = ref(false);
const discount = ref('');
const payments = ref([]);
const receipt = ref(props.completed);

const form = useForm({ client_uuid: '', items: [], discount: null, payments: [], stale: null });

const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const visibleProducts = computed(() => {
    const query = normalize(term.value.trim());

    return props.products
        .filter((product) => category.value === null || product.category_id === category.value)
        .filter((product) => query === '' || normalize(product.name).includes(query))
        .slice(0, 120);
});

const subtotalCents = computed(() => cart.value.reduce((sum, item) => sum + lineCents(item.quantity, item.sale_price), 0));
const discountCents = computed(() => toCents(discount.value));
const totalCents = computed(() => Math.max(subtotalCents.value - discountCents.value, 0));
const itemCount = computed(() => cart.value.length);

const paidCents = computed(() => payments.value.reduce((sum, payment) => sum + toCents(payment.amount), 0));
const cashCents = computed(() => payments.value.filter((payment) => payment.method === 'cash').reduce((sum, payment) => sum + toCents(payment.amount), 0));
const remainingCents = computed(() => Math.max(totalCents.value - paidCents.value, 0));
const changeCents = computed(() => Math.max(paidCents.value - totalCents.value, 0));
const overpaidWithoutCash = computed(() => paidCents.value - cashCents.value > totalCents.value);
const canFinish = computed(() => cart.value.length > 0 && remainingCents.value === 0 && !overpaidWithoutCash.value);

function addProduct(product, quantity = 1) {
    const line = cart.value.find((item) => item.id === product.id);

    if (line) {
        line.quantity = String(Number(line.quantity) + quantity);
    } else {
        cart.value.push({
            id: product.id,
            name: product.name,
            unit: product.unit,
            sale_price: product.sale_price,
            quantity: String(quantity),
        });
    }

    notFound.value = false;
}

function changeQuantity(item, delta) {
    const quantity = Number(item.quantity) + delta;

    if (quantity <= 0) {
        removeItem(item);
    } else {
        item.quantity = String(Number(quantity.toFixed(3)));
    }
}

function removeItem(item) {
    cart.value = cart.value.filter((line) => line !== item);
}

// Aceita "3*7891234" para lançar três unidades de uma vez pelo leitor.
function submitSearch() {
    const match = term.value.trim().match(/^(\d+(?:[.,]\d+)?)\*(.+)$/);
    const quantity = match ? Number(match[1].replace(',', '.')) : 1;
    const code = (match ? match[2] : term.value).trim();

    if (code === '') {
        return;
    }

    const exact = props.products.find((product) => product.barcode === code || product.sku === code);
    const found = exact ?? (visibleProducts.value.length === 1 ? visibleProducts.value[0] : null);

    if (found) {
        addProduct(found, quantity);
        term.value = '';
    } else {
        notFound.value = visibleProducts.value.length === 0;
    }
}

function clearCart() {
    cart.value = [];
    discount.value = '';
    payments.value = [];
    saleUuid.value = newUuid();
}

function openPayment() {
    if (cart.value.length === 0) {
        return;
    }

    form.clearErrors();
    payments.value = [];
    paying.value = true;
}

function addPayment(method) {
    const existing = payments.value.find((payment) => payment.method === method);

    if (existing) {
        existing.amount = fromCents(toCents(existing.amount) + remainingCents.value);
    } else {
        payments.value.push({ method, amount: fromCents(remainingCents.value) });
    }
}

function finish() {
    form.transform(() => ({
        client_uuid: saleUuid.value,
        discount: discount.value || null,
        items: cart.value.map((item) => ({
            product_id: item.id,
            quantity: item.quantity,
            expected_price: item.sale_price,
        })),
        payments: payments.value
            .filter((payment) => toCents(payment.amount) > 0)
            .map((payment) => ({ method: payment.method, amount: fromCents(toCents(payment.amount)) })),
    })).post(route('sales.store'), {
        preserveScroll: true,
        onSuccess: () => {
            paying.value = false;
            clearCart();
            receipt.value = props.completed;
            searchField.value?.focus();
        },
    });
}

function refreshCart() {
    cart.value = cart.value
        .filter((item) => props.products.some((product) => product.id === item.id))
        .map((item) => ({ ...item, sale_price: props.products.find((product) => product.id === item.id).sale_price }));
}

function onKeydown(event) {
    if (event.key === 'F2') {
        event.preventDefault();
        searchField.value?.focus();
    }

    if (event.key === 'F9') {
        event.preventDefault();
        openPayment();
    }
}

watch(() => props.products, refreshCart);

watch([cart, saleUuid], () => {
    try {
        localStorage.setItem(storageKey, JSON.stringify({ cart: cart.value, uuid: saleUuid.value }));
    } catch {
        // Sem armazenamento, o carrinho só não sobrevive a recarregar a página.
    }
}, { deep: true });

onMounted(() => {
    refreshCart();
    window.addEventListener('keydown', onKeydown);
    searchField.value?.focus();
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden">
        <Head title="PDV" />
        <FlashMessages />

        <header class="bg-sidebar flex h-14 shrink-0 items-center gap-3 px-4 text-white">
            <Link :href="route('dashboard')" class="flex items-center gap-2 text-sm font-semibold" aria-label="Sair do PDV">
                <ArrowLeft :size="18" />
                <span class="max-sm:hidden">Sair do PDV</span>
            </Link>
            <span class="min-w-0 flex-1 truncate text-center font-bold">{{ page.props.auth.company.trade_name }}</span>
            <span class="text-sidebar-text text-sm max-sm:hidden">{{ page.props.auth.user.name }}</span>
        </header>

        <div class="flex min-h-0 flex-1">
            <section class="flex min-w-0 flex-1 flex-col" :class="{ 'max-lg:hidden': view === 'cart' }" aria-label="Produtos">
                <div class="border-line bg-surface space-y-3 border-b p-3">
                    <div class="relative">
                        <Search :size="18" class="text-muted pointer-events-none absolute top-3 left-3" />
                        <input
                            ref="searchField"
                            v-model="term"
                            class="input pl-10"
                            type="search"
                            placeholder="Nome ou código de barras (F2)"
                            aria-label="Buscar produto"
                            autocomplete="off"
                            @input="notFound = false"
                            @keydown.enter.prevent="submitSearch"
                        />
                    </div>
                    <p v-if="notFound" class="text-danger text-sm" role="alert">Produto não encontrado.</p>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        <button
                            type="button"
                            class="btn shrink-0"
                            :class="category === null ? 'btn-primary' : 'btn-secondary'"
                            @click="category = null"
                        >
                            Todos
                        </button>
                        <button
                            v-for="item in categories"
                            :key="item.id"
                            type="button"
                            class="btn shrink-0"
                            :class="category === item.id ? 'btn-primary' : 'btn-secondary'"
                            @click="category = item.id"
                        >
                            {{ item.name }}
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto p-3">
                    <p v-if="visibleProducts.length === 0" class="text-muted py-12 text-center">Nenhum produto encontrado.</p>
                    <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                        <li v-for="product in visibleProducts" :key="product.id">
                            <button
                                type="button"
                                class="card hover:border-brand flex h-24 w-full cursor-pointer flex-col justify-between p-3 text-left transition-colors"
                                @click="addProduct(product)"
                            >
                                <span class="line-clamp-2 text-sm leading-tight font-semibold">{{ product.name }}</span>
                                <span class="text-brand-strong text-sm font-bold tabular-nums">{{ formatMoney(product.sale_price) }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <button
                    v-if="itemCount > 0"
                    type="button"
                    class="btn btn-primary m-3 shrink-0 lg:hidden"
                    @click="view = 'cart'"
                >
                    Ver carrinho ({{ itemCount }}) · {{ formatMoney(fromCents(totalCents)) }}
                </button>
            </section>

            <aside
                class="border-line bg-surface flex w-full shrink-0 flex-col border-l lg:w-[26rem]"
                :class="{ 'max-lg:hidden': view === 'products' }"
                aria-label="Carrinho"
            >
                <div class="border-line flex items-center justify-between gap-2 border-b px-4 py-3">
                    <h2 class="font-bold">Carrinho</h2>
                    <button type="button" class="btn btn-ghost lg:hidden" @click="view = 'products'">Adicionar produtos</button>
                    <button v-if="itemCount > 0" type="button" class="btn btn-ghost max-lg:hidden" @click="clearCart">Limpar</button>
                </div>

                <p v-if="itemCount === 0" class="text-muted flex-1 px-4 py-12 text-center text-sm">
                    Toque em um produto ou use o leitor de código de barras.
                </p>

                <ul v-else class="divide-line min-h-0 flex-1 divide-y overflow-y-auto">
                    <li v-for="item in cart" :key="item.id" class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0 text-sm font-semibold break-words">{{ item.name }}</span>
                            <span class="shrink-0 text-sm font-bold tabular-nums">{{ formatMoney(fromCents(lineCents(item.quantity, item.sale_price))) }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Diminuir ${item.name}`" @click="changeQuantity(item, -1)">
                                <Minus :size="16" />
                            </button>
                            <DecimalInput v-model="item.quantity" :decimals="3" class="w-20 text-center" :aria-label="`Quantidade de ${item.name}`" />
                            <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Aumentar ${item.name}`" @click="changeQuantity(item, 1)">
                                <Plus :size="16" />
                            </button>
                            <span class="text-muted min-w-0 flex-1 truncate text-xs tabular-nums">{{ item.unit }} × {{ formatMoney(item.sale_price) }}</span>
                            <button type="button" class="btn btn-ghost" :aria-label="`Remover ${item.name}`" title="Remover" @click="removeItem(item)">
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </li>
                </ul>

                <div class="border-line space-y-2 border-t p-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted">Subtotal</span>
                        <span class="tabular-nums">{{ formatMoney(fromCents(subtotalCents)) }}</span>
                    </div>
                    <div v-if="can('sales.discount')" class="flex items-center justify-between gap-3 text-sm">
                        <label for="sale-discount" class="text-muted">Desconto (R$)</label>
                        <DecimalInput id="sale-discount" v-model="discount" class="w-28 text-right" pad />
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-bold">Total</span>
                        <span class="text-2xl font-bold tabular-nums">{{ formatMoney(fromCents(totalCents)) }}</span>
                    </div>
                    <p v-if="discountCents > subtotalCents" class="text-danger text-sm" role="alert">O desconto é maior que o subtotal.</p>
                    <button type="button" class="btn btn-primary min-h-12 w-full text-base" :disabled="itemCount === 0 || discountCents > subtotalCents" @click="openPayment">
                        Pagar <span class="max-lg:hidden">(F9)</span>
                    </button>
                </div>
            </aside>
        </div>

        <ConfirmDialog
            :open="paying"
            :title="`Pagamento de ${formatMoney(fromCents(totalCents))}`"
            confirm-label="Concluir venda"
            :disabled="!canFinish"
            :processing="form.processing"
            @confirm="finish"
            @cancel="paying = false"
        >
            <div class="mt-4 grid grid-cols-2 gap-2">
                <button
                    v-for="(label, method) in paymentMethods"
                    :key="method"
                    type="button"
                    class="btn btn-secondary justify-start"
                    @click="addPayment(method)"
                >
                    <component :is="methodIcons[method]" :size="16" />
                    {{ label }}
                </button>
            </div>

            <ul v-if="payments.length" class="mt-4 space-y-2">
                <li v-for="(payment, index) in payments" :key="payment.method" class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ paymentMethods[payment.method] }}</span>
                    <DecimalInput v-model="payment.amount" class="w-28 text-right" pad :aria-label="`Valor em ${paymentMethods[payment.method]}`" />
                    <button type="button" class="btn btn-ghost" :aria-label="`Remover ${paymentMethods[payment.method]}`" @click="payments.splice(index, 1)">
                        <Trash2 :size="16" />
                    </button>
                </li>
            </ul>

            <dl class="border-line mt-4 space-y-1 border-t pt-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted">Pago</dt>
                    <dd class="font-semibold tabular-nums">{{ formatMoney(fromCents(paidCents)) }}</dd>
                </div>
                <div v-if="remainingCents > 0" class="flex justify-between">
                    <dt class="text-muted">Falta</dt>
                    <dd class="text-danger font-bold tabular-nums">{{ formatMoney(fromCents(remainingCents)) }}</dd>
                </div>
                <div v-if="changeCents > 0 && !overpaidWithoutCash" class="flex justify-between text-base">
                    <dt class="font-bold">Troco</dt>
                    <dd class="text-ok font-bold tabular-nums">{{ formatMoney(fromCents(changeCents)) }}</dd>
                </div>
            </dl>

            <p v-if="overpaidWithoutCash" class="text-danger mt-2 text-sm" role="alert">Pix, cartão e vale não podem passar do total: só dinheiro tem troco.</p>
            <p v-for="message in [form.errors.items, form.errors.payments, form.errors.discount].filter(Boolean)" :key="message" class="text-danger mt-2 text-sm" role="alert">{{ message }}</p>
        </ConfirmDialog>

        <ConfirmDialog
            :open="receipt !== null"
            :title="`Venda #${receipt?.number ?? ''} concluída`"
            confirm-label="Nova venda"
            cancel-label="Fechar"
            @confirm="receipt = null"
            @cancel="receipt = null"
        >
            <div v-if="receipt" class="mt-4 space-y-3">
                <p class="text-sm">Total: <strong class="tabular-nums">{{ formatMoney(receipt.total) }}</strong></p>
                <div v-if="Number(receipt.change) > 0" class="bg-ok-soft rounded-lg px-4 py-3">
                    <p class="text-ok text-sm font-semibold">Troco</p>
                    <p class="text-ok text-3xl font-bold tabular-nums">{{ formatMoney(receipt.change) }}</p>
                </div>
                <div v-if="receipt.negative.length" class="bg-warn-soft text-warn flex gap-2 rounded-lg px-3 py-2 text-sm">
                    <TriangleAlert :size="16" class="mt-0.5 shrink-0" />
                    <div>
                        <p class="font-semibold">Estoque ficou negativo:</p>
                        <p>{{ receipt.negative.join(', ') }}</p>
                    </div>
                </div>
                <a :href="route('sales.receipt', receipt.id)" target="_blank" class="btn btn-secondary w-full">Imprimir cupom</a>
            </div>
        </ConfirmDialog>
    </div>
</template>
