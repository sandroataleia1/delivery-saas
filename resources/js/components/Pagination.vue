<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';

defineProps({
    meta: { type: Object, required: true },
});
</script>

<template>
    <nav
        v-if="meta.total > 0"
        class="text-muted mt-4 flex items-center justify-between gap-3 text-sm"
        aria-label="Paginação"
    >
        <p>{{ meta.from }}–{{ meta.to }} de {{ meta.total }}</p>
        <div v-if="meta.last_page > 1" class="flex gap-2">
            <Link
                v-if="meta.prev_page_url"
                :href="meta.prev_page_url"
                preserve-scroll
                class="btn btn-secondary"
                aria-label="Página anterior"
            >
                <ChevronLeft :size="16" />
            </Link>
            <Link
                v-if="meta.next_page_url"
                :href="meta.next_page_url"
                preserve-scroll
                class="btn btn-secondary"
                aria-label="Próxima página"
            >
                <ChevronRight :size="16" />
            </Link>
        </div>
    </nav>
</template>
