<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecipeEditor from '@/components/RecipeEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { recipeFormFor, recipePayload, recipeRows } from '@/lib/recipeForm';
import { formatMoney } from '@/lib/format';
import { Pause, Play, Plus, Trash2 } from '@lucide/vue';

const props = defineProps({
    groups: { type: Array, required: true },
    products: { type: Array, required: true },
    categories: { type: Array, default: () => [] },
});

const blankOption = () => ({ id: null, name: '', price: '', active: true, recipe: [] });
const blankGroup = () => ({
    name: '',
    min_choices: 0,
    max_choices: 1,
    allows_quantity: false,
    active: true,
    product_ids: [],
    category_ids: [],
    options: [blankOption()],
});

const editingId = ref(null);
const editing = ref(false);
const productSearch = ref('');
const form = useForm(blankGroup());

const visibleProducts = computed(() => {
    const term = productSearch.value.trim().toLowerCase();

    return props.products.filter((product) => form.product_ids.includes(product.id) || product.name.toLowerCase().includes(term));
});

function startNew() {
    editingId.value = null;
    editing.value = true;
    form.defaults(blankGroup()).reset();
    form.clearErrors();
}

function startEdit(group) {
    editingId.value = group.id;
    editing.value = true;
    form.defaults({
        name: group.name,
        min_choices: group.min_choices,
        max_choices: group.max_choices,
        allows_quantity: group.allows_quantity,
        active: group.active,
        product_ids: [...group.product_ids],
        category_ids: [...group.category_ids],
        options: group.options
            .filter((option) => option.active)
            .map((option) => ({ id: option.id, name: option.name, price: option.price, active: option.active, recipe: recipeRows(option.recipe) })),
    }).reset();
    form.clearErrors();
}

function toggleCategory(id) {
    form.category_ids = form.category_ids.includes(id) ? form.category_ids.filter((item) => item !== id) : [...form.category_ids, id];
}

function toggleProduct(id) {
    form.product_ids = form.product_ids.includes(id) ? form.product_ids.filter((item) => item !== id) : [...form.product_ids, id];
}

