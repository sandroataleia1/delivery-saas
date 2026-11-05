<script setup>
import { TriangleAlert } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { formatMoney } from '@/lib/format';

defineProps({
    sale: { type: Object, default: null },
    confirmLabel: { type: String, default: 'Nova venda' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <ConfirmDialog
        :open="sale !== null"
        :title="`Venda #${sale?.number ?? ''} concluída`"
        :confirm-label="confirmLabel"
        cancel-label="Fechar"
        @confirm="emit('close')"
        @cancel="emit('close')"
    >
        <div v-if="sale" class="mt-4 space-y-3">
            <p class="text-sm">Total: <strong class="tabular-nums">{{ formatMoney(sale.total) }}</strong></p>
            <div v-if="Number(sale.change) > 0" class="bg-ok-soft rounded-lg px-4 py-3">
                <p class="text-ok text-sm font-semibold">Troco</p>
                <p class="text-ok text-3xl font-bold tabular-nums">{{ formatMoney(sale.change) }}</p>
            </div>
            <div v-if="sale.negative.length" class="bg-warn-soft text-warn flex gap-2 rounded-lg px-3 py-2 text-sm">
                <TriangleAlert :size="16" class="mt-0.5 shrink-0" />
                <div>
                    <p class="font-semibold">Estoque ficou negativo:</p>
                    <p>{{ sale.negative.join(', ') }}</p>
                </div>
            </div>
            <a :href="route('sales.receipt', sale.id)" target="_blank" class="btn btn-secondary w-full">Imprimir cupom</a>
        </div>
    </ConfirmDialog>
</template>
