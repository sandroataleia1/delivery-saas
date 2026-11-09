<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    /** Valores numéricos do período atual e do período de comparação, para a variação. */
    current: { type: [String, Number], default: null },
    previous: { type: [String, Number], default: null },
    comparedTo: { type: String, default: null },
    note: { type: String, default: null },
    href: { type: String, default: null },
});

const change = computed(() => {
    if (props.current === null || props.previous === null) {
        return null;
    }

    const current = Number(props.current);
    const previous = Number(props.previous);

    if (previous === 0) {
        return current === 0 ? { tone: 'flat', text: 'Igual' } : null;
    }

    const percent = Math.round(((current - previous) / previous) * 100);

    if (percent === 0) {
        return { tone: 'flat', text: 'Igual' };
    }

    return { tone: percent > 0 ? 'up' : 'down', text: `${percent > 0 ? '+' : ''}${percent}%` };
});
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href" class="card block p-4" :class="{ 'hover:border-brand transition-colors': href }">
        <p class="text-muted text-sm">{{ label }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums">{{ value }}</p>
        <p v-if="change" class="mt-1 flex items-center gap-1 text-xs">
            <span class="inline-flex items-center gap-0.5 font-semibold" :class="{ 'text-ok': change.tone === 'up', 'text-danger': change.tone === 'down', 'text-muted': change.tone === 'flat' }">
                <ArrowUpRight v-if="change.tone === 'up'" :size="14" aria-hidden="true" />
                <ArrowDownRight v-else-if="change.tone === 'down'" :size="14" aria-hidden="true" />
                <Minus v-else :size="14" aria-hidden="true" />
                {{ change.text }}
            </span>
            <span class="text-muted">{{ comparedTo }}</span>
        </p>
        <p v-else-if="comparedTo && current !== null" class="text-muted mt-1 text-xs">Sem base para comparar {{ comparedTo }}</p>
        <p v-if="note" class="text-muted mt-0.5 text-xs">{{ note }}</p>
    </component>
</template>
