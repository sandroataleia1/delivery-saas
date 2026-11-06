<script setup>
import { computed } from 'vue';
import { formatMoney } from '@/lib/format';

const props = defineProps({
    /** [{ label, total, count? }] */
    rows: { type: Array, required: true },
    empty: { type: String, default: 'Sem dados no período.' },
    /** Mostra a participação de cada linha no total. */
    share: { type: Boolean, default: true },
});

const sum = computed(() => props.rows.reduce((total, row) => total + Math.abs(Number(row.total)), 0));
const max = computed(() => Math.max(...props.rows.map((row) => Math.abs(Number(row.total))), 0));
</script>

<template>
    <p v-if="rows.length === 0" class="text-muted py-6 text-center text-sm">{{ empty }}</p>

    <ul v-else class="space-y-3">
        <li v-for="row in rows" :key="row.label" class="text-sm">
            <div class="mb-1 flex items-baseline justify-between gap-3">
                <span class="min-w-0 truncate font-medium">{{ row.label }}</span>
                <span class="shrink-0 font-semibold tabular-nums">
                    {{ formatMoney(row.total) }}
                    <span v-if="share && sum > 0" class="text-muted ml-1 text-xs font-normal">{{ Math.round((Math.abs(Number(row.total)) / sum) * 100) }}%</span>
                </span>
            </div>
            <div class="bg-canvas h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                <div class="bg-brand h-full rounded-full" :style="{ width: `${max > 0 ? (Math.abs(Number(row.total)) / max) * 100 : 0}%` }" />
            </div>
        </li>
    </ul>
</template>
