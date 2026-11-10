<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePoll } from '@inertiajs/vue3';
import { Bike, History, MapPin, Plus } from '@lucide/vue';
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
    { status: 'returned', label: 'Não entregues' },
    { status: 'delivered', label: 'A acertar' },
];

const grouped = computed(() => columns.map((column) => ({
    ...column,
    orders: props.orders.filter((order) => order.status === column.status),
})));

const form = useForm({ expected_status: '' });
const target = ref(null);
const chosen = ref('');

function advance(order) {
    if (order.next_status === 'out_for_delivery') {
        chosen.value = '';
        form.clearErrors();
        target.value = order;

        return;
    }

    form.expected_status = order.status;
    form.transform((data) => data).post(route('deliveries.advance', order.id), { preserveScroll: true });
}

/** O cadastro de entregadores e os usuários entregadores antigos vão em campos diferentes. */
function dispatch() {
    const [type, id] = chosen.value.split(':');
    form.expected_status = target.value.status;
    form.transform((data) => ({ ...data, [type === 'courier' ? 'delivery_courier_id' : 'courier_id']: Number(id) })).post(route('deliveries.advance', target.value.id), {
        preserveScroll: true,
        onSuccess: () => (target.value = null),
    });
}

const returnForm = useForm({ reason: '' });
const returning = ref(null);

function askReturn(order) {
    returnForm.reset();
    returnForm.clearErrors();
    returning.value = order;
}

function markReturned() {
    returnForm.post(route('deliveries.returned', returning.value.id), {
        preserveScroll: true,
        onSuccess: () => (returning.value = null),
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
            <Link v-if="can('deliveries.manage')" :href="route('couriers.index')" class="btn btn-secondary">
                <Bike :size="16" />
                Entregadores
            </Link>
            <Link :href="route('deliveries.create')" class="btn btn-primary">
                <Plus :size="16" />
                Novo pedido
            </Link>
        </PageHeader>

        <p v-if="form.errors.order" class="text-danger mb-3 text-sm" role="alert">{{ form.errors.order }}</p>

        <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <section v-for="column in grouped" :key="column.status">
                <h2 class="text-muted mb-2 flex items-center justify-between text-sm font-semibold">
                    {{ column.label }}
                    <span class="badge bg-canvas text-muted">{{ column.orders.length }}</span>
                </h2>
                <ul class="space-y-2">
                    <li v-for="order in column.orders" :key="order.id" class="card p-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <Link :href="route('deliveries.show', order.id)" class="text-brand font-bold hover:underline">#{{ order.number }}</Link>
                            <span v-if="order.online_number" class="text-muted text-xs">Online #{{ order.online_number }}</span>
                            <span class="text-muted text-xs tabular-nums">{{ order.minutes }} min</span>
                        </div>
                        <p class="mt-1 truncate font-semibold">{{ order.customer }}</p>
                        <p class="text-muted truncate text-xs">{{ order.district ?? 'Retirada no balcão' }}</p>
                        <p class="mt-1 font-bold tabular-nums">{{ formatMoney(order.total) }}</p>
                        <p class="text-muted text-xs">{{ order.payment }}<template v-if="order.courier"> · {{ order.courier }}</template></p>
                        <p v-if="order.return_reason" class="text-danger mt-1 text-xs font-semibold">Motivo: {{ order.return_reason }}</p>
                        <Link v-if="['received', 'preparing'].includes(order.status) && can('production.view')" :href="route('production.index')" class="btn btn-secondary mt-2 w-full">Ver preparo por item</Link>
                        <p v-else-if="['received', 'preparing'].includes(order.status)" class="text-muted mt-2">Aguarda atualização da produção.</p>
                        <button v-else-if="order.next_status" type="button" class="btn btn-secondary mt-2 w-full" :disabled="form.processing" @click="advance(order)">
                            {{ order.next_status === 'out_for_delivery' ? 'Despachar' : order.status === 'returned' ? 'Voltar para prontos' : order.next_label }}
                        </button>
                        <button v-if="order.status === 'out_for_delivery'" type="button" class="btn btn-ghost text-danger mt-1 w-full" @click="askReturn(order)">Não entregue</button>
                        <Link v-else :href="route('deliveries.show', order.id)" class="btn btn-primary mt-2 w-full">Acertar</Link>
                    </li>
                </ul>
            </section>
        </div>

        <ConfirmDialog
            :open="target !== null"
            :title="`Despachar o pedido #${target?.number ?? ''}`"
            confirm-label="Despachar"
            :disabled="chosen === ''"
            :processing="form.processing"
            @confirm="dispatch"
            @cancel="target = null"
        >
            <div class="mt-4">
                <Field
                    v-slot="{ id }"
                    label="Entregador"
                    required
                    :error="form.errors.courier_id || form.errors.delivery_courier_id"
                    :hint="couriers.length === 0 ? 'Nenhum entregador ativo. Cadastre em Entregas > Entregadores.' : null"
                >
                    <select :id="id" v-model="chosen" class="input">
                        <option value="" disabled>Selecione</option>
                        <option v-for="courier in couriers" :key="`${courier.type}:${courier.id}`" :value="`${courier.type}:${courier.id}`">{{ courier.name }}</option>
                    </select>
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="returning !== null"
            :title="`Pedido #${returning?.number ?? ''} não entregue`"
            message="O pedido volta para a loja. Depois dá para despachar de novo ou cancelar."
            confirm-label="Marcar não entregue"
            danger
            :disabled="returnForm.reason.trim() === ''"
            :processing="returnForm.processing"
            @confirm="markReturned"
            @cancel="returning = null"
        >
            <div class="mt-4">
                <Field v-slot="{ id }" label="Motivo" required :error="returnForm.errors.reason || returnForm.errors.order">
                    <input :id="id" v-model="returnForm.reason" class="input" maxlength="255" placeholder="Ex.: cliente ausente, endereço não encontrado" />
                </Field>
            </div>
        </ConfirmDialog>
    </AppLayout>
</template>
