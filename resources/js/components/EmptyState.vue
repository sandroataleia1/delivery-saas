<script setup>
defineProps({
    title: { type: String, required: true },
    text: { type: String, default: null },
});
</script>

<template>
    <div class="card px-6 py-12 text-center">
        <p class="font-semibold">{{ title }}</p>
        <p v-if="text" class="text-muted mt-1 text-sm">{{ text }}</p>
    </div>
</template>
