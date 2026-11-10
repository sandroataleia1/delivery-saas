<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { ArrowLeft, Minus, Plus, Trash2, UserPlus } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CustomerPicker from '@/components/CustomerPicker.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import ItemComposer from '@/components/ItemComposer.vue';
import Field from '@/components/Field.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import ProductGrid from '@/components/ProductGrid.vue';
import { lineError, payloadFields, selectionKey } from '@/lib/composition';
import { formatMoney, formatPhone } from '@/lib/format';
import { fromCents, lineCents, newUuid, toCents } from '@/lib/money';

const props = defineProps({
    products: { type: Array, required: true },
    composition: { type: Object, default: () => ({}) },
    categories: { type: Array, required: true },
    zones: { type: Array, required: true },
    paymentMethods: { type: Object, required: true },
});

const view = ref('menu');
const customer = ref(null);
const cart = ref([]);
const type = ref('delivery');
const fee = ref('');
const paymentMethod = ref('pix');
const changeFor = ref('');
const notes = ref('');
const address = ref({ street: '', number: '', complement: '', district: '', reference: '' });
const orderUuid = ref(newUuid());
const form = useForm({});

const methods = computed(() => Object.fromEntries(Object.entries(props.paymentMethods).filter(([key]) => key !== 'fiado' || customer.value?.allow_credit)));
const subtotalCents = computed(() => cart.value.reduce((sum, item) => sum + lineCents(item.quantity, item.sale_price), 0));
const feeCents = computed(() => (type.value === 'delivery' ? toCents(fee.value) : 0));
const totalCents = computed(() => subtotalCents.value + feeCents.value);

const composing = ref(null);
let lineSequence = 0;

function add(product, quantity = 1) {
    if (props.composition[product.id]) {
        composing.value = { product, quantity };

        return;
    }

    const line = cart.value.find((item) => item.product_id === product.id && !item.key && !item.notes);

    if (line) {
        line.quantity = String(Number(line.quantity) + quantity);
    } else {
        cart.value.push({ uid: ++lineSequence, product_id: product.id, name: product.name, sale_price: product.sale_price, quantity: String(quantity), notes: '' });
    }
}

function addComposed({ selection, unitCents, label, notes }) {
    const { product, quantity } = composing.value;
    const key = selectionKey(product.id, selection);
    const line = cart.value.find((item) => item.key === key && item.notes === notes);
    composing.value = null;

    if (line) {
        line.quantity = String(Number(line.quantity) + quantity);
    } else {
        cart.value.push({ uid: ++lineSequence, product_id: product.id, name: product.name, sale_price: fromCents(unitCents), quantity: String(quantity), notes, key, selection, label });
    }
}

function change(item, delta) {
    const quantity = Number(item.quantity) + delta;

    if (quantity <= 0) {
        cart.value = cart.value.filter((line) => line !== item);
    } else {
        item.quantity = String(quantity);
    }
}

function useAddress(saved) {
    address.value = {
        street: saved.street,
        number: saved.number,
        complement: saved.complement ?? '',
        district: saved.district,
        reference: saved.reference ?? '',
    };
}

watch(customer, (selected) => {
    const saved = selected?.addresses?.[0];

    if (saved) {
        useAddress(saved);
    } else {
        address.value = { street: '', number: '', complement: '', district: '', reference: '' };
    }
});

// A taxa do bairro é uma sugestão: o atendente pode alterar.
watch(() => address.value.district, (district) => {
    const zone = props.zones.find((item) => item.name.toLowerCase() === district.trim().toLowerCase());

    if (zone) {
        fee.value = zone.fee;
    }
});

function submit() {
    form.transform(() => ({
        client_uuid: orderUuid.value,
        customer_id: customer.value?.id,
        type: type.value,
        items: cart.value.map(({ product_id, quantity, notes: itemNotes, selection }) => ({ product_id, quantity, notes: itemNotes || null, ...payloadFields(selection) })),
        address: type.value === 'delivery' ? address.value : null,
        delivery_fee: fee.value || 0,
        payment_method: paymentMethod.value,
        change_for: paymentMethod.value === 'cash' ? changeFor.value || null : null,
        notes: notes.value || null,
    })).post(route('deliveries.store'));
}

