<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Ban, Check, Pencil, Undo2 } from '@lucide/vue';
import BillStatus from '@/components/BillStatus.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettleDialog from '@/components/SettleDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    bill: { type: Object, required: true },
    methods: { type: Object, required: true },
    today: { type: String, required: true },
});

const { can } = usePermissions();
const isOpen = computed(() => props.bill.status === 'open' || props.bill.status === 'overdue');

const settling = ref(null);
const action = ref(null);
const form = useForm({ reason: '', settlement_id: null });

function start(name, settlement = null) {
    form.reason = '';
    form.settlement_id = settlement?.id ?? null;
    form.clearErrors();
    action.value = name;
}

function confirm() {
    const target = action.value === 'cancel' ? 'bills.cancel' : 'bills.reopen';

    form.post(route(target, props.bill.id), {
        preserveScroll: true,
        onSuccess: () => (action.value = null),
    });
}
</script>

<template>
    <AppLayout :title="bill.description">
        <PageHeader :title="bill.description" :subtitle="bill.type === 'pagar' ? 'Conta a pagar' : 'Conta a receber'">
            <template v-if="can('finance.manage')">
                <button v-if="isOpen && !bill.legacy_review_required" type="button" class="btn btn-primary" @click="settling = bill">
                    <Check :size="16" />
                    Dar baixa
                </button>
                <Link v-if="bill.can_edit" :href="route('bills.edit', bill.id)" class="btn btn-secondary">
                    <Pencil :size="16" />
                    Editar
                </Link>
                <button v-if="bill.can_cancel" type="button" class="btn btn-secondary" @click="start('cancel')">
                    <Ban :size="16" />
                    Cancelar conta
                </button>
            </template>
        </PageHeader>

        <p v-if="bill.legacy_review_required" class="mb-4 text-danger" role="alert">Esta conta contém uma quitação antiga pendente de reconciliação. Os valores históricos foram preservados; alterações estão bloqueadas.</p>
        <p v-if="bill.linked" class="mb-4 text-muted">Edição e cancelamento devem ser realizados pela venda ou entrada de origem. Baixas precisam ser estornadas antes do cancelamento.</p>

        <div v-if="bill.status === 'cancelled'" class="bg-danger-soft text-danger mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm">
            <p class="font-semibold">Cancelada por {{ bill.cancelled_by }} em {{ bill.cancelled_at }}</p>
            <p>Motivo: {{ bill.cancel_reason }}</p>
        </div>

        <dl class="card grid max-w-3xl gap-4 p-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted">Situação</dt>
                <dd class="mt-0.5"><BillStatus :status="bill.status" :label="bill.status_label" /></dd>
            </div>
            <div>
                <dt class="text-muted">Valor original</dt>
                <dd class="font-semibold tabular-nums">{{ formatMoney(bill.amount) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Saldo</dt>
                <dd class="font-semibold tabular-nums">{{ formatMoney(bill.balance) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Total de descontos</dt>
                <dd class="font-semibold tabular-nums">{{ formatMoney(bill.discount_amount) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Vencimento</dt>
                <dd class="font-semibold tabular-nums">{{ bill.due_date }}</dd>
            </div>
            <div>
                <dt class="text-muted">Categoria</dt>
                <dd class="font-semibold">{{ bill.category }}</dd>
            </div>
            <div>
                <dt class="text-muted">{{ bill.type === 'pagar' ? 'Fornecedor' : 'Cliente' }}</dt>
                <dd class="font-semibold">{{ bill.party ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">Lançada por</dt>
                <dd class="font-semibold">{{ bill.user }}</dd>
            </div>
            <div v-if="bill.stock_entry_id">
                <dt class="text-muted">Origem</dt>
                <dd><Link :href="route('entries.show', bill.stock_entry_id)" class="text-brand font-semibold hover:underline">Entrada #{{ bill.stock_entry_id }}</Link></dd>
            </div>
            <div v-if="bill.notes" class="sm:col-span-3">
                <dt class="text-muted">Observações</dt>
                <dd>{{ bill.notes }}</dd>
            </div>
        </dl>

        <dl v-if="bill.paid_amount !== null" class="card mt-4 grid max-w-3xl gap-4 p-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted">{{ bill.type === 'pagar' ? 'Pago em' : 'Recebido em' }}</dt>
                <dd class="font-semibold tabular-nums">{{ bill.paid_at }}</dd>
            </div>
            <div>
                <dt class="text-muted">Total efetivamente {{ bill.type === 'pagar' ? 'pago' : 'recebido' }}</dt>
                <dd class="font-semibold tabular-nums">{{ formatMoney(bill.paid_amount) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Forma</dt>
                <dd class="font-semibold">{{ bill.payment_method }}</dd>
            </div>
            <div>
                <dt class="text-muted">Baixa feita por</dt>
                <dd class="font-semibold">{{ bill.settled_by }}</dd>
            </div>
        </dl>

        <section v-if="bill.settlements?.length" class="card mt-4 max-w-3xl p-5">
            <h2 class="mb-3 font-semibold">Histórico de baixas</h2>
            <ul class="space-y-4">
                <li v-for="entry in bill.settlements" :key="entry.id" class="border-line border-t pt-3 text-sm">
                    <p class="font-semibold">#{{ entry.id }} · {{ entry.paid_at }} · {{ formatMoney(entry.amount) }} · {{ entry.payment_method }}</p>
                    <p>Responsável: {{ entry.user }}<span v-if="entry.legacy_imported"> · Importada do histórico</span></p>
                    <p v-if="entry.notes">Observação: {{ entry.notes }}</p>
                    <p v-if="Number(entry.discount_amount) > 0">Desconto: {{ formatMoney(entry.discount_amount) }} · {{ entry.discount_reason }}</p>
                    <p v-if="entry.reversed_at">Estornada em {{ entry.reversed_at }} por {{ entry.reversed_by }} · {{ entry.reversal_reason }}</p>
                    <button v-else-if="can('finance.manage') && !bill.legacy_review_required" type="button" class="btn btn-secondary mt-2" @click="start('reopen', entry)">
                        <Undo2 :size="16" /> Estornar esta baixa
                    </button>
                </li>
            </ul>
        </section>

        <SettleDialog :bill="settling" :methods="methods" :today="today" @close="settling = null" />

        <ConfirmDialog
            :open="action !== null"
            :title="action === 'cancel' ? 'Cancelar esta conta?' : 'Estornar esta baixa?'"
            :message="action === 'reopen' ? 'Somente a baixa selecionada e seu desconto serão estornados. Dinheiro do caixa exige seu caixa aberto; as outras baixas serão preservadas.' : null"
            :confirm-label="action === 'cancel' ? 'Cancelar conta' : 'Estornar baixa'"
            danger
            :processing="form.processing"
            @confirm="confirm"
            @cancel="action = null"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Motivo" required :error="form.errors.reason">
                    <input :id="id" v-model="form.reason" class="input" maxlength="255" />
                </Field>
                <p v-if="form.errors.settlement_id" class="text-danger" role="alert">{{ form.errors.settlement_id }}</p>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
