<script setup>
import { computed, ref } from 'vue';
import { Search } from '@lucide/vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    products: { type: Array, required: true },
    categories: { type: Array, required: true },
    placeholder: { type: String, default: 'Nome ou código de barras' },
    composition: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['add']);

const term = ref('');
const category = ref(null);
const notFound = ref(false);
const searchField = ref(null);

const normalize = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

// Categorias só de insumos não têm o que mostrar aqui.
const sellableCategories = computed(() => props.categories.filter((item) => props.products.some((product) => product.category_id === item.id)));

const visibleProducts = computed(() => {
    const query = normalize(term.value.trim());

    return props.products
        .filter((product) => category.value === null || product.category_id === category.value)
        .filter((product) => query === '' || normalize(product.name).includes(query))
        .slice(0, 120);
});

function add(product, quantity = 1) {
    notFound.value = false;
    emit('add', product, quantity);
}

// Aceita "3*7891234" para lançar três unidades de uma vez pelo leitor.
function submitSearch() {
    const match = term.value.trim().match(/^(\d+(?:[.,]\d+)?)\*(.+)$/);
    const quantity = match ? Number(match[1].replace(',', '.')) : 1;
    const code = (match ? match[2] : term.value).trim();

    if (code === '') {
        return;
    }

    const exact = props.products.find((product) => product.barcode === code || product.sku === code);
    const found = exact ?? (visibleProducts.value.length === 1 ? visibleProducts.value[0] : null);

    if (found) {
        add(found, quantity);
        term.value = '';
    } else {
        notFound.value = visibleProducts.value.length === 0;
    }
}

defineExpose({ focus: () => searchField.value?.focus() });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="border-line bg-surface space-y-3 border-b p-3">
            <div class="relative">
                <Search :size="18" class="text-muted pointer-events-none absolute top-3 left-3" />
                <input
                    ref="searchField"
                    v-model="term"
                    class="input pl-10"
                    type="search"
                    :placeholder="placeholder"
                    aria-label="Buscar produto"
                    autocomplete="off"
                    @input="notFound = false"
                    @keydown.enter.prevent="submitSearch"
                />
            </div>
            <p v-if="notFound" class="text-danger text-sm" role="alert">Produto não encontrado.</p>
            <div class="flex gap-2 overflow-x-auto pb-1">
                <button type="button" class="btn shrink-0" :class="category === null ? 'btn-primary' : 'btn-secondary'" @click="category = null">
                    Todos
                </button>
                <button
                    v-for="item in sellableCategories"
                    :key="item.id"
                    type="button"
                    class="btn shrink-0"
                    :class="category === item.id ? 'btn-primary' : 'btn-secondary'"
                    @click="category = item.id"
                >
                    {{ item.name }}
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <p v-if="visibleProducts.length === 0" class="text-muted py-12 text-center">Nenhum produto encontrado.</p>
            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                <li v-for="product in visibleProducts" :key="product.id">
                    <button
                        type="button"
                        class="card hover:border-brand flex h-24 w-full cursor-pointer flex-col justify-between p-3 text-left transition-colors"
                        @click="add(product)"
                    >
                        <span class="line-clamp-2 text-sm leading-tight font-semibold">{{ product.name }}</span>
                        <span class="text-brand-strong text-sm font-bold tabular-nums">{{ composition[product.id] ? `a partir de ${formatMoney(composition[product.id].from_price)}` : formatMoney(product.sale_price) }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
