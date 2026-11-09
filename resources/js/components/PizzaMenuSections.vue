<script setup>
import { computed } from 'vue';
import { ChevronRight } from '@lucide/vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    product: { type: Object, required: true },
    pizza: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['pick']);

// Uma seção por tamanho, com os sabores disponíveis e o preço da pizza inteira naquele tamanho.
const sections = computed(() => props.pizza.sizes
    .map((size) => ({
        size,
        flavors: props.pizza.flavors
            .filter((flavor) => !flavor.paused && flavor.prices?.[size.id] !== undefined)
            .map((flavor) => ({ ...flavor, price: flavor.prices[size.id] }))
            .sort((first, second) => Number(first.price) - Number(second.price) || first.name.localeCompare(second.name)),
    }))
    .filter((section) => section.flavors.length > 0));

const details = (size) => [
    size.slices ? `${size.slices} fatias` : null,
    size.max_flavors > 1 ? `até ${size.max_flavors} sabores` : '1 sabor',
].filter(Boolean).join(' · ');
</script>

<template>
    <li class="space-y-2" :class="{ 'opacity-60': product.sold_out }">
        <section v-for="{ size, flavors } in sections" :key="size.id" class="card overflow-hidden" :aria-label="`${product.name} ${size.name}`">
            <header class="border-line border-b px-3 py-2.5">
                <p class="font-semibold">{{ product.name }} {{ size.name }}</p>
                <p class="text-muted text-sm">{{ details(size) }}</p>
                <p v-if="product.sold_out" class="text-danger text-sm font-bold">Esgotado</p>
            </header>
            <ul class="divide-line divide-y">
                <li v-for="flavor in flavors" :key="flavor.id">
                    <button
                        type="button"
                        class="hover:bg-canvas flex min-h-12 w-full items-center gap-3 px-3 py-2 text-left disabled:cursor-not-allowed"
                        :disabled="disabled || product.sold_out"
                        :aria-label="`${product.name} ${size.name} ${flavor.name}, ${formatMoney(flavor.price)}`"
                        @click="emit('pick', { sizeId: size.id, flavorId: flavor.id })"
                    >
                        <span class="min-w-0 flex-1 break-words">{{ flavor.name }}</span>
                        <span class="text-brand-strong font-bold tabular-nums">{{ formatMoney(flavor.price) }}</span>
                        <ChevronRight :size="16" class="text-muted shrink-0" aria-hidden="true" />
                    </button>
                </li>
            </ul>
        </section>
    </li>
</template>
