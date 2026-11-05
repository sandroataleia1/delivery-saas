<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    session: { type: Object, required: true },
    canClose: { type: Boolean, required: true },
});

const closing = ref(false);
const form = useForm({ counted_cash: '', closing_notes: '' });

function close() {
    form.post(route('cash.close', props.session.id), {
        preserveScroll: true,
        onSuccess: () => (closing.value = false),
    });
}
</script>

<template>
    <AppLayout :title="`Caixa #${session.number}`">
        <PageHeader :title="`Caixa #${session.number}`" :subtitle="`${session.user}, aberto em ${session.opened_at}`">
            <button v-if="canClose" type="button" class="btn btn-secondary" @click="closing = true">
                <Lock :size="16" />
                Fechar este caixa
            </button>
        </PageHeader>

        <p v-if="session.is_open" class="bg-ok-soft text-ok mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm font-semibold">Caixa aberto</p>
        <p v-else class="text-muted mb-4 max-w-3xl text-sm">Fechado por {{ session.closed_by }} em {{ session.closed_at }}</p>

        <div
            v-if="!session.is_open"
            class="mb-4 max-w-3xl rounded-xl px-5 py-4"
            :class="Number(session.difference) === 0 ? 'bg-ok-soft text-ok' : 'bg-danger-soft text-danger'"
        >
            <p class="text-sm font-semibold">
                {{ Number(session.difference) === 0 ? 'Valor conferido' : Number(session.difference) < 0 ? 'Falta no caixa' : 'Sobra no caixa' }}
            </p>
            <p class="text-3xl font-bold tabular-nums">{{ formatMoney(session.difference) }}</p>
            <p v-if="session.closing_notes" class="mt-1 text-sm">{{ session.closing_notes }}</p>
        </div>

        <div class="grid max-w-3xl gap-4 sm:grid-cols-2">
            <dl class="card space-y-1.5 p-5 text-sm">
                <h2 class="mb-2 font-bold">Dinheiro</h2>
                <div class="flex justify-between">
                    <dt class="text-muted">Valor inicial</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.opening_amount) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted">Suprimentos</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.deposits) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted">Sangrias</dt>
                    <dd class="tabular-nums">− {{ formatMoney(session.withdrawals) }}</dd>
                </div>
                <div v-if="Number(session.bill_receipts) > 0" class="flex justify-between">
                    <dt class="text-muted">Contas recebidas</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.bill_receipts) }}</dd>
                </div>
                <div v-if="Number(session.bill_payments) > 0" class="flex justify-between">
                    <dt class="text-muted">Contas pagas</dt>
                    <dd class="tabular-nums">− {{ formatMoney(session.bill_payments) }}</dd>
                </div>
                <div v-if="Number(session.refunds) > 0" class="flex justify-between">
                    <dt class="text-muted">Estornos</dt>
                    <dd class="tabular-nums">− {{ formatMoney(session.refunds) }}</dd>
                </div>
                <div v-if="session.expected_cash !== null" class="border-line flex justify-between border-t pt-1.5 font-semibold">
                    <dt>Esperado na gaveta</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.expected_cash) }}</dd>
                </div>
                <div v-if="!session.is_open" class="flex justify-between font-semibold">
                    <dt>Contado</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.counted_cash) }}</dd>
                </div>
            </dl>

            <dl class="card space-y-1.5 p-5 text-sm">
                <h2 class="mb-2 font-bold">Vendas ({{ session.sales_count }})</h2>
                <div v-for="payment in session.payments" :key="payment.label" class="flex justify-between">
                    <dt class="text-muted">{{ payment.label }}</dt>
                    <dd class="tabular-nums">{{ formatMoney(payment.total) }}</dd>
                </div>
                <div v-if="session.sales_total !== null" class="border-line flex justify-between border-t pt-1.5 font-semibold">
                    <dt>Total</dt>
                    <dd class="tabular-nums">{{ formatMoney(session.sales_total) }}</dd>
                </div>
                <p v-else class="text-muted">Os totais aparecem depois do fechamento.</p>
            </dl>
        </div>

        <div v-if="session.movements.length" class="table-wrap mt-4 max-w-3xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Lançamento</th>
                        <th class="max-sm:hidden">Usuário</th>
                        <th class="text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="movement in session.movements" :key="movement.id">
                        <td>
                            <p class="font-semibold">{{ movement.label }}</p>
                            <p class="text-muted text-xs">{{ movement.reason }} · {{ movement.date }}</p>
                        </td>
                        <td class="whitespace-nowrap max-sm:hidden">{{ movement.user }}</td>
                        <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="movement.is_inflow ? 'text-ok' : 'text-danger'">
                            {{ movement.is_inflow ? '+' : '−' }} {{ formatMoney(movement.amount) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="closing"
            :title="`Fechar o caixa #${session.number}`"
            :message="`Caixa de ${session.user}.`"
            confirm-label="Fechar caixa"
            :processing="form.processing"
            @confirm="close"
            @cancel="closing = false"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Dinheiro contado (R$)" required :error="form.errors.counted_cash">
                    <DecimalInput :id="id" v-model="form.counted_cash" pad />
                </Field>
                <Field v-slot="{ id }" label="Observação" :error="form.errors.closing_notes" hint="Obrigatória se o valor não conferir.">
                    <input :id="id" v-model="form.closing_notes" class="input" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
