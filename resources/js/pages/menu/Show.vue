<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePoll } from '@inertiajs/vue3';
import { Minus, Plus, Receipt, ShoppingBag, X } from '@lucide/vue';
import FlashMessages from '@/components/FlashMessages.vue';
import { formatMoney, formatQuantity } from '@/lib/format';
import { fromCents, lineCents, newUuid } from '@/lib/money';

const props = defineProps({
    token: { type: String, required: true },
    company: { type: String, required: true },
    table: { type: Number, required: true },
    categories: { type: Array, required: true },
    products: { type: Array, required: true },
    order: { type: Object, default: null },
});

usePoll(20000, { only: ['order'] });

const knownOrderId = ref(props.order?.id ?? null);
const category = ref(null);
const cart = ref([]);
const sheet = ref(null);
const uuid = ref(newUuid());
const form = useForm({});

const categories = computed(() => props.categories.filter((item) => props.products.some((product) => product.category_id === item.id)));
const visible = computed(() => props.products.filter((product) => category.value === null || product.category_id === category.value));
const count = computed(() => cart.value.reduce((sum, item) => sum + item.quantity, 0));
const totalCents = computed(() => cart.value.reduce((sum, item) => sum + lineCents(item.quantity, item.sale_price), 0));
const quantityOf = (product) => cart.value.find((item) => item.product_id === product.id)?.quantity ?? 0;

function change(product, delta) {
    const line = cart.value.find((item) => item.product_id === product.id);

    if (line) {
        line.quantity += delta;

        if (line.quantity <= 0) {
            cart.value = cart.value.filter((item) => item !== line);
        }
    } else if (delta > 0) {
        cart.value.push({ product_id: product.id, name: product.name, sale_price: product.sale_price, quantity: 1, notes: '' });
    }
}

function send() {
    form.transform(() => ({
        client_uuid: uuid.value,
        known_order_id: knownOrderId.value,
        items: cart.value.map(({ product_id, quantity, notes }) => ({ product_id, quantity, notes: notes || null })),
    })).post(route('menu.store', props.token), {
        preserveScroll: true,
        onSuccess: () => {
            cart.value = [];
            sheet.value = null;
            uuid.value = newUuid();
            knownOrderId.value = props.order?.id ?? null;
        },
    });
}
</script>

<template>
    <div class="bg-canvas min-h-dvh pb-24">
        <Head :title="`Cardápio · ${company}`" />
        <FlashMessages top />

        <header class="bg-sidebar sticky top-0 z-20 px-4 py-3 text-white">
            <p class="truncate font-bold">{{ company }}</p>
            <p class="text-sidebar-text text-sm">Mesa {{ table }}</p>
        </header>

        <nav v-if="categories.length" class="bg-surface border-line sticky top-[3.75rem] z-10 flex gap-2 overflow-x-auto border-b p-3" aria-label="Categorias">
            <button type="button" class="btn shrink-0" :class="category === null ? 'btn-primary' : 'btn-secondary'" @click="category = null">Tudo</button>
            <button v-for="item in categories" :key="item.id" type="button" class="btn shrink-0" :class="category === item.id ? 'btn-primary' : 'btn-secondary'" @click="category = item.id">{{ item.name }}</button>
        </nav>

        <main class="mx-auto max-w-2xl p-3">
            <p v-if="visible.length === 0" class="text-muted py-12 text-center">Cardápio indisponível no momento.</p>
            <ul class="space-y-2">
                <li v-for="product in visible" :key="product.id" class="card flex items-center gap-3 p-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ product.name }}</p>
                        <p v-if="product.description" class="text-muted text-sm">{{ product.description }}</p>
                        <p class="text-brand-strong mt-1 font-bold tabular-nums">{{ formatMoney(product.sale_price) }}</p>
                    </div>
                    <div v-if="quantityOf(product)" class="flex items-center gap-2">
                        <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Diminuir ${product.name}`" @click="change(product, -1)"><Minus :size="16" /></button>
                        <span class="w-5 text-center font-bold tabular-nums">{{ quantityOf(product) }}</span>
                        <button type="button" class="btn btn-secondary size-10 px-0" :aria-label="`Aumentar ${product.name}`" @click="change(product, 1)"><Plus :size="16" /></button>
                    </div>
                    <button v-else type="button" class="btn btn-primary size-10 px-0" :aria-label="`Adicionar ${product.name}`" @click="change(product, 1)"><Plus :size="18" /></button>
                </li>
            </ul>
        </main>

        <div class="bg-surface border-line fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t p-3">
            <button v-if="order" type="button" class="btn btn-secondary shrink-0" @click="sheet = 'bill'">
                <Receipt :size="16" />
                Minha conta
            </button>
            <button type="button" class="btn btn-primary min-h-12 flex-1" :disabled="count === 0" @click="sheet = 'cart'">
                <ShoppingBag :size="18" />
                {{ count ? `Ver pedido (${count}) · ${formatMoney(fromCents(totalCents))}` : 'Escolha seus itens' }}
            </button>
        </div>

        <div v-if="sheet" class="fixed inset-0 z-30 bg-black/40" @click.self="sheet = null">
            <section class="bg-surface absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-y-auto rounded-t-2xl p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ sheet === 'cart' ? 'Seu pedido' : 'Minha conta' }}</h2>
                    <button type="button" class="btn btn-ghost" aria-label="Fechar" @click="sheet = null"><X :size="18" /></button>
                </div>

                <template v-if="sheet === 'cart'">
                    <ul class="space-y-3">
                        <li v-for="item in cart" :key="item.product_id">
                            <div class="flex justify-between gap-3 text-sm font-semibold">
                                <span>{{ item.quantity }} × {{ item.name }}</span>
                                <span class="tabular-nums">{{ formatMoney(fromCents(lineCents(item.quantity, item.sale_price))) }}</span>
                            </div>
                            <input v-model="item.notes" class="input mt-1" maxlength="120" placeholder="Alguma observação?" :aria-label="`Observação de ${item.name}`" />
                        </li>
                    </ul>
                    <p v-if="form.errors.items" class="text-danger mt-3 text-sm" role="alert">{{ form.errors.items }}</p>
                    <button type="button" class="btn btn-primary mt-4 min-h-12 w-full" :disabled="form.processing || count === 0" @click="send">
                        Enviar pedido · {{ formatMoney(fromCents(totalCents)) }}
                    </button>
                </template>

                <template v-else-if="order">
                    <ul class="divide-line divide-y text-sm">
                        <li v-for="item in order.items" :key="item.id" class="flex justify-between gap-3 py-2">
                            <span>{{ formatQuantity(item.quantity) }} × {{ item.product_name }}</span>
                            <span class="tabular-nums">{{ formatMoney(item.total) }}</span>
                        </li>
                    </ul>
                    <p class="border-line mt-2 flex justify-between border-t pt-3 text-lg font-bold">
                        <span>Total</span>
                        <span class="tabular-nums">{{ formatMoney(order.subtotal) }}</span>
                    </p>
                    <p class="text-muted mt-1 text-sm">Para fechar, chame um atendente.</p>
                </template>
            </section>
        </div>
    </div>
</template>
