<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Minus, Plus, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import PaymentPanel from '@/components/PaymentPanel.vue';
import ProductGrid from '@/components/ProductGrid.vue';
import SaleCompleteDialog from '@/components/SaleCompleteDialog.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';
import { fromCents, lineCents, newUuid, toCents } from '@/lib/money';

const props = defineProps({
    products: { type: Array, required: true },
    categories: { type: Array, required: true },
    paymentMethods: { type: Object, required: true },
    cashNumber: { type: Number, required: true },
    completed: { type: Object, default: null },
});

const page = usePage();
const { can } = usePermissions();

const storageKey = `pos-cart-${page.props.auth.company.id}`;

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

const view = ref('products');
const grid = ref(null);

const paying = ref(false);
const discount = ref('');
const payments = ref([]);
const paymentValid = ref(false);
const receipt = ref(props.completed);

const form = useForm({ client_uuid: '', items: [], discount: null, payments: [], stale: null });

const subtotalCents = computed(() => cart.value.reduce((sum, item) => sum + lineCents(item.quantity, item.sale_price), 0));
const discountCents = computed(() => toCents(discount.value));
const totalCents = computed(() => Math.max(subtotalCents.value - discountCents.value, 0));
const itemCount = computed(() => cart.value.length);

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
            grid.value?.focus();
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
        grid.value?.focus();
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
    grid.value?.focus();
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden">
        <Head title="PDV" />
        <FlashMessages top />

        <header class="bg-sidebar flex h-14 shrink-0 items-center gap-3 px-4 text-white">
            <Link :href="route('dashboard')" class="flex items-center gap-2 text-sm font-semibold" aria-label="Sair do PDV">
                <ArrowLeft :size="18" />
                <span class="max-sm:hidden">Sair do PDV</span>
            </Link>
            <span class="min-w-0 flex-1 truncate text-center font-bold">{{ page.props.auth.company.trade_name }}</span>
            <Link :href="route('cash.index')" class="text-sidebar-text text-sm hover:text-white">Caixa #{{ cashNumber }}</Link>
            <span class="text-sidebar-text text-sm max-sm:hidden">{{ page.props.auth.user.name }}</span>
        </header>

        <div class="flex min-h-0 flex-1">
            <section class="flex min-w-0 flex-1 flex-col" :class="{ 'max-lg:hidden': view === 'cart' }" aria-label="Produtos">
                <ProductGrid ref="grid" :products="products" :categories="categories" placeholder="Nome ou código de barras (F2)" @add="addProduct" />

                <button v-if="itemCount > 0" type="button" class="btn btn-primary m-3 shrink-0 lg:hidden" @click="view = 'cart'">
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
            :disabled="!paymentValid || itemCount === 0"
            :processing="form.processing"
            @confirm="finish"
            @cancel="paying = false"
        >
            <PaymentPanel v-model:payments="payments" v-model:valid="paymentValid" :methods="paymentMethods" :total-cents="totalCents" />
            <p v-for="message in [form.errors.cash, form.errors.items, form.errors.payments, form.errors.discount].filter(Boolean)" :key="message" class="text-danger mt-2 text-sm" role="alert">{{ message }}</p>
        </ConfirmDialog>

        <SaleCompleteDialog :sale="receipt" @close="receipt = null" />
    </div>
</template>
