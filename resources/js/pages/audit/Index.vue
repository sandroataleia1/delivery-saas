<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    users: { type: Array, required: true },
    events: { type: Object, required: true },
    isPlatform: { type: Boolean, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    user: props.filters.user ?? '',
    event: props.filters.event ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const expanded = ref(null);
const eventStyles = {
    created: 'bg-ok-soft text-ok',
    updated: 'bg-warn-soft text-warn',
    deleted: 'bg-danger-soft text-danger',
    login_failed: 'bg-danger-soft text-danger',
};

let timer = null;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const url = route(props.isPlatform ? 'admin.audit.index' : 'audit.index');
        const query = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));

        router.get(url, query, { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <AppLayout title="Auditoria">
        <PageHeader
            title="Auditoria"
            :subtitle="isPlatform ? 'Tudo o que aconteceu na plataforma. Os registros não podem ser alterados.' : 'Quem fez o quê, e quando. Os registros não podem ser alterados.'"
        />

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <input v-model="filters.search" class="input lg:col-span-1" type="search" placeholder="Buscar na descrição" aria-label="Buscar na descrição" />
            <select v-model="filters.user" class="input" aria-label="Filtrar por usuário">
                <option value="">Todos os usuários</option>
                <option v-for="user in users" :key="user.user_id" :value="user.user_id">{{ user.user_name }}</option>
            </select>
            <select v-model="filters.event" class="input" aria-label="Filtrar por tipo de evento">
                <option value="">Todos os eventos</option>
                <option v-for="(label, key) in events" :key="key" :value="key">{{ label }}</option>
            </select>
            <input v-model="filters.from" class="input" type="date" aria-label="Data inicial" />
            <input v-model="filters.to" class="input" type="date" aria-label="Data final" />
        </div>

        <EmptyState v-if="logs.data.length === 0" title="Nenhum registro encontrado" text="Ajuste os filtros da busca." />

        <ul v-else class="card divide-line divide-y">
            <li v-for="log in logs.data" :key="log.id">
                <button
                    type="button"
                    class="hover:bg-canvas flex w-full cursor-pointer items-start gap-3 px-4 py-3 text-left transition-colors"
                    :aria-expanded="expanded === log.id"
                    @click="expanded = expanded === log.id ? null : log.id"
                >
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="text-sm font-semibold">{{ log.user }}</span>
                            <span class="badge" :class="eventStyles[log.event] ?? 'bg-canvas text-muted'">{{ log.event_label }}</span>
                            <span v-if="log.company" class="text-muted text-xs">{{ log.company }}</span>
                        </span>
                        <span class="text-muted mt-0.5 block text-sm break-words">{{ log.description }}</span>
                    </span>
                    <time class="text-muted hidden shrink-0 text-xs tabular-nums sm:block">{{ log.date }}</time>
                    <ChevronDown :size="16" class="text-muted mt-0.5 shrink-0 transition-transform" :class="{ 'rotate-180': expanded === log.id }" />
                </button>

                <div v-if="expanded === log.id" class="bg-canvas/60 px-4 py-3 text-sm">
                    <div v-if="log.changes.length" class="table-wrap mb-3">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th>Antes</th>
                                    <th>Depois</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="change in log.changes" :key="change.field">
                                    <td class="font-semibold whitespace-nowrap">{{ change.field }}</td>
                                    <td class="text-muted break-words">{{ change.old }}</td>
                                    <td class="break-words">{{ change.new }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <dl class="text-muted grid gap-1 text-xs sm:grid-cols-[6rem_1fr]">
                        <dt class="font-semibold">Data</dt>
                        <dd class="tabular-nums">{{ log.date }}</dd>
                        <dt class="font-semibold">Endereço IP</dt>
                        <dd>{{ log.ip_address ?? '—' }}</dd>
                        <dt class="font-semibold">Ação</dt>
                        <dd class="break-all">{{ log.url ?? '—' }}</dd>
                        <dt class="font-semibold">Navegador</dt>
                        <dd class="break-words">{{ log.user_agent || '—' }}</dd>
                    </dl>
                </div>
            </li>
        </ul>

        <Pagination :meta="logs" />
    </AppLayout>
</template>
