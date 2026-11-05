<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatQuantity } from '@/lib/format';

defineProps({
    table: { type: Number, required: true },
    batch: { type: Number, required: true },
    date: { type: String, required: true },
    items: { type: Array, required: true },
});

onMounted(() => window.print());
</script>

<template>
    <main class="ticket mx-auto bg-white p-3 font-mono text-sm text-black">
        <Head :title="`Cozinha mesa ${table}`" />

        <p class="text-center text-xl font-bold">MESA {{ table }}</p>
        <p class="text-center text-xs">Pedido {{ batch }} · {{ date }}</p>

        <ul class="my-2 border-t border-dashed border-black pt-2">
            <li v-for="item in items" :key="item.id" class="mb-2">
                <p class="font-bold">{{ formatQuantity(item.quantity, item.unit) }} {{ item.product_name }}</p>
                <p v-if="item.notes">&gt;&gt; {{ item.notes }}</p>
            </li>
        </ul>

        <p class="border-t border-dashed border-black pt-1 text-xs">Garçom: {{ items[0]?.user_name }}</p>
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
