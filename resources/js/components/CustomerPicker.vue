<script setup>
import { ref } from 'vue';
import { Search, X } from '@lucide/vue';
import { formatMoney, formatPhone } from '@/lib/format';

const model = defineModel({ type: Object, default: null });

const props = defineProps({
    placeholder: { type: String, default: 'Cliente: nome ou telefone' },
});

const term = ref('');
const results = ref([]);
const open = ref(false);
let timer = null;

async function search() {
    if (term.value.trim() === '') {
        results.value = [];
        open.value = false;

        return;
    }

    const response = await fetch(route('customers.search', { q: term.value }), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    results.value = response.ok ? await response.json() : [];
    open.value = true;
}

function onInput() {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
}

function choose(customer) {
    model.value = customer;
    term.value = '';
    results.value = [];
    open.value = false;
}
</script>

<template>
    <div v-if="model" class="border-line flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm">
        <span class="min-w-0">
            <span class="block truncate font-semibold">{{ model.name }}</span>
            <span class="text-muted block text-xs tabular-nums">
                {{ formatPhone(model.phone) }}
                <template v-if="model.allow_credit"> · Fiado{{ Number(model.open_credit) > 0 ? `: deve ${formatMoney(model.open_credit)}` : ' liberado' }}</template>
            </span>
        </span>
        <button type="button" class="btn btn-ghost min-h-7 px-1 py-0.5" :aria-label="`Remover cliente ${model.name}`" @click="model = null">
            <X :size="16" />
        </button>
    </div>

    <div v-else class="relative">
        <Search :size="16" class="text-muted pointer-events-none absolute top-3 left-3" />
        <input
            v-model="term"
            class="input pl-9"
            type="search"
            :placeholder="placeholder"
            aria-label="Buscar cliente"
            autocomplete="off"
            @input="onInput"
            @blur="open = false"
        />
        <ul v-if="open" class="border-line bg-surface absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border shadow-lg">
            <li v-if="results.length === 0" class="text-muted px-3 py-2.5 text-sm">Nenhum cliente encontrado.</li>
            <li v-for="customer in results" :key="customer.id">
                <button type="button" class="hover:bg-canvas w-full cursor-pointer px-3 py-2 text-left text-sm" @mousedown.prevent="choose(customer)">
                    <span class="block font-semibold">{{ customer.name }}</span>
                    <span class="text-muted block text-xs tabular-nums">{{ formatPhone(customer.phone) }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
