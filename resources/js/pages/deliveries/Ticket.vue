<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatMoney, formatPhone, formatQuantity } from '@/lib/format';

defineProps({
    order: { type: Object, required: true },
});

onMounted(() => window.print());
</script>

<template>
    <main class="ticket mx-auto bg-white p-3 font-mono text-sm text-black">
        <Head :title="`Pedido ${order.number}`" />

        <p class="text-center text-xl font-bold">PEDIDO #{{ order.number }}</p>
        <p v-if="order.online_number" class="text-center text-xs font-bold">ONLINE #{{ order.online_number }}</p>
        <p class="text-center text-xs">{{ order.type_label }} · {{ order.date }}</p>

        <div class="my-2 border-t border-dashed border-black pt-2">
            <p class="font-bold">{{ order.customer }}</p>
            <p>{{ formatPhone(order.phone) }}</p>
            <p v-if="order.address">{{ order.address }}</p>
            <p v-if="order.reference">{{ order.reference }}</p>
        </div>

        <ul class="my-2 border-t border-dashed border-black pt-2">
            <li v-for="item in order.items" :key="item.id" class="mb-1">
                <p class="flex justify-between gap-2 font-bold">
                    <span>{{ formatQuantity(item.quantity, item.unit) }} {{ item.product_name }}</span>
                    <span>{{ formatMoney(item.total) }}</span>
                </p>
                <p v-if="item.options_label">{{ item.options_label }}</p>
                <p v-if="item.notes">&gt;&gt; {{ item.notes }}</p>
            </li>
        </ul>

        <dl class="border-t border-dashed border-black pt-2 text-xs">
            <div class="flex justify-between">
                <dt>Taxa de entrega</dt>
                <dd>{{ formatMoney(order.delivery_fee) }}</dd>
            </div>
            <div class="flex justify-between text-sm font-bold">
                <dt>TOTAL</dt>
                <dd>{{ formatMoney(order.total) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>{{ order.payment }}</dt>
                <dd v-if="order.change_for">Troco para {{ formatMoney(order.change_for) }}</dd>
            </div>
        </dl>
        <p v-if="order.notes" class="mt-2 border-t border-dashed border-black pt-1">{{ order.notes }}</p>
    </main>
</template>

<style>
.ticket {
    width: 76mm;
}

@media print {
    @page {
        size: 80mm auto;
        margin: 0;
    }

    body {
        background: white;
    }
}
</style>
