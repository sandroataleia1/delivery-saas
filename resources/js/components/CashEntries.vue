<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    movements: { type: Array, required: true },
    sales: { type: Array, default: () => [] },
});

const { can } = usePermissions();

/** O que está sendo desfeito: { kind: 'movement' | 'sale', item }. */
const undoing = ref(null);
const undoForm = useForm({ reason: '' });

const undoTitle = computed(() => {
    if (!undoing.value) {
        return '';
    }
    const { kind, item } = undoing.value;
    return kind === 'sale' ? `Cancelar a venda #${item.number}?` : `Estornar ${item.label.toLowerCase()} de ${formatMoney(item.amount)}?`;
});

const undoMessage = computed(() => {
    if (!undoing.value) {
        return '';
    }
    if (undoing.value.kind === 'sale') {
        return 'O estoque volta e o valor sai do caixa. Não dá para desfazer.';
    }
    return undoing.value.item.is_inflow ? 'O valor sai da gaveta com um lançamento de estorno.' : 'O valor volta para a gaveta com um lançamento de estorno.';
});

function startUndo(kind, item) {
    undoForm.reason = '';
    undoForm.clearErrors();
    undoing.value = { kind, item };
}

function confirmUndo() {
    const { kind, item } = undoing.value;
    const url = kind === 'sale' ? route('sales.cancel', item.id) : route('cash.movement.reverse', item.id);
    undoForm.post(url, { preserveScroll: true, onSuccess: () => (undoing.value = null) });
}

const undoError = computed(() => undoForm.errors.reason ?? undoForm.errors.movement ?? undoForm.errors.amount ?? undoForm.errors.sale);
</script>

<template>
    <div class="space-y-6">
        <section aria-labelledby="cash-movements-title">
            <h3 id="cash-movements-title" class="mb-2 font-bold">Sangrias e suprimentos</h3>
            <EmptyState v-if="movements.length === 0" title="Nenhum lançamento ainda" text="Sangrias e suprimentos aparecem aqui." />
            <ul v-else class="divide-line border-line divide-y rounded-lg border">
                <li v-for="movement in movements" :key="movement.id" class="flex items-center justify-between gap-3 px-3 py-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{ movement.label }}
                            <span v-if="movement.reversed" class="badge bg-canvas text-muted ml-1">Estornado</span>
                        </p>
                        <p class="text-muted truncate text-xs">{{ movement.date }} · {{ movement.reason }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-sm font-semibold whitespace-nowrap tabular-nums" :class="[movement.is_inflow ? 'text-ok' : 'text-danger', { 'line-through opacity-60': movement.reversed }]">
                            {{ movement.is_inflow ? '+' : '−' }} {{ formatMoney(movement.amount) }}
                        </span>
                        <button v-if="movement.reversible" type="button" class="btn btn-ghost text-danger px-2 text-sm" @click="startUndo('movement', movement)">Estornar</button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="sales.length" aria-labelledby="cash-sales-title">
            <h3 id="cash-sales-title" class="mb-2 font-bold">Vendas deste caixa</h3>
            <ul class="divide-line border-line divide-y rounded-lg border">
                <li v-for="sale in sales" :key="sale.id" class="flex items-center justify-between gap-3 px-3 py-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            <Link v-if="can('sales.view')" :href="route('sales.show', sale.id)" class="hover:underline">Venda #{{ sale.number }}</Link>
                            <template v-else>Venda #{{ sale.number }}</template>
                            <span v-if="sale.cancelled" class="badge bg-danger-soft text-danger ml-1">Cancelada</span>
                        </p>
                        <p class="text-muted truncate text-xs">{{ sale.date }} · {{ sale.payment }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-sm font-semibold whitespace-nowrap tabular-nums" :class="{ 'line-through opacity-60': sale.cancelled }">{{ formatMoney(sale.total) }}</span>
                        <button v-if="can('sales.cancel') && !sale.cancelled" type="button" class="btn btn-ghost text-danger px-2 text-sm" @click="startUndo('sale', sale)">Cancelar</button>
                    </div>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            :open="undoing !== null"
            :title="undoTitle"
            :message="undoMessage"
            :confirm-label="undoing?.kind === 'sale' ? 'Cancelar venda' : 'Estornar'"
            cancel-label="Voltar"
            danger
            :processing="undoForm.processing"
            :disabled="!undoForm.reason.trim()"
            @confirm="confirmUndo"
            @cancel="undoing = null"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Motivo" required :error="undoError" hint="Fica registrado na auditoria.">
                    <input :id="id" v-model="undoForm.reason" class="input" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>
    </div>
</template>
