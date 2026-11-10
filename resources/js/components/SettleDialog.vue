<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    bill: { type: Object, default: null },
    methods: { type: Object, required: true },
    today: { type: String, required: true },
});

const emit = defineEmits(['close']);

const { can } = usePermissions();
const form = useForm({ paid_at: '', paid_amount: '', payment_method: 'pix', notes: '', bill: null, discount_amount: '0', discount_reason: '' });
const isPayable = computed(() => props.bill?.type === 'pagar');

watch(
    () => props.bill,
    (bill) => {
        if (bill) {
            Object.assign(form, { paid_at: props.today, paid_amount: bill.balance ?? bill.amount, payment_method: 'pix', notes: '', discount_amount: '0', discount_reason: '' });
            form.clearErrors();
        }
    },
);

function submit() {
    form.post(route('bills.settle', props.bill.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <ConfirmDialog
        :open="bill !== null"
        :title="isPayable ? 'Registrar pagamento' : 'Registrar recebimento'"
        :message="bill?.description"
        confirm-label="Confirmar baixa"
        :processing="form.processing"
        @confirm="submit"
        @cancel="emit('close')"
    >
        <div class="mt-4 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <Field v-slot="{ id }" label="Data" required :error="form.errors.paid_at">
                    <input :id="id" v-model="form.paid_at" class="input" type="date" :max="today" />
                </Field>
                <Field v-slot="{ id }" label="Valor (R$)" required :error="form.errors.paid_amount">
                    <DecimalInput :id="id" v-model="form.paid_amount" pad />
                </Field>
            </div>

            <Field
                v-slot="{ id }"
                label="Forma"
                required
                :error="form.errors.payment_method"
                :hint="form.payment_method === 'cash' ? (isPayable ? 'Sai do seu caixa aberto.' : 'Entra no seu caixa aberto.') : null"
            >
                <select :id="id" v-model="form.payment_method" class="input">
                    <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                </select>
            </Field>

            <Field v-slot="{ id }" label="Observação" :error="form.errors.notes">
                <input :id="id" v-model="form.notes" class="input" maxlength="255" />
            </Field>

            <template v-if="can('finance.discount')">
                <Field v-slot="{ id }" label="Desconto para quitação (R$)" :error="form.errors.discount_amount" hint="Opcional. Valor recebido e desconto devem quitar exatamente o saldo.">
                    <DecimalInput :id="id" v-model="form.discount_amount" pad />
                </Field>
                <Field v-slot="{ id }" label="Motivo do desconto" :error="form.errors.discount_reason">
                    <input :id="id" v-model="form.discount_reason" class="input" maxlength="255" />
                </Field>
            </template>

            <p v-if="form.errors.bill" class="text-danger text-sm" role="alert">{{ form.errors.bill }}</p>
        </div>
    </ConfirmDialog>
</template>
