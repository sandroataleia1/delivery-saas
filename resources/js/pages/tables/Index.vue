<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { Clock, Settings, Users } from '@lucide/vue';
import MenuReceiptAlerts from '@/components/MenuReceiptAlerts.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import SaleCompleteDialog from '@/components/SaleCompleteDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    tables: { type: Array, required: true },
    operation: { type: Object, required: true },
    completed: { type: Object, default: null },
});

const { can } = usePermissions();

const lastUpdated = ref(Date.now());
usePoll(10000, { only: ['tables', 'operation'], onSuccess: () => lastUpdated.value = Date.now() }, { keepAlive: true });
const pendingIds = computed(() => props.tables.flatMap(table => table.order?.pending_ids ?? []));
const late = (table) => table.order?.oldest_pending_at && Date.now() - Date.parse(table.order.oldest_pending_at) >= props.operation.late_after_minutes * 60000;

const receipt = ref(props.completed);
const target = ref(null);
const area = ref(null);
const form = useForm({ people: 2, table: null });

watch(() => props.completed, (sale) => {
    if (sale) {
        receipt.value = sale;
    }
});

const areas = computed(() => [...new Set(props.tables.map((table) => table.area).filter(Boolean))]);
const visible = computed(() => props.tables.filter((table) => area.value === null || table.area === area.value));
const occupied = computed(() => props.tables.filter((table) => table.order).length);

const elapsed = (minutes) => (minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, '0')}`);

function select(table) {
    if (table.order) {
        router.visit(route('orders.show', table.order.id));

        return;
    }

    form.people = 2;
    form.clearErrors();
    target.value = table;
}

function open() {
    form.post(route('orders.open', target.value.id));
}
</script>

<template>
    <AppLayout title="Mesas">
        <PageHeader title="Mesas" :subtitle="`${occupied} de ${tables.length} ocupadas`">
            <Link v-if="can('tables.manage')" :href="route('tables.manage')" class="btn btn-secondary">
                <Settings :size="16" />
                Gerenciar mesas
            </Link>
        </PageHeader>

        <MenuReceiptAlerts :ids="pendingIds" :operation="operation" :last-updated="lastUpdated" />

        <div v-if="areas.length" class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button type="button" class="btn shrink-0" :class="area === null ? 'btn-primary' : 'btn-secondary'" @click="area = null">Todas</button>
            <button
                v-for="item in areas"
                :key="item"
                type="button"
                class="btn shrink-0"
                :class="area === item ? 'btn-primary' : 'btn-secondary'"
                @click="area = item"
            >
                {{ item }}
            </button>
        </div>

        <EmptyState v-if="tables.length === 0" title="Nenhuma mesa cadastrada" text="Cadastre as mesas em Gerenciar mesas." />

        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <li v-for="table in visible" :key="table.id">
                <button
                    type="button"
                    class="flex min-h-36 w-full cursor-pointer flex-col justify-between rounded-xl border p-3 text-left transition-colors"
                    :class="table.order ? 'border-brand/40 bg-brand-soft hover:border-brand' : 'border-line bg-surface hover:border-ok'"
                    :aria-label="`Mesa ${table.number}, ${table.order ? 'ocupada' : 'livre'}`"
                    @click="select(table)"
                >
                    <span class="flex items-start justify-between gap-2">
                        <span class="text-2xl font-bold tabular-nums">{{ table.number }}</span>
                        <span class="badge" :class="table.order ? 'bg-brand text-white' : 'bg-ok-soft text-ok'">{{ table.order ? 'Ocupada' : 'Livre' }}</span>
                    </span>

                    <span v-if="table.order?.pending_count" class="text-warn text-xs font-bold">{{ table.order.pending_count }} QR pendente(s){{ late(table) ? ' · EM ATRASO' : '' }}</span>
                    <span v-if="table.order" class="text-sm">
                        <span class="block font-bold tabular-nums">{{ formatMoney(table.order.total) }}</span>
                        <span class="text-muted flex items-center gap-3 text-xs tabular-nums">
                            <span class="flex items-center gap-1"><Clock :size="12" />{{ elapsed(table.order.minutes) }}</span>
                            <span class="flex items-center gap-1"><Users :size="12" />{{ table.order.people }}</span>
                        </span>
                    </span>
                    <span v-else class="text-muted text-xs">{{ table.seats }} lugares{{ table.area ? ` · ${table.area}` : '' }}</span>
                </button>
            </li>
        </ul>

        <ConfirmDialog
            :open="target !== null"
            :title="`Abrir a mesa ${target?.number ?? ''}`"
            confirm-label="Abrir mesa"
            :processing="form.processing"
            @confirm="open"
            @cancel="target = null"
        >
            <div class="mt-4 space-y-3">
                <Field v-slot="{ id }" label="Pessoas" required :error="form.errors.people">
                    <input :id="id" v-model.number="form.people" class="input" type="number" min="1" max="100" inputmode="numeric" />
                </Field>
                <p v-if="form.errors.table" class="text-danger text-sm" role="alert">{{ form.errors.table }}</p>
            </div>
        </ConfirmDialog>

        <SaleCompleteDialog :sale="receipt" confirm-label="Voltar às mesas" @close="receipt = null" />
    </AppLayout>
</template>
