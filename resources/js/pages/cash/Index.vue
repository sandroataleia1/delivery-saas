<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowDownToLine, ArrowUpFromLine, History, Lock, ShoppingCart } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    session: { type: Object, default: null },
});

const { can } = usePermissions();

const openForm = useForm({ opening_amount: '' });

const movementType = ref(null);
const movementForm = useForm({ type: '', amount: '', reason: '' });
const movementTitle = computed(() => (movementType.value === 'withdrawal' ? 'Sangria' : 'Suprimento'));

function startMovement(type) {
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    Object.assign(movementForm, { type, amount: '', reason: '' });
    movementForm.clearErrors();
    movementType.value = type;
}

function saveMovement() {
    movementForm.post(route('cash.movement'), {
        preserveScroll: true,
        onSuccess: () => (movementType.value = null),
    });
}

const closing = ref(false);
const closeForm = useForm({ counted_cash: '', closing_notes: '' });

function closeCash() {
    closeForm.post(route('cash.close', props.session.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Caixa">
        <PageHeader title="Caixa" :subtitle="session ? `Caixa #${session.number} aberto em ${session.opened_at}` : 'Abra o caixa para começar a vender.'">
            <Link :href="route('cash.sessions')" class="btn btn-secondary">
                <History :size="16" />
                Histórico
            </Link>
        </PageHeader>

        <form v-if="!session" class="card max-w-sm space-y-4 p-5" @submit.prevent="openForm.post(route('cash.open'))">
            <Field v-slot="{ id }" label="Dinheiro na gaveta (R$)" required :error="openForm.errors.opening_amount" hint="Conte o troco inicial. Informe 0,00 se a gaveta estiver vazia.">
                <DecimalInput :id="id" v-model="openForm.opening_amount" pad required autofocus />
            </Field>
            <button type="submit" class="btn btn-primary w-full" :disabled="openForm.processing">Abrir caixa</button>
        </form>

        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
                <div class="card p-4">
                    <p class="text-muted text-sm">Valor inicial</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(session.opening_amount) }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-muted text-sm">Vendas neste caixa</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ session.sales_count }}</p>
                </div>
                <div v-if="session.expected_cash !== null" class="card col-span-2 p-4 lg:col-span-1">
                    <p class="text-muted text-sm">Dinheiro esperado na gaveta</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(session.expected_cash) }}</p>
                </div>
            </div>

            <div class="mb-6 flex flex-wrap gap-2">
                <Link v-if="can('sales.create')" :href="route('pos')" class="btn btn-primary">
                    <ShoppingCart :size="16" />
                    Ir para o PDV
                </Link>
                <button type="button" class="btn btn-secondary" @click="startMovement('withdrawal')">
                    <ArrowUpFromLine :size="16" />
                    Sangria
                </button>
                <button type="button" class="btn btn-secondary" @click="startMovement('deposit')">
                    <ArrowDownToLine :size="16" />
                    Suprimento
                </button>
                <button type="button" class="btn btn-secondary" @click="closing = true">
                    <Lock :size="16" />
                    Fechar caixa
                </button>
            </div>

            <h2 class="mb-3 text-lg font-bold">Lançamentos deste caixa</h2>

            <EmptyState v-if="session.movements.length === 0" title="Nenhum lançamento ainda" text="Sangrias e suprimentos aparecem aqui." />

            <div v-else class="table-wrap max-w-3xl">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Lançamento</th>
                            <th class="text-right">Valor</th>
                            <th class="max-sm:hidden">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="movement in session.movements" :key="movement.id">
                            <td>
                                <p class="font-semibold">{{ movement.label }}</p>
                                <p class="text-muted text-xs">{{ movement.reason }}</p>
                            </td>
                            <td class="text-right font-semibold whitespace-nowrap tabular-nums" :class="movement.type === 'deposit' ? 'text-ok' : 'text-danger'">
                                {{ movement.type === 'deposit' ? '+' : '−' }} {{ formatMoney(movement.amount) }}
                            </td>
                            <td class="text-muted whitespace-nowrap tabular-nums max-sm:hidden">{{ movement.date }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ConfirmDialog
                :open="movementType !== null"
                :title="movementTitle"
                :message="movementType === 'withdrawal' ? 'Dinheiro que sai da gaveta.' : 'Dinheiro que entra na gaveta.'"
                :confirm-label="`Registrar ${movementTitle.toLowerCase()}`"
                :processing="movementForm.processing"
                @confirm="saveMovement"
                @cancel="movementType = null"
            >
                <div class="mt-4 space-y-4">
                    <Field v-slot="{ id }" label="Valor (R$)" required :error="movementForm.errors.amount">
                        <DecimalInput :id="id" v-model="movementForm.amount" pad />
                    </Field>
                    <Field v-slot="{ id }" label="Motivo" required :error="movementForm.errors.reason" hint="Fica registrado na auditoria.">
                        <input :id="id" v-model="movementForm.reason" class="input" maxlength="255" />
                    </Field>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                :open="closing"
                title="Fechar caixa"
                message="Conte o dinheiro da gaveta e informe o valor. Pix e cartões entram automaticamente."
                confirm-label="Fechar caixa"
                :processing="closeForm.processing"
                @confirm="closeCash"
                @cancel="closing = false"
            >
                <div class="mt-4 space-y-4">
                    <Field v-slot="{ id }" label="Dinheiro contado (R$)" required :error="closeForm.errors.counted_cash">
                        <DecimalInput :id="id" v-model="closeForm.counted_cash" pad />
                    </Field>
                    <Field v-slot="{ id }" label="Observação" :error="closeForm.errors.closing_notes" hint="Obrigatória se o valor não conferir.">
                        <input :id="id" v-model="closeForm.closing_notes" class="input" maxlength="255" />
                    </Field>
                </div>
            </ConfirmDialog>
        </template>
    </AppLayout>
</template>
