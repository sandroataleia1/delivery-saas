<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatCnpj, formatMoney, formatQuantity } from '@/lib/format';

defineProps({
    sale: { type: Object, required: true },
    company: { type: Object, required: true },
});

onMounted(() => window.print());
</script>

<template>
    <main class="receipt mx-auto bg-white p-3 font-mono text-xs text-black">
        <Head :title="`Cupom ${sale.number}`" />

        <header class="text-center">
            <p class="text-sm font-bold">{{ company.trade_name }}</p>
            <p>{{ company.legal_name }}</p>
            <p>CNPJ {{ formatCnpj(company.document) }}</p>
            <p v-if="company.street">{{ company.street }}, {{ company.number }} - {{ company.district }}</p>
            <p v-if="company.city">{{ company.city }}/{{ company.state }}</p>
        </header>

        <p class="my-2 border-y border-dashed border-black py-1 text-center font-bold">CUPOM NÃO FISCAL</p>

        <p>Venda #{{ sale.number }} · {{ sale.date }}</p>
        <p>Operador: {{ sale.user }}</p>
        <p v-if="sale.cancelled" class="my-1 font-bold">VENDA CANCELADA</p>

        <ul class="my-2 border-t border-dashed border-black pt-2">
            <li v-for="item in sale.items" :key="item.id" class="mb-1">
                <p>{{ item.product_name }}</p>
                <p class="flex justify-between">
                    <span>{{ formatQuantity(item.quantity, item.unit) }} x {{ formatMoney(item.unit_price) }}</span>
                    <span>{{ formatMoney(item.total) }}</span>
                </p>
            </li>
        </ul>

        <dl class="border-t border-dashed border-black pt-2">
            <div v-if="Number(sale.discount) > 0" class="flex justify-between">
                <dt>Desconto</dt>
                <dd>- {{ formatMoney(sale.discount) }}</dd>
            </div>
            <div class="flex justify-between text-sm font-bold">
                <dt>TOTAL</dt>
                <dd>{{ formatMoney(sale.total) }}</dd>
            </div>
            <div v-for="payment in sale.payments" :key="payment.id" class="flex justify-between">
                <dt>{{ payment.label }}</dt>
                <dd>{{ formatMoney(payment.received) }}</dd>
            </div>
            <div v-if="Number(sale.change) > 0" class="flex justify-between">
                <dt>Troco</dt>
                <dd>{{ formatMoney(sale.change) }}</dd>
            </div>
        </dl>

        <p class="mt-3 text-center">Obrigado pela preferência!</p>
    </main>
</template>

<style>
.receipt {
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
