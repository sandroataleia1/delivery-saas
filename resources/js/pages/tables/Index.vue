<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePoll } from '@inertiajs/vue3';
import { BellRing, Clock, Receipt, Settings, Users } from '@lucide/vue';
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
const status = ref(null);
const form = useForm({ people: 2, table: null });

watch(() => props.completed, (sale) => {
    if (sale) {
        receipt.value = sale;
    }
});

/** Livre, ocupada ou em fechamento (pediu a conta): cada estado tem a sua cor no mapa. */
const STATUSES = {
    free: { label: 'Livre', card: 'border-ok/40 bg-surface hover:border-ok', bar: 'bg-ok', number: 'text-ok', badge: 'bg-ok-soft text-ok', dot: 'bg-ok' },
    busy: { label: 'Ocupada', card: 'border-brand/40 bg-brand-soft hover:border-brand', bar: 'bg-brand', number: 'text-brand', badge: 'bg-brand text-white', dot: 'bg-brand' },
    closing: { label: 'Em fechamento', card: 'border-warn/50 bg-warn-soft hover:border-warn', bar: 'bg-warn', number: 'text-warn', badge: 'bg-warn text-white', dot: 'bg-warn' },
};

const statusOf = (table) => (!table.order ? 'free' : table.order.closing ? 'closing' : 'busy');

const areas = computed(() => [...new Set(props.tables.map((table) => table.area).filter(Boolean))]);
const inArea = computed(() => props.tables.filter((table) => area.value === null || table.area === area.value));
const visible = computed(() => inArea.value.filter((table) => status.value === null || statusOf(table) === status.value));
const counts = computed(() => Object.fromEntries(Object.keys(STATUSES).map((key) => [key, inArea.value.filter((table) => statusOf(table) === key).length])));
const occupied = computed(() => props.tables.filter((table) => table.order).length);

function toggleStatus(key) {
    status.value = status.value === key ? null : key;
}

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

        <div v-if="tables.length" class="mb-4 grid grid-cols-3 gap-2 sm:max-w-xl" role="group" aria-label="Filtrar por situação">
            <button
                v-for="(info, key) in STATUSES"
                :key="key"
                type="button"
                class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-left transition-colors"
                :class="status === key ? 'border-ink bg-surface shadow-sm' : 'border-line bg-surface hover:border-muted'"
                :aria-pressed="status === key"
                @click="toggleStatus(key)"
            >
                <span class="size-3 shrink-0 rounded-full" :class="info.dot" aria-hidden="true" />
                <span class="min-w-0">
                    <span class="block text-xl leading-tight font-bold tabular-nums">{{ counts[key] }}</span>
                    <span class="text-muted block truncate text-xs">{{ info.label }}</span>
                </span>
            </button>
        </div>

        <EmptyState v-if="tables.length === 0" title="Nenhuma mesa cadastrada" text="Cadastre as mesas em Gerenciar mesas." />
        <p v-else-if="visible.length === 0" class="text-muted py-10 text-center text-sm">Nenhuma mesa nesta situação.</p>

        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <li v-for="table in visible" :key="table.id">
                <button
                    type="button"
                    class="relative flex min-h-40 w-full cursor-pointer flex-col overflow-hidden rounded-xl border-2 p-3 pl-4 text-left shadow-sm transition hover:shadow-md"
                    :class="[STATUSES[statusOf(table)].card, { 'ring-danger ring-2 ring-offset-2': late(table) }]"
                    :aria-label="`Mesa ${table.number}, ${STATUSES[statusOf(table)].label.toLowerCase()}`"
                    @click="select(table)"
                >
                    <span class="absolute inset-y-0 left-0 w-1.5" :class="STATUSES[statusOf(table)].bar" aria-hidden="true" />

                    <span class="flex items-start justify-between gap-2">
                        <span class="text-3xl leading-none font-extrabold tabular-nums" :class="STATUSES[statusOf(table)].number">{{ table.number }}</span>
                        <span class="badge" :class="STATUSES[statusOf(table)].badge">{{ STATUSES[statusOf(table)].label }}</span>
                    </span>

                    <span v-if="table.order?.pending_count" class="mt-2 flex items-center gap-1 text-xs font-bold" :class="late(table) ? 'text-danger' : 'text-warn'">
                        <BellRing :size="12" :class="{ 'animate-pulse': late(table) }" />
                        {{ table.order.pending_count }} pedido(s) no QR{{ late(table) ? ' · em atraso' : '' }}
                    </span>

                    <span v-if="table.order" class="mt-auto pt-3">
                        <span class="block text-lg font-bold tabular-nums">{{ formatMoney(table.order.total) }}</span>
                        <span v-if="table.order.closing" class="text-warn flex items-center gap-1 text-xs font-semibold">
                            <Receipt :size="12" />
                            Conta pedida há {{ elapsed(table.order.closing_minutes) }}
                        </span>
                        <span class="text-muted mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs tabular-nums">
                            <span class="flex items-center gap-1" title="Tempo de mesa"><Clock :size="12" />{{ elapsed(table.order.minutes) }}</span>
                            <span class="flex items-center gap-1" title="Pessoas"><Users :size="12" />{{ table.order.people }}</span>
                            <span v-if="table.order.waiter" class="truncate">{{ table.order.waiter }}</span>
                        </span>
                    </span>
                    <span v-else class="text-muted mt-auto pt-3 text-xs">
                        <span class="flex items-center gap-1"><Users :size="12" />{{ table.seats }} lugares</span>
                        <span v-if="table.area" class="block">{{ table.area }}</span>
                    </span>
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