const creating = ref(false);
const newCustomer = useHttp({ name: '', phone: '' });

function startCreate() {
    newCustomer.name = '';
    newCustomer.phone = '';
    newCustomer.clearErrors();
    creating.value = true;
}

function createCustomer() {
    newCustomer.post(route('customers.store'), {
        onSuccess: (created) => {
            customer.value = created;
            creating.value = false;
        },
    });
}
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden">
        <Head title="Novo pedido" />
        <FlashMessages top />

        <header class="bg-sidebar flex h-14 shrink-0 items-center gap-3 px-4 text-white">
            <Link :href="route('deliveries.board')" class="flex items-center gap-2 text-sm font-semibold" aria-label="Voltar às entregas">
                <ArrowLeft :size="18" />
                <span class="max-sm:hidden">Entregas</span>
            </Link>
            <span class="min-w-0 flex-1 truncate text-center font-bold">Novo pedido</span>
            <span class="text-sm font-bold tabular-nums">{{ formatMoney(fromCents(totalCents)) }}</span>
        </header>

        <div class="border-line bg-surface grid shrink-0 grid-cols-2 border-b lg:hidden">
            <button type="button" class="cursor-pointer py-3 text-sm font-semibold" :class="view === 'menu' ? 'border-brand text-brand border-b-2' : 'text-muted'" @click="view = 'menu'">Cardápio</button>
            <button type="button" class="cursor-pointer py-3 text-sm font-semibold" :class="view === 'order' ? 'border-brand text-brand border-b-2' : 'text-muted'" @click="view = 'order'">Pedido ({{ cart.length }})</button>
        </div>

        <div class="flex min-h-0 flex-1">
            <section class="flex min-w-0 flex-1 flex-col" :class="{ 'max-lg:hidden': view === 'order' }" aria-label="Cardápio">
                <ProductGrid :products="products" :composition="composition" :categories="categories" @add="add" />
            </section>

            <aside class="border-line bg-surface w-full shrink-0 space-y-4 overflow-y-auto border-l p-4 lg:w-[28rem]" :class="{ 'max-lg:hidden': view === 'menu' }" aria-label="Pedido">
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <p class="text-sm font-semibold">Cliente <span class="text-danger" aria-hidden="true">*</span></p>
                        <button v-if="!customer" type="button" class="btn btn-ghost min-h-7 py-0.5" @click="startCreate">
                            <UserPlus :size="16" />
                            Novo
                        </button>
                    </div>
                    <CustomerPicker v-model="customer" />
                    <p v-if="form.errors.customer_id" class="text-danger mt-1 text-sm" role="alert">{{ form.errors.customer_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn" :class="type === 'delivery' ? 'btn-primary' : 'btn-secondary'" @click="type = 'delivery'">Entrega</button>
                    <button type="button" class="btn" :class="type === 'pickup' ? 'btn-primary' : 'btn-secondary'" @click="type = 'pickup'">Retirada</button>
                </div>

                <div v-if="type === 'delivery'" class="space-y-3">
                    <div v-if="customer?.addresses?.length > 1" class="flex flex-wrap gap-2">
                        <button v-for="saved in customer.addresses" :key="saved.id" type="button" class="btn btn-secondary" @click="useAddress(saved)">{{ saved.street }}, {{ saved.number }}</button>
                    </div>
                    <div class="grid grid-cols-[1fr_5.5rem] gap-3">
                        <Field v-slot="{ id }" label="Rua" required :error="form.errors['address.street']">
                            <input :id="id" v-model="address.street" class="input" />
                        </Field>
                        <Field v-slot="{ id }" label="Número" required :error="form.errors['address.number']">
                            <input :id="id" v-model="address.number" class="input" />
                        </Field>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <Field v-slot="{ id }" label="Bairro" required :error="form.errors['address.district']">
                            <input :id="id" v-model="address.district" class="input" list="delivery-zones" autocomplete="off" />
                            <datalist id="delivery-zones"><option v-for="zone in zones" :key="zone.id" :value="zone.name" /></datalist>
                        </Field>
                        <Field v-slot="{ id }" label="Taxa (R$)" :error="form.errors.delivery_fee">
                            <DecimalInput :id="id" v-model="fee" pad />
                        </Field>
                    </div>
                    <Field v-slot="{ id }" label="Complemento e referência">
                        <input :id="id" v-model="address.reference" class="input" maxlength="150" />
                    </Field>
                </div>

                <div>
                    <p class="mb-1.5 text-sm font-semibold">Itens</p>
                    <p v-if="form.errors.items" class="text-danger mb-1 text-sm" role="alert">{{ form.errors.items }}</p>
                    <p v-if="cart.length === 0" class="text-muted text-sm">Escolha os produtos no cardápio.</p>
                    <ul class="space-y-2">
                        <li v-for="(item, index) in cart" :key="item.uid">
                            <div class="flex items-center gap-2">
                                <button type="button" class="btn btn-secondary size-9 min-h-9 px-0" :aria-label="`Diminuir ${item.name}`" @click="change(item, -1)"><Minus :size="14" /></button>
                                <span class="w-6 text-center text-sm font-bold tabular-nums">{{ item.quantity }}</span>
                                <button type="button" class="btn btn-secondary size-9 min-h-9 px-0" :aria-label="`Aumentar ${item.name}`" @click="change(item, 1)"><Plus :size="14" /></button>
                                <span class="min-w-0 flex-1 text-sm font-semibold break-words">
                                    {{ item.name }}
                                    <span v-if="item.label" class="text-muted block text-xs font-normal">{{ item.label }}</span>
                                </span>
                                <span class="text-sm tabular-nums">{{ formatMoney(fromCents(lineCents(item.quantity, item.sale_price))) }}</span>
                                <button type="button" class="btn btn-ghost min-h-7 px-1 py-0.5" :aria-label="`Remover ${item.name}`" @click="cart = cart.filter((line) => line !== item)"><Trash2 :size="14" /></button>
                            </div>
                            <input v-model="item.notes" class="input mt-1 min-h-9 py-1" maxlength="120" placeholder="Observação do item" :aria-label="`Observação de ${item.name}`" />
                            <p v-if="lineError(form.errors, index)" class="text-danger mt-1 text-xs" role="alert">{{ lineError(form.errors, index) }}</p>
                        </li>
                    </ul>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <Field v-slot="{ id }" label="Pagamento" required :error="form.errors.payment_method">
                        <select :id="id" v-model="paymentMethod" class="input">
                            <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </Field>
                    <Field v-if="paymentMethod === 'cash'" v-slot="{ id }" label="Troco para (R$)" :error="form.errors.change_for">
                        <DecimalInput :id="id" v-model="changeFor" pad />
                    </Field>
                </div>

                <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                    <input :id="id" v-model="notes" class="input" maxlength="255" />
                </Field>

                <dl class="border-line space-y-1 border-t pt-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted">Produtos</dt>
                        <dd class="tabular-nums">{{ formatMoney(fromCents(subtotalCents)) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-muted">Taxa de entrega</dt>
                        <dd class="tabular-nums">{{ formatMoney(fromCents(feeCents)) }}</dd>
                    </div>
                    <div class="flex justify-between text-lg font-bold">
                        <dt>Total</dt>
                        <dd class="tabular-nums">{{ formatMoney(fromCents(totalCents)) }}</dd>
                    </div>
                </dl>

                <button type="button" class="btn btn-primary min-h-12 w-full text-base" :disabled="form.processing || cart.length === 0 || !customer" @click="submit">Registrar pedido</button>
            </aside>
        </div>

        <ItemComposer v-if="composing" :product="composing.product" :composition="composition[composing.product.id]" @add="addComposed" @cancel="composing = null" />

        <ConfirmDialog
            :open="creating"
            title="Novo cliente"
            confirm-label="Salvar cliente"
            :disabled="newCustomer.name.trim() === ''"
            :processing="newCustomer.processing"
            @confirm="createCustomer"
            @cancel="creating = false"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Nome" required :error="newCustomer.errors.name">
                    <input :id="id" v-model="newCustomer.name" class="input" maxlength="120" />
                </Field>
                <Field v-slot="{ id }" label="Telefone" :error="newCustomer.errors.phone">
                    <input :id="id" class="input" inputmode="tel" :value="newCustomer.phone" @input="newCustomer.phone = $event.target.value = formatPhone($event.target.value)" />
                </Field>
            </div>
        </ConfirmDialog>
    </div>
</template>
