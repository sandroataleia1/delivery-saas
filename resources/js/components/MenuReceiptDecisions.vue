<script setup>
import { useForm } from '@inertiajs/vue3';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';
const props = defineProps({ receipts: { type: Array, required: true }, lateMinutes: { type: Number, required: true } });
const { can } = usePermissions();
const form = useForm({ reason: '' });
const late = (receipt) => Date.now() - Date.parse(receipt.created_at) >= props.lateMinutes * 60000;
function decide(receipt, reject = false) {
    form.clearErrors();
    if (reject) { const reason = window.prompt('Motivo da rejeição (obrigatório):'); if (!reason?.trim()) return; form.reason = reason.trim(); }
    form.post(route(reject ? 'receipts.reject' : 'receipts.confirm', receipt.id), { preserveScroll: true });
}
</script>
<template>
    <section v-if="receipts.length" class="max-h-80 space-y-3 overflow-y-auto pb-3" aria-label="Pedidos recebidos pelo QR">
        <p v-for="(error, key) in form.errors" :key="key" class="text-danger text-sm" role="alert">{{ error }}</p>
        <article v-for="receipt in receipts" :key="receipt.id" class="border-line rounded-lg border p-3 text-sm">
            <p class="font-bold">QR #{{ receipt.id }} · {{ { pending: 'Aguardando confirmação', confirmed: 'Confirmado', rejected: 'Rejeitado' }[receipt.status] }}</p>
            <p v-if="receipt.status === 'pending' && late(receipt)" class="text-danger font-bold">Pedido em atraso</p>
            <ul><li v-for="item in receipt.items" :key="item.id">{{ item.quantity }} × {{ item.product_name }} <span v-if="item.options_label" class="text-muted block text-sm">{{ item.options_label }}</span><span v-if="item.notes">· {{ item.notes }}</span></li></ul>
            <p class="font-semibold">{{ formatMoney(receipt.total) }}</p>
            <p v-if="receipt.decision_reason" class="text-muted">{{ receipt.decision_reason }}</p>
            <div v-if="receipt.status === 'pending' && can('tables.confirm')" class="mt-2 flex gap-2">
                <button type="button" class="btn btn-primary" :disabled="form.processing" @click="decide(receipt)">Confirmar</button>
                <button type="button" class="btn btn-secondary" :disabled="form.processing" @click="decide(receipt, true)">Rejeitar</button>
            </div>
            <p v-if="receipt.status === 'confirmed'" class="text-muted">Encaminhado ao painel de produção. O preparo é atualizado por item.</p>
        </article>
    </section>
</template>
