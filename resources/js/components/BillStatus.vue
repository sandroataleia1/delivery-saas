<script setup>
defineProps({
    status: { type: String, required: true },
    label: { type: String, required: true },
});

const styles = {
    open: 'bg-warn-soft text-warn',
    overdue: 'bg-danger-soft text-danger',
    paid: 'bg-ok-soft text-ok',
    cancelled: 'bg-canvas text-muted',
};
</script>

<template>
    <span class="badge" :class="styles[status]">{{ label }}</span>
</template>
