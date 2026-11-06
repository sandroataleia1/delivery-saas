<script setup>
import { ref } from 'vue';
import { Search } from '@lucide/vue';
import { formatQuantity } from '@/lib/format';

defineProps({
    placeholder: { type: String, default: 'Buscar produto por nome ou código de barras' },
    label: { type: String, default: 'Buscar produto' },
});

const emit = defineEmits(['select']);

const term = ref('');
const results = ref([]);
const open = ref(false);
let timer = null;

async function search() {
    const query = term.value.trim();

    if (query === '') {
        results.value = [];

        return;
    }

    const response = await fetch(route('products.search', { q: query }), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    results.value = response.ok ? await response.json() : [];
    open.value = true;
}

function onInput() {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
}

function choose(product) {
    emit('select', product);
    term.value = '';
    results.value = [];
    open.value = false;
}

// O leitor de código de barras digita tudo e envia Enter: não dá para esperar o atraso.
async function onEnter() {
    clearTimeout(timer);
    await search();

    if (results.value.length > 0) {
        choose(results.value[0]);
    }
}
</script>

<template>
    <div class="relative">
        <Search :size="16" class="text-muted pointer-events-none absolute top-3 left-3" />
        <input
            v-model="term"
            class="input pl-9"
            type="search"
            :placeholder="placeholder"
            :aria-label="label"
            autocomplete="off"
            @input="onInput"
            @keydown.enter.prevent="onEnter"
            @focus="open = results.length > 0"
            @blur="open = false"
        />
        <ul
            v-if="open"
            class="border-line bg-surface absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border shadow-lg"
        >
            <li v-if="results.length === 0" class="text-muted px-3 py-2.5 text-sm">
                Nenhum produto com controle de estoque encontrado.
            </li>
            <li v-for="product in results" :key="product.id">
                <button
                    type="button"
                    class="hover:bg-canvas flex w-full cursor-pointer items-center justify-between gap-3 px-3 py-2.5 text-left text-sm"
                    @mousedown.prevent="choose(product)"
                >
                    <span class="min-w-0 truncate font-semibold">{{ product.name }}</span>
                    <span class="text-muted shrink-0 text-xs tabular-nums">Saldo: {{ formatQuantity(product.stock_quantity, product.unit) }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
