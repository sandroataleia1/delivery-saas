<script setup>
import { computed, watch } from 'vue';
import { Banknote, CreditCard, QrCode, Ticket, Trash2 } from '@lucide/vue';
import DecimalInput from '@/components/DecimalInput.vue';
import { formatMoney } from '@/lib/format';
import { fromCents, toCents } from '@/lib/money';

const props = defineProps({
    methods: { type: Object, required: true },
    totalCents: { type: Number, required: true },
});

const payments = defineModel('payments', { type: Array, required: true });
const valid = defineModel('valid', { type: Boolean, default: false });

const icons = { cash: Banknote, pix: QrCode, credit: CreditCard, debit: CreditCard, voucher: Ticket };

const paidCents = computed(() => payments.value.reduce((sum, payment) => sum + toCents(payment.amount), 0));
const cashCents = computed(() => payments.value.filter((payment) => payment.method === 'cash').reduce((sum, payment) => sum + toCents(payment.amount), 0));
const remainingCents = computed(() => Math.max(props.totalCents - paidCents.value, 0));
const changeCents = computed(() => Math.max(paidCents.value - props.totalCents, 0));
const overpaidWithoutCash = computed(() => paidCents.value - cashCents.value > props.totalCents);

watch(
    [remainingCents, overpaidWithoutCash],
    () => (valid.value = remainingCents.value === 0 && !overpaidWithoutCash.value),
    { immediate: true },
);

function add(method) {
    const existing = payments.value.find((payment) => payment.method === method);

    if (existing) {
        existing.amount = fromCents(toCents(existing.amount) + remainingCents.value);
    } else {
        payments.value.push({ method, amount: fromCents(remainingCents.value) });
    }
}
</script>

<template>
    <div>
        <div class="mt-4 grid grid-cols-2 gap-2">
            <button v-for="(label, method) in methods" :key="method" type="button" class="btn btn-secondary justify-start" @click="add(method)">
                <component :is="icons[method]" :size="16" />
                {{ label }}
            </button>
        </div>

        <ul v-if="payments.length" class="mt-4 space-y-2">
            <li v-for="(payment, index) in payments" :key="payment.method" class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-semibold">{{ methods[payment.method] }}</span>
                <DecimalInput v-model="payment.amount" class="w-28 text-right" pad :aria-label="`Valor em ${methods[payment.method]}`" />
                <button type="button" class="btn btn-ghost" :aria-label="`Remover ${methods[payment.method]}`" @click="payments.splice(index, 1)">
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
    </div>
</template>
