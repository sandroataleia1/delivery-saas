<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { MonitorCog, Pencil, Plus, Star, Trash2, X } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    sectors: { type: Array, required: true },
    categories: { type: Array, required: true },
    defaultId: { type: Number, default: null },
});

const sectorName = (id) => props.sectors.find((sector) => sector.id === id)?.name;
const defaultName = computed(() => sectorName(props.defaultId));

/** null = modal fechado; { id: null } = novo setor; { id } = editando. */
const editing = ref(null);
const dialog = ref(null);
const form = useForm({ name: '', active: true, is_default: false, category_ids: [] });

function start(sector = null) {
    Object.assign(form, {
        name: sector?.name ?? '',
        active: sector?.active ?? true,
        is_default: sector ? props.defaultId === sector.id : props.sectors.length === 0,
        category_ids: sector ? sector.categories.map((category) => category.id) : [],
    });
    form.clearErrors();
    editing.value = { id: sector?.id ?? null };
}

watch(editing, (value) => (value ? dialog.value?.showModal() : dialog.value?.close()));

function toggleCategory(id) {
    form.category_ids = form.category_ids.includes(id) ? form.category_ids.filter((item) => item !== id) : [...form.category_ids, id];
}

/** Mostra de onde a categoria sai hoje, para deixar claro que marcar aqui muda o destino dela. */
function currentPlace(category) {
    if (category.production_sector_id === editing.value?.id) {
        return null;
    }
    return category.production_sector_id ? `hoje em ${sectorName(category.production_sector_id)}` : 'hoje no setor padrão';
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value.id) {
        form.put(route('production.sectors.update', editing.value.id), options);
    } else {
        form.post(route('production.sectors.store'), options);
    }
}

const removing = ref(null);
const removeForm = useForm({});

function remove() {
    removeForm.delete(route('production.sectors.destroy', removing.value.id), { preserveScroll: true, onSuccess: () => (removing.value = null) });
}
</script>

<template>
    <AppLayout title="Setores">
        <PageHeader title="Setores" subtitle="Cozinha, bar, balcão... Cada categoria sai no seu setor: na tela de produção e na impressora dele.">
            <Link class="btn btn-secondary" :href="route('production.index')">
                <MonitorCog :size="16" />
                Painel de produção
            </Link>
            <button type="button" class="btn btn-primary" @click="start()">
                <Plus :size="16" />
                Novo setor
            </button>
        </PageHeader>

        <EmptyState v-if="sectors.length === 0" title="Nenhum setor cadastrado" text="Crie o primeiro setor (por exemplo, Cozinha) para os pedidos terem para onde ir." />

        <ul v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="sector in sectors" :key="sector.id" class="card flex flex-col gap-3 p-4" :class="{ 'opacity-60': !sector.active }">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-bold">{{ sector.name }}</h2>
                        <p class="mt-1 flex flex-wrap gap-1">
                            <span v-if="defaultId === sector.id" class="badge bg-brand-soft text-brand"><Star :size="12" />Padrão</span>
                            <span v-if="!sector.active" class="badge bg-canvas text-muted">Inativo</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button type="button" class="btn btn-ghost size-9 px-0" :aria-label="`Editar ${sector.name}`" title="Editar" @click="start(sector)">
                            <Pencil :size="16" />
                        </button>
                        <button type="button" class="btn btn-ghost text-danger size-9 px-0" :aria-label="`Excluir ${sector.name}`" title="Excluir" @click="removing = sector">
                            <Trash2 :size="16" />
                        </button>
                    </div>
                </div>

                <div>
                    <p class="text-muted mb-1 text-xs font-semibold uppercase">Categorias</p>
                    <ul v-if="sector.categories.length" class="flex flex-wrap gap-1">
                        <li v-for="category in sector.categories" :key="category.id" class="badge bg-canvas text-ink">{{ category.name }}</li>
                    </ul>
                    <p v-else-if="defaultId === sector.id" class="text-muted text-sm">Recebe as categorias sem setor.</p>
                    <p v-else class="text-muted text-sm">Nenhuma categoria.</p>
                </div>
            </li>
        </ul>

        <dialog
            ref="dialog"
            class="bg-surface text-ink m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-xl p-0 shadow-xl backdrop:bg-black/40"
            aria-labelledby="sector-dialog-title"
            @cancel.prevent="editing = null"
        >
            <form v-if="editing" @submit.prevent="save">
                <div class="border-line bg-surface sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-5 py-3">
                    <h2 id="sector-dialog-title" class="text-lg font-bold">{{ editing.id ? 'Editar setor' : 'Novo setor' }}</h2>
                    <button type="button" class="btn btn-ghost size-10 shrink-0 px-0" aria-label="Fechar" @click="editing = null">
                        <X :size="18" />
                    </button>
                </div>

                <div class="space-y-4 p-5">
                    <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                        <input :id="id" v-model="form.name" class="input" maxlength="60" required placeholder="Cozinha, Bar, Balcão..." />
                    </Field>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-semibold">Categorias deste setor</legend>
                        <p v-if="categories.length === 0" class="text-muted text-sm">Nenhuma categoria cadastrada ainda.</p>
                        <ul v-else class="border-line divide-line max-h-64 divide-y overflow-y-auto rounded-lg border">
                            <li v-for="category in categories" :key="category.id">
                                <label class="hover:bg-canvas flex cursor-pointer items-center gap-3 px-3 py-2">
                                    <input type="checkbox" class="size-4" :checked="form.category_ids.includes(category.id)" @change="toggleCategory(category.id)" />
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ category.name }}</span>
                                    <span v-if="currentPlace(category)" class="text-muted shrink-0 text-xs">{{ currentPlace(category) }}</span>
                                </label>
                            </li>
                        </ul>
                        <p class="text-muted mt-1.5 text-xs">
                            Desmarcada, a categoria vai para o setor padrão{{ defaultName ? ` (${defaultName})` : '' }}.
                        </p>
                        <p v-if="form.errors['category_ids.0'] || form.errors.category_ids" class="text-danger mt-1 text-sm" role="alert">{{ form.errors['category_ids.0'] ?? form.errors.category_ids }}</p>
                    </fieldset>

                    <div class="space-y-2">
                        <label class="flex cursor-pointer items-start gap-2 text-sm">
                            <input v-model="form.is_default" type="checkbox" class="mt-0.5 size-4" />
                            <span>
                                <span class="font-semibold">Setor padrão</span>
                                <span class="text-muted block text-xs">Recebe os produtos de categorias sem setor.</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input v-model="form.active" type="checkbox" class="size-4" />
                            <span class="font-semibold">Ativo</span>
                        </label>
                        <p v-if="form.errors.active" class="text-danger text-sm" role="alert">{{ form.errors.active }}</p>
                    </div>
                </div>

                <div class="border-line flex justify-end gap-2 border-t px-5 py-3">
                    <button type="button" class="btn btn-secondary" @click="editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar setor</button>
                </div>
            </form>
        </dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Excluir o setor ${removing?.name ?? ''}?`"
            message="Só dá para excluir setor sem categorias, que não seja o padrão e que nunca recebeu pedidos. Se já usou, desative."
            confirm-label="Excluir"
            cancel-label="Voltar"
            danger
            :processing="removeForm.processing"
            @confirm="remove"
            @cancel="removing = null"
        >
            <p v-if="removeForm.errors.sector" class="text-danger mt-3 text-sm" role="alert">{{ removeForm.errors.sector }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>
