<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
            <p v-if="subtitle" class="text-muted mt-1 text-sm">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.default" class="flex shrink-0 flex-wrap gap-2">
            <slot />
        </div>
    </div>
</template>
