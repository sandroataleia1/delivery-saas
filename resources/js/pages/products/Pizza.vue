<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecipeEditor from '@/components/RecipeEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { recipeFormFor, recipePayload, recipeRows } from '@/lib/recipeForm';
import { Plus, Trash2 } from '@lucide/vue';

const props = defineProps({
    product: { type: Object, required: true },
    sizes: { type: Array, required: true },
    flavors: { type: Array, required: true },
    extras: { type: Array, required: true },
});

let counter = 0;
const nextKey = () => `k${counter++}`;

// "key" identifica o tamanho só na tela; no envio vira o id ou o índice do tamanho novo.
const sizeRows = props.sizes.map((size) => ({
    key: String(size.id),
    id: size.id,
    name: size.name,
    slices: size.slices ?? '',
    max_flavors: size.max_flavors,
    active: size.active,
    recipe: recipeRows(size.recipe),
}));

const flavorRows = props.flavors.map((flavor) => ({
    id: flavor.id,
    name: flavor.name,
    active: flavor.active,
    prices: Object.fromEntries(sizeRows.map((size) => [size.key, flavor.prices[size.id] ?? ''])),
    recipes: Object.fromEntries(sizeRows.map((size) => [size.key, recipeRows(flavor.recipes[size.id])])),
}));

const extraPrices = {};
props.extras.forEach((group) => group.options.forEach((option) => {
    extraPrices[option.id] = Object.fromEntries(sizeRows.map((size) => [size.key, option.prices[size.id] ?? '']));
}));

const form = useForm({
    pizza_pricing: props.product.pizza_pricing ?? 'highest',
    sizes: sizeRows,
    flavors: flavorRows,
    size_prices: extraPrices,
});

function addSize() {
    const key = nextKey();

    form.sizes.push({ key, id: null, name: '', slices: '', max_flavors: 1, active: true, recipe: [] });
    form.flavors.forEach((flavor) => {
        flavor.prices[key] = '';
        flavor.recipes[key] = [];
    });
    Object.values(form.size_prices).forEach((prices) => {
        prices[key] = '';
    });
}

function addFlavor() {
    form.flavors.push({
        id: null,
        name: '',
        active: true,
        prices: Object.fromEntries(form.sizes.map((size) => [size.key, ''])),
        recipes: Object.fromEntries(form.sizes.map((size) => [size.key, []])),
    });
}

