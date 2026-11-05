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
const form = useForm({ reason: '' });

function start(name) {
    form.reason = '';
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
                <button v-if="isOpen" type="button" class="btn btn-primary" @click="settling = bill">
                    <Check :size="16" />
                    Dar baixa
                </button>
                <Link v-if="isOpen" :href="route('bills.edit', bill.id)" class="btn btn-secondary">
                    <Pencil :size="16" />
                    Editar
                </Link>
                <button v-if="isOpen" type="button" class="btn btn-secondary" @click="start('cancel')">
                    <Ban :size="16" />
                    Cancelar conta
                </button>
                <button v-if="bill.status === 'paid'" type="button" class="btn btn-secondary" @click="start('reopen')">
                    <Undo2 :size="16" />
                    Estornar baixa
                </button>
            </template>
        </PageHeader>

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
                <dt class="text-muted">Valor</dt>
                <dd class="font-semibold tabular-nums">{{ formatMoney(bill.amount) }}</dd>
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

        <dl v-if="bill.status === 'paid'" class="card mt-4 grid max-w-3xl gap-4 p-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted">{{ bill.type === 'pagar' ? 'Pago em' : 'Recebido em' }}</dt>
                <dd class="font-semibold tabular-nums">{{ bill.paid_at }}</dd>
            </div>
            <div>
                <dt class="text-muted">Valor da baixa</dt>
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

        <SettleDialog :bill="settling" :methods="methods" :today="today" @close="settling = null" />

        <ConfirmDialog
            :open="action !== null"
            :title="action === 'cancel' ? 'Cancelar esta conta?' : 'Estornar esta baixa?'"
            :message="action === 'reopen' ? 'A conta volta a ficar em aberto. Se a baixa usou dinheiro do caixa, o valor volta pelo seu caixa aberto.' : null"
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
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
