<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    entry: { type: Object, required: true },
});

const { can } = usePermissions();
const confirming = ref(false);
const form = useForm({ reason: '', stock: null });

function cancelEntry() {
    form.post(route('entries.cancel', props.entry.id), {
        preserveScroll: true,
        onSuccess: () => (confirming.value = false),
    });
}
</script>

<template>
    <AppLayout :title="`Entrada #${entry.id}`">
        <PageHeader :title="`Entrada #${entry.id}`" :subtitle="`Lançada por ${entry.user} em ${entry.created_at}`">
            <button
                v-if="!entry.cancelled && can('entries.manage')"
                type="button"
                class="btn btn-secondary"
                @click="confirming = true"
            >
                <Ban :size="16" />
                Cancelar entrada
            </button>
        </PageHeader>

        <div v-if="entry.cancelled" class="bg-danger-soft text-danger mb-4 max-w-4xl rounded-lg px-4 py-3 text-sm">
            <p class="font-semibold">Cancelada por {{ entry.cancelled_by }} em {{ entry.cancelled_at }}</p>
            <p>Motivo: {{ entry.cancel_reason }}</p>
        </div>

        <dl class="card mb-4 grid max-w-4xl gap-4 p-5 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted">Fornecedor</dt>
                <dd class="font-semibold">{{ entry.supplier ?? 'Sem fornecedor' }}</dd>
            </div>
            <div>
                <dt class="text-muted">Número da nota</dt>
                <dd class="font-semibold break-all">{{ entry.invoice_number ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-muted">Data da entrada</dt>
                <dd class="font-semibold tabular-nums">{{ entry.date }}</dd>
            </div>
            <div v-if="entry.notes" class="sm:col-span-3">
                <dt class="text-muted">Observações</dt>
                <dd>{{ entry.notes }}</dd>
            </div>
        </dl>

        <div class="table-wrap max-w-4xl">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="text-right">Quantidade</th>
                        <th class="text-right max-sm:hidden">Custo unit.</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in entry.items" :key="item.id">
                        <td class="font-semibold">{{ item.product }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatQuantity(item.quantity, item.unit) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums max-sm:hidden">{{ formatMoney(item.unit_cost) }}</td>
                        <td class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(item.total) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="font-bold">Total</td>
                        <td class="max-sm:hidden" />
                        <td />
                        <td class="text-right font-bold whitespace-nowrap tabular-nums">{{ formatMoney(entry.total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <ConfirmDialog
            :open="confirming"
            title="Cancelar esta entrada?"
            message="As quantidades saem do estoque e o custo médio é recalculado."
            confirm-label="Cancelar entrada"
            danger
            :processing="form.processing"
            @confirm="cancelEntry"
            @cancel="confirming = false"
        >
            <div class="mt-4 space-y-3">
                <Field v-slot="{ id }" label="Motivo" required :error="form.errors.reason">
                    <input :id="id" v-model="form.reason" class="input" maxlength="255" />
                </Field>
                <p v-if="form.errors.stock" class="text-danger text-sm" role="alert">{{ form.errors.stock }}</p>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
