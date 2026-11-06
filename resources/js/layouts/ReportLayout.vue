<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';

const props = defineProps({
    title: { type: String, required: true },
    slug: { type: String, required: true },
    period: { type: Object, required: true },
    presets: { type: Array, required: true },
    hidesPeriod: { type: Boolean, default: false },
});

const page = usePage();
const { can } = usePermissions();

const tabs = computed(() => [
    can('reports.view') && { label: 'Vendas', slug: 'vendas', route: 'reports.sales' },
    can('reports.view') && { label: 'Produtos', slug: 'produtos', route: 'reports.products' },
    can('reports.finance') && { label: 'Financeiro', slug: 'financeiro', route: 'reports.finance' },
    can('reports.finance') && { label: 'Caixa', slug: 'caixa', route: 'reports.cash' },
    can('reports.view') && { label: 'Estoque', slug: 'estoque', route: 'reports.stock' },
    can('reports.view') && { label: 'Entregas', slug: 'entregas', route: 'reports.deliveries' },
].filter(Boolean));

const heading = computed(() => (props.slug === 'financeiro' ? 'Relatório financeiro' : `Relatório de ${props.title.toLowerCase()}`));

const from = ref(props.period.from);
const to = ref(props.period.to);
const query = computed(() => ({ from: from.value, to: to.value }));

watch(() => props.period, (period) => {
    from.value = period.from;
    to.value = period.to;
});

function apply(range = query.value) {
    router.get(page.url.split('?')[0], range, { preserveScroll: true, replace: true });
}

function changeDate() {
    if (from.value && to.value && from.value <= to.value) {
        apply();
    }
}

const isActive = (preset) => preset.from === props.period.from && preset.to === props.period.to;
</script>

<template>
    <AppLayout :title="`Relatório: ${title}`">
        <PageHeader :title="heading" :subtitle="period.label">
            <div class="flex gap-2 print:hidden">
                <a :href="route('reports.export', { report: slug, ...query })" class="btn btn-secondary">
                    <Download :size="16" />
                    Exportar
                </a>
                <button type="button" class="btn btn-secondary" onclick="window.print()">
                    <Printer :size="16" />
                    Imprimir
                </button>
            </div>
        </PageHeader>

        <nav class="border-line mb-4 flex gap-1 overflow-x-auto border-b print:hidden" aria-label="Relatórios">
            <Link
                v-for="tab in tabs"
                :key="tab.slug"
                :href="route(tab.route, query)"
                class="shrink-0 border-b-2 px-3 py-2 text-sm font-semibold"
                :class="tab.slug === slug ? 'border-brand text-brand' : 'text-muted hover:text-ink border-transparent'"
                :aria-current="tab.slug === slug ? 'page' : undefined"
            >
                {{ tab.label }}
            </Link>
        </nav>

        <div v-if="!hidesPeriod" class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center print:hidden">
            <div class="flex gap-2 overflow-x-auto pb-1">
                <button
                    v-for="preset in presets"
                    :key="preset.label"
                    type="button"
                    class="btn shrink-0"
                    :class="isActive(preset) ? 'btn-primary' : 'btn-secondary'"
                    @click="apply({ from: preset.from, to: preset.to })"
                >
                    {{ preset.label }}
                </button>
            </div>
            <div class="grid grid-cols-2 gap-2 lg:ml-auto lg:w-80">
                <input v-model="from" class="input" type="date" aria-label="Data inicial" @change="changeDate" />
                <input v-model="to" class="input" type="date" aria-label="Data final" @change="changeDate" />
            </div>
        </div>

        <slot />
    </AppLayout>
</template>
