<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    note: { type: String, default: null },
    tone: { type: String, default: null },
});
</script>

<template>
    <div class="card p-4">
        <p class="text-muted text-sm">{{ label }}</p>
        <p class="mt-1 text-xl font-bold tabular-nums sm:text-2xl" :class="{ 'text-danger': tone === 'bad', 'text-ok': tone === 'good' }">{{ value }}</p>
        <p v-if="note" class="text-muted mt-0.5 text-xs">{{ note }}</p>
    </div>
</template>
