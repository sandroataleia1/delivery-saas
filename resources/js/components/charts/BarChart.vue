<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    /** [{ label, value, tooltip }] */
    points: { type: Array, required: true },
    height: { type: Number, default: 180 },
    label: { type: String, required: true },
});

const hovered = ref(null);

const max = computed(() => Math.max(...props.points.map((point) => point.value), 0));
const isEmpty = computed(() => max.value === 0);

// Rótulos seletivos: no máximo oito no eixo, para não se atropelarem.
const step = computed(() => Math.max(Math.ceil(props.points.length / 8), 1));

const barHeight = (value) => (value === 0 ? 0 : Math.max((value / max.value) * 100, 1.5));
</script>

<template>
    <p v-if="isEmpty" class="text-muted py-10 text-center text-sm">Sem dados no período.</p>

    <div v-else role="img" :aria-label="label">
        <div class="relative" :style="{ height: `${height}px` }">
            <div class="border-line absolute inset-x-0 top-0 border-t border-dashed" />
            <div class="flex h-full items-end gap-0.5 border-b border-line" @mouseleave="hovered = null">
                <div
                    v-for="(point, index) in points"
                    :key="point.label"
                    class="group relative flex h-full min-w-0 flex-1 items-end"
                    @mouseenter="hovered = index"
                >
                    <div
                        class="w-full rounded-t transition-opacity"
                        :class="hovered !== null && hovered !== index ? 'bg-brand/40' : 'bg-brand'"
                        :style="{ height: `${barHeight(point.value)}%` }"
                    />
                    <div
                        v-if="hovered === index"
                        class="bg-sidebar pointer-events-none absolute bottom-full z-10 mb-1 rounded-lg px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
                        :class="index > points.length / 2 ? 'right-0' : 'left-0'"
                    >
                        <p class="font-semibold">{{ point.label }}</p>
                        <p class="tabular-nums">{{ point.tooltip }}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-muted mt-1 flex gap-0.5 text-[11px] tabular-nums">
            <span v-for="(point, index) in points" :key="point.label" class="relative h-4 min-w-0 flex-1">
                <span v-if="index % step === 0" class="absolute left-0 whitespace-nowrap">{{ point.label }}</span>
            </span>
        </div>
    </div>
</template>
