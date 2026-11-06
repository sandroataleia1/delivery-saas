<script setup>
defineProps({
    active: { type: Boolean, required: true },
});
</script>

<template>
    <span class="badge" :class="active ? 'bg-ok-soft text-ok' : 'bg-canvas text-muted'">
        {{ active ? 'Ativo' : 'Inativo' }}
    </span>
</template>