function submit() {
    form.transform((data) => ({
        ...data,
        options: data.options.map((option) => ({ ...option, recipe: recipePayload(option.recipe) })),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    };

    if (editingId.value) {
        form.put(route('options.update', editingId.value), options);
    } else {
        form.post(route('options.store'), options);
    }
}

function togglePause(option) {
    router.patch(route('options.pause', option.id), { paused: !option.paused }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Opções dos produtos">
        <PageHeader title="Opções dos produtos" subtitle="Adicionais e bordas que o cliente escolhe ao pedir.">
            <Link :href="route('products.index')" class="btn btn-secondary">Produtos</Link>
            <button type="button" class="btn btn-primary" @click="startNew">
                <Plus :size="16" />
                Novo grupo
            </button>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
            <div class="space-y-3">
                <EmptyState v-if="groups.length === 0" title="Nenhum grupo de opções" text="Crie um grupo, como Adicionais ou Borda recheada." />

                <div v-for="group in groups" :key="group.id" class="card p-4" :class="{ 'ring-brand ring-2': editing && editingId === group.id }">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ group.name }}</p>
                            <p class="text-muted text-xs">
                                Escolhe de {{ group.min_choices }} a {{ group.max_choices }} · {{ group.category_ids.length }} categoria(s) · {{ group.product_ids.length }} produto(s)
                                <span v-if="!group.active"> · inativo</span>
                            </p>
                        </div>
                        <button type="button" class="btn btn-secondary" @click="startEdit(group)">Editar</button>
                    </div>

                    <ul class="divide-line mt-3 divide-y text-sm">
                        <li v-for="option in group.options.filter((item) => item.active)" :key="option.id" class="flex items-center justify-between gap-2 py-2">
                            <span class="min-w-0 truncate" :class="{ 'text-muted line-through': option.paused }">{{ option.name }}</span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="tabular-nums">{{ formatMoney(option.price) }}</span>
                                <button
                                    type="button"
                                    class="btn btn-ghost"
                                    :aria-label="option.paused ? `Retomar ${option.name}` : `Pausar ${option.name}`"
                                    :title="option.paused ? 'Retomar' : 'Pausar'"
                                    @click="togglePause(option)"
                                >
                                    <Play v-if="option.paused" :size="16" />
                                    <Pause v-else :size="16" />
                                </button>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <form v-if="editing" class="space-y-4" @submit.prevent="submit">
                <div class="card space-y-4 p-5">
                    <h2 class="font-bold">{{ editingId ? 'Editar grupo' : 'Novo grupo' }}</h2>

                    <Field v-slot="{ id }" label="Nome do grupo" required :error="form.errors.name">
                        <input :id="id" v-model="form.name" class="input" maxlength="80" required />
                    </Field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <Field v-slot="{ id }" label="Mínimo de escolhas" required :error="form.errors.min_choices" hint="0 deixa o grupo opcional.">
                            <input :id="id" v-model.number="form.min_choices" type="number" min="0" max="50" class="input" required />
                        </Field>
                        <Field v-slot="{ id }" label="Máximo de escolhas" required :error="form.errors.max_choices">
                            <input :id="id" v-model.number="form.max_choices" type="number" min="1" max="50" class="input" required />
                        </Field>
                    </div>

                    <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                        <input v-model="form.allows_quantity" type="checkbox" class="accent-brand mt-0.5 size-4" />
                        <span>
                            <span class="block font-semibold">Permitir quantidade</span>
                            <span class="text-muted">O cliente pode pedir a mesma opção mais de uma vez, como 2 bacons.</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                        <input v-model="form.active" type="checkbox" class="accent-brand size-4" />
                        <span class="font-semibold">Grupo ativo</span>
                    </label>
                </div>

                <div class="card space-y-4 p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-bold">Opções</h2>
                        <button type="button" class="btn btn-secondary" @click="form.options.push(blankOption())">
                            <Plus :size="16" />
                            Adicionar opção
                        </button>
                    </div>
                    <p v-if="form.errors.options" class="text-danger text-sm" role="alert">{{ form.errors.options }}</p>

                    <div v-for="(option, index) in form.options" :key="option.id ?? `new-${index}`" class="border-line space-y-3 rounded-lg border p-4">
                        <div class="grid items-start gap-3 sm:grid-cols-[1fr_9rem_auto]">
                            <Field v-slot="{ id }" label="Nome" required :error="form.errors[`options.${index}.name`]">
                                <input :id="id" v-model="option.name" class="input" maxlength="80" required />
                            </Field>
                            <Field v-slot="{ id }" label="Preço (R$)" required :error="form.errors[`options.${index}.price`]">
                                <DecimalInput :id="id" v-model="option.price" pad required />
                            </Field>
                            <div class="sm:pt-7">
                                <button type="button" class="btn btn-ghost" :aria-label="`Remover ${option.name || 'opção'}`" title="Remover" @click="form.options.splice(index, 1)">
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </div>

                        <RecipeEditor
                            :form="recipeFormFor(form, option.recipe, `options.${index}.recipe`)"
                            title="Ficha da opção"
                            description="Insumos baixados do estoque a cada unidade vendida desta opção."
                            empty-text="Sem ficha, a opção só soma no preço."
                            :summary="false"
                        />
                    </div>
                </div>

                <div class="card space-y-3 p-5">
                    <div>
                        <h2 class="font-bold">Categorias com este grupo</h2>
                        <p class="text-muted text-sm">Vale para todos os produtos da categoria, inclusive os cadastrados depois.</p>
                    </div>
                    <p v-if="form.errors.category_ids" class="text-danger text-sm" role="alert">{{ form.errors.category_ids }}</p>
                    <p v-if="categories.length === 0" class="text-muted text-sm">Nenhuma categoria cadastrada.</p>
                    <ul v-else class="grid max-h-64 gap-1 overflow-y-auto sm:grid-cols-2">
                        <li v-for="category in categories" :key="category.id">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                                <input type="checkbox" class="accent-brand size-4" :checked="form.category_ids.includes(category.id)" @change="toggleCategory(category.id)" />
                                {{ category.name }}
                            </label>
                        </li>
                    </ul>
                </div>

                <div class="card space-y-3 p-5">
                    <div>
                        <h2 class="font-bold">Outros produtos com este grupo</h2>
                        <p class="text-muted text-sm">Para produtos avulsos, fora das categorias marcadas.</p>
                    </div>
                    <input v-model="productSearch" class="input" placeholder="Buscar produto" aria-label="Buscar produto" />
                    <p v-if="form.errors.product_ids" class="text-danger text-sm" role="alert">{{ form.errors.product_ids }}</p>
                    <ul class="max-h-64 space-y-1 overflow-y-auto">
                        <li v-for="product in visibleProducts" :key="product.id">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                                <input type="checkbox" class="accent-brand size-4" :checked="form.product_ids.includes(product.id)" @change="toggleProduct(product.id)" />
                                {{ product.name }}
                            </label>
                        </li>
                    </ul>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                    <button type="button" class="btn btn-secondary" @click="editing = false">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
