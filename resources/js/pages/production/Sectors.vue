<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/PageHeader.vue';
const props = defineProps({ sectors: Array, defaultId: Number });
const editing = ref(null);
const form = useForm({ name: '', active: true, is_default: false });
function edit(sector) { editing.value = sector.id; form.name = sector.name; form.active = sector.active; form.is_default = props.defaultId === sector.id; form.clearErrors(); }
function reset() { editing.value = null; form.name = ''; form.active = true; form.is_default = false; }
function save() { const options = { preserveScroll: true, onSuccess: reset }; if (editing.value) form.put(route('production.sectors.update', editing.value), options); else form.post(route('production.sectors.store'), options); }
</script>
<template>
    <AppLayout title="Setores de produção">
        <PageHeader title="Setores de produção" subtitle="Defina o destino padrão e associe categorias para separar cozinha e bar." />
        <Link class="btn btn-secondary mb-4" :href="route('production.index')">Painel de produção</Link>
        <form class="card mb-5 max-w-xl space-y-4 p-5" @submit.prevent="save">
            <h2 class="font-bold">{{ editing ? 'Editar setor' : 'Novo setor' }}</h2>
            <label class="block">Nome<input v-model="form.name" class="input mt-1" required maxlength="60" /></label>
            <label class="flex gap-2"><input v-model="form.active" type="checkbox" />Ativo</label>
            <label class="flex gap-2"><input v-model="form.is_default" type="checkbox" />Destino padrão da empresa</label>
            <p class="text-muted text-sm">O padrão atende produtos sem setor na categoria. Trabalhos já recebidos preservam seu destino.</p>
            <p v-for="(error, key) in form.errors" :key="key" class="text-danger" role="alert">{{ error }}</p>
            <div class="flex gap-2"><button class="btn btn-primary" :disabled="form.processing">Salvar setor</button><button v-if="editing" type="button" class="btn btn-secondary" @click="reset">Cancelar edição</button></div>
        </form>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><article v-for="sector in sectors" :key="sector.id" class="card flex items-center justify-between gap-3 p-4"><div><h2 class="font-bold">{{ sector.name }}</h2><p class="text-muted text-sm">{{ sector.active ? 'Ativo' : 'Inativo' }}{{ defaultId === sector.id ? ' · Padrão' : '' }}</p></div><button class="btn btn-secondary" @click="edit(sector)">Editar</button></article></div>
    </AppLayout>
</template>
