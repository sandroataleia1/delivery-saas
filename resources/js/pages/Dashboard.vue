<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    stats: { type: Object, required: true },
    recent: { type: Array, required: true },
});

const page = usePage();
const { can } = usePermissions();

const cards = computed(() => [
    { label: 'Usuários ativos', value: props.stats.active_users },
    props.stats.low_stock === null
        ? { label: 'Perfis de acesso', value: props.stats.roles }
        : { label: 'Produtos com estoque baixo', value: props.stats.low_stock, href: route('stock.index', { low: 1 }) },
    { label: 'Entradas hoje', value: props.stats.logins_today },
    { label: 'Eventos auditados hoje', value: props.stats.events_today },
]);
</script>

<template>
    <AppLayout title="Início">
        <PageHeader :title="`Olá, ${page.props.auth.user.name.split(' ')[0]}`" :subtitle="page.props.auth.company?.trade_name" />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <component :is="card.href ? Link : 'div'" v-for="card in cards" :key="card.label" :href="card.href" class="card p-4" :class="{ 'hover:border-brand transition-colors': card.href }">
                <p class="text-muted text-sm">{{ card.label }}</p>
                <p class="mt-1 text-3xl font-bold tabular-nums">{{ card.value }}</p>
            </component>
        </div>

        <section v-if="can('audit.view')" class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-lg font-bold">Atividade recente</h2>
                <Link :href="route('audit.index')" class="text-brand text-sm font-semibold hover:underline">Ver tudo</Link>
            </div>
            <ul class="card divide-line divide-y">
                <li v-for="log in recent" :key="log.id" class="flex flex-col gap-0.5 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <span class="min-w-0 text-sm">
                        <span class="font-semibold">{{ log.user }}</span>
                        <span class="text-muted"> · {{ log.description }}</span>
                    </span>
                    <time class="text-muted shrink-0 text-xs tabular-nums">{{ log.date }}</time>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