function submit() {
    form.transform((data) => {
        const keyOf = Object.fromEntries(data.sizes.map((size, index) => [size.key, size.id ? String(size.id) : `n${index}`]));
        const remap = (values, convert = (value) => value) => Object.fromEntries(
            Object.entries(values).filter(([key]) => keyOf[key] !== undefined).map(([key, value]) => [keyOf[key], convert(value)]),
        );
        const filled = (values) => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== ''));

        return {
            pizza_pricing: data.pizza_pricing,
            sizes: data.sizes.map((size) => ({
                ...(size.id ? { id: size.id } : {}),
                name: size.name,
                slices: size.slices === '' ? null : size.slices,
                max_flavors: size.max_flavors,
                active: size.active,
                recipe: recipePayload(size.recipe),
            })),
            flavors: data.flavors.map((flavor) => ({
                ...(flavor.id ? { id: flavor.id } : {}),
                name: flavor.name,
                active: flavor.active,
                prices: remap(filled(flavor.prices)),
                recipes: remap(flavor.recipes, recipePayload),
            })),
            size_prices: Object.fromEntries(
                Object.entries(data.size_prices).map(([optionId, prices]) => [optionId, remap(prices, (value) => (value === '' ? null : value))]),
            ),
        };
    });

    form.put(route('products.pizza.update', props.product.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="`Pizza · ${product.name}`">
        <PageHeader :title="`Pizza · ${product.name}`" subtitle="Tamanhos, sabores, preços e fichas.">
            <Link :href="route('products.edit', product.id)" class="btn btn-secondary">Voltar ao produto</Link>
        </PageHeader>

        <form class="max-w-4xl space-y-4" @submit.prevent="submit">
            <div class="card space-y-3 p-5">
                <Field v-slot="{ id }" label="Preço da pizza com mais de um sabor" required :error="form.errors.pizza_pricing" hint="O preço de cada sabor é o da pizza inteira naquele tamanho.">
                    <select :id="id" v-model="form.pizza_pricing" class="input">
                        <option value="highest">Cobrar o sabor mais caro</option>
                        <option value="average">Cobrar a média dos sabores</option>
                    </select>
                </Field>
            </div>

            <div class="card space-y-4 p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-bold">Tamanhos</h2>
                    <button type="button" class="btn btn-secondary" @click="addSize">
                        <Plus :size="16" />
                        Adicionar tamanho
                    </button>
                </div>
                <p v-if="form.errors.sizes" class="text-danger text-sm" role="alert">{{ form.errors.sizes }}</p>

                <div v-for="(size, index) in form.sizes" :key="size.key" class="border-line space-y-3 rounded-lg border p-4">
                    <div class="grid items-start gap-3 sm:grid-cols-[1fr_6rem_7rem_auto_auto]">
                        <Field v-slot="{ id }" label="Nome" required :error="form.errors[`sizes.${index}.name`]">
                            <input :id="id" v-model="size.name" class="input" maxlength="40" required />
                        </Field>
                        <Field v-slot="{ id }" label="Fatias" :error="form.errors[`sizes.${index}.slices`]">
                            <input :id="id" v-model.number="size.slices" type="number" min="1" max="60" class="input" />
                        </Field>
                        <Field v-slot="{ id }" label="Máx. sabores" required :error="form.errors[`sizes.${index}.max_flavors`]">
                            <input :id="id" v-model.number="size.max_flavors" type="number" min="1" max="4" class="input" required />
                        </Field>
                        <label class="flex items-center gap-2 text-sm sm:pt-8"><input v-model="size.active" type="checkbox" class="accent-brand size-4" />Ativo</label>
                        <div class="sm:pt-7">
                            <button type="button" class="btn btn-ghost" :aria-label="`Remover ${size.name || 'tamanho'}`" title="Remover" @click="form.sizes.splice(index, 1)">
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>

                    <RecipeEditor
                        :form="recipeFormFor(form, size.recipe, `sizes.${index}.recipe`)"
                        title="Ficha base do tamanho"
                        description="Massa, caixa e demais insumos de uma pizza deste tamanho, qualquer que seja o sabor."
                        empty-text="Sem ficha base, o tamanho não baixa estoque."
                        :summary="false"
                    />
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-bold">Sabores</h2>
                    <button type="button" class="btn btn-secondary" :disabled="form.sizes.length === 0" @click="addFlavor">
                        <Plus :size="16" />
                        Adicionar sabor
                    </button>
                </div>

                <div v-for="(flavor, index) in form.flavors" :key="flavor.id ?? `new-${index}`" class="border-line space-y-3 rounded-lg border p-4">
                    <div class="grid items-start gap-3 sm:grid-cols-[1fr_auto_auto]">
                        <Field v-slot="{ id }" label="Nome do sabor" required :error="form.errors[`flavors.${index}.name`]">
                            <input :id="id" v-model="flavor.name" class="input" maxlength="80" required />
                        </Field>
                        <label class="flex items-center gap-2 text-sm sm:pt-8"><input v-model="flavor.active" type="checkbox" class="accent-brand size-4" />Ativo</label>
                        <div class="sm:pt-7">
                            <button type="button" class="btn btn-ghost" :aria-label="`Remover ${flavor.name || 'sabor'}`" title="Remover" @click="form.flavors.splice(index, 1)">
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Field v-for="size in form.sizes" :key="size.key" v-slot="{ id }" :label="`Preço ${size.name || 'do tamanho'} (R$)`">
                            <DecimalInput :id="id" v-model="flavor.prices[size.key]" pad />
                        </Field>
                    </div>
                    <p v-if="form.errors[`flavors.${index}.prices`]" class="text-danger text-sm" role="alert">{{ form.errors[`flavors.${index}.prices`] }}</p>

                    <details v-for="(size, sizeIndex) in form.sizes" :key="size.key" class="border-line rounded-lg border p-3">
                        <summary class="cursor-pointer text-sm font-semibold">Ficha de {{ flavor.name || 'sabor' }} · {{ size.name || `tamanho ${sizeIndex + 1}` }}</summary>
                        <div class="mt-3">
                            <RecipeEditor
                                :form="recipeFormFor(form, flavor.recipes[size.key], `flavors.${index}.recipes.${size.id ?? `n${sizeIndex}`}`)"
                                title="Ficha do sabor"
                                description="Insumos do sabor numa pizza inteira deste tamanho. Com mais de um sabor, cada um baixa a fração correspondente."
                                empty-text="Sem ficha, o sabor só soma no preço."
                                :summary="false"
                            />
                        </div>
                    </details>
                </div>
            </div>

            <div v-for="group in extras" :key="group.id" class="card space-y-4 p-5">
                <div>
                    <h2 class="font-bold">{{ group.name }} · preço por tamanho</h2>
                    <p class="text-muted mt-1 text-sm">Deixe em branco para usar o preço padrão da opção.</p>
                </div>
                <div v-for="option in group.options" :key="option.id" class="grid items-end gap-3 sm:grid-cols-[1fr_repeat(3,8rem)]">
                    <p class="text-sm font-semibold">{{ option.name }}</p>
                    <Field v-for="size in form.sizes.filter((item) => form.size_prices[option.id]?.[item.key] !== undefined)" :key="size.key" v-slot="{ id }" :label="size.name || 'Tamanho'">
                        <DecimalInput :id="id" v-model="form.size_prices[option.id][size.key]" pad />
                    </Field>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('products.edit', product.id)" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
