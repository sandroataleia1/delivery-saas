<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePoll } from '@inertiajs/vue3';
import { History, MapPin, Plus } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    orders: { type: Array, required: true },
    couriers: { type: Array, required: true },
});

const { can } = usePermissions();

usePoll(10000, { only: ['orders'] });

const columns = [
    { status: 'received', label: 'Recebidos' },
    { status: 'preparing', label: 'Em preparo' },
    { status: 'ready', label: 'Prontos' },
    { status: 'out_for_delivery', label: 'Em rota' },
    { status: 'delivered', label: 'A acertar' },
];

const grouped = computed(() => columns.map((column) => ({
    ...column,
    orders: props.orders.filter((order) => order.status === column.status),
})));

const form = useForm({ expected_status: '', courier_id: '' });
const target = ref(null);

function advance(order) {
    if (order.next_status === 'out_for_delivery') {
        form.courier_id = '';
        form.clearErrors();
        target.value = order;

        return;
    }

    form.expected_status = order.status;
    form.courier_id = '';
    form.post(route('deliveries.advance', order.id), { preserveScroll: true });
}

function dispatch() {
    form.expected_status = target.value.status;
    form.post(route('deliveries.advance', target.value.id), {
        preserveScroll: true,
        onSuccess: () => (target.value = null),
    });
}
</script>

<template>
    <AppLayout title="Entregas">
        <PageHeader title="Entregas" subtitle="Pedidos em andamento.">
            <Link :href="route('deliveries.history')" class="btn btn-secondary">
                <History :size="16" />
                Histórico
            </Link>
            <Link v-if="can('deliveries.manage')" :href="route('deliveries.zones')" class="btn btn-secondary">
                <MapPin :size="16" />
                Bairros
            </Link>
            <Link :href="route('deliveries.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo pedido
            </Link>
        </PageHeader>

        <p v-if="form.errors.order" class="text-danger mb-3 text-sm" role="alert">{{ form.errors.order }}</p>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <section v-for="column in grouped" :key="column.status">
                <h2 class="text-muted mb-2 flex items-center justify-between text-sm font-semibold">
                    {{ column.label }}
                    <span class="badge bg-canvas text-muted">{{ column.orders.length }}</span>
                </h2>
                <ul class="space-y-2">
                    <li v-for="order in column.orders" :key="order.id" class="card p-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <Link :href="route('deliveries.show', order.id)" class="text-brand font-bold hover:underline">#{{ order.number }}</Link>
                            <span class="text-muted text-xs tabular-nums">{{ order.minutes }} min</span>
                        </div>
                        <p class="mt-1 truncate font-semibold">{{ order.customer }}</p>
                        <p class="text-muted truncate text-xs">{{ order.district ?? 'Retirada no balcão' }}</p>
                        <p class="mt-1 font-bold tabular-nums">{{ formatMoney(order.total) }}</p>
                        <p class="text-muted text-xs">{{ order.payment }}<template v-if="order.courier"> · {{ order.courier }}</template></p>
                        <button v-if="order.next_status" type="button" class="btn btn-secondary mt-2 w-full" :disabled="form.processing" @click="advance(order)">
                            {{ order.next_status === 'out_for_delivery' ? 'Despachar' : order.next_label }}
                        </button>
                        <Link v-else :href="route('deliveries.show', order.id)" class="btn btn-primary mt-2 w-full">Acertar</Link>
                    </li>
                </ul>
            </section>
        </div>

        <ConfirmDialog
            :open="target !== null"
            :title="`Despachar o pedido #${target?.number ?? ''}`"
            confirm-label="Despachar"
            :disabled="form.courier_id === ''"
            :processing="form.processing"
            @confirm="dispatch"
            @cancel="target = null"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Entregador" required :error="form.errors.courier_id" :hint="couriers.length === 0 ? 'Nenhum usuário com perfil de entregador.' : null">
                    <select :id="id" v-model="form.courier_id" class="input">
                        <option value="" disabled>Selecione</option>
                        <option v-for="courier in couriers" :key="courier.id" :value="courier.id">{{ courier.name }}</option>
                    </select>
                </Field>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
