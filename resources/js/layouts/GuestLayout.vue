<script setup>
import { Head } from '@inertiajs/vue3';
import { Bike } from '@lucide/vue';
import DeliveryPanel from '@/components/DeliveryPanel.vue';
import FlashMessages from '@/components/FlashMessages.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    wide: { type: Boolean, default: false },
});
</script>

<template>
    <div class="bg-surface flex min-h-dvh">
        <Head :title="title" />
        <FlashMessages />
        <DeliveryPanel />

        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full" :class="wide ? 'max-w-md' : 'max-w-sm'">
                <div class="mb-8 flex items-center gap-2.5 lg:hidden">
                    <span class="bg-brand flex size-10 items-center justify-center rounded-lg text-white">
                        <Bike :size="22" />
                    </span>
                    <span class="text-lg font-bold tracking-tight">Delivery SaaS</span>
                </div>
                <h1 class="text-3xl font-bold tracking-tight">{{ title }}</h1>
                <p v-if="subtitle" class="text-muted mt-1.5 text-sm">{{ subtitle }}</p>
                <div class="mt-7">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
