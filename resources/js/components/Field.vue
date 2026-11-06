<script setup>
import { useId } from 'vue';

defineProps({
    label: { type: String, required: true },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
});

const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-semibold">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>
        <slot :id="id" :invalid="Boolean(error)" />
        <p v-if="hint && !error" class="text-muted mt-1.5 text-xs">{{ hint }}</p>
        <p v-if="error" class="text-danger mt-1.5 text-sm" role="alert">{{ error }}</p>
    </div>
</template>
