<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import RecipeEditor from '@/components/RecipeEditor.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney, formatQuantity } from '@/lib/format';

const props = defineProps({
    product: { type: Object, default: null },
    categories: { type: Array, required: true },
    units: { type: Object, required: true },
});

const editing = computed(() => props.product !== null);

const form = useForm({
    name: props.product?.name ?? '',
    category_id: props.product?.category_id ?? '',
    sku: props.product?.sku ?? '',
    barcode: props.product?.barcode ?? '',
    unit: props.product?.unit ?? 'UN',
    sale_price: props.product?.sale_price ?? '',
    cost_price: props.product?.cost_price ?? '',
    track_stock: props.product?.track_stock ?? true,
    sellable: props.product?.sellable ?? true,
    min_stock: props.product?.min_stock ?? '',
    initial_stock: '',
    recipe: (props.product?.recipe ?? []).map((item) => ({ ...item, quantity: String(Number(item.quantity)) })),
});

// Com estoque controlado, depois de criado o custo só muda pelas entradas.
const costIsCalculated = computed(() => editing.value && form.track_stock && props.product.track_stock);
const hasRecipe = computed(() => !form.track_stock && form.recipe.length > 0);

function submit() {
    form.transform((data) => ({
        ...data,
        recipe: data.track_stock
            ? []
            : data.recipe.map(({ ingredient_id, quantity }) => ({ ingredient_id, quantity })),
    }));

    if (editing.value) {
        form.put(route('products.update', props.product.id));
    } else {
        form.post(route('products.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar produto' : 'Novo produto'">
        <PageHeader :title="editing ? 'Editar produto' : 'Novo produto'" />

        <form class="max-w-xl space-y-4" @submit.prevent="submit">
            <div class="card space-y-4 p-5">
                <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                    <input :id="id" v-model="form.name" class="input" maxlength="120" required autofocus />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Categoria" :error="form.errors.category_id">
                        <select :id="id" v-model="form.category_id" class="input">
                            <option value="">Sem categoria</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </Field>

                    <Field v-slot="{ id }" label="Unidade" required :error="form.errors.unit">
                        <select :id="id" v-model="form.unit" class="input" required>
                            <option v-for="(label, code) in units" :key="code" :value="code">{{ code }} · {{ label }}</option>
                        </select>
                    </Field>

                    <Field v-slot="{ id }" label="Código de barras" :error="form.errors.barcode">
                        <input :id="id" v-model="form.barcode" class="input" inputmode="numeric" maxlength="20" />
                    </Field>

                    <Field v-slot="{ id }" label="Código interno" :error="form.errors.sku">
                        <input :id="id" v-model="form.sku" class="input" maxlength="40" />
                    </Field>
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Preços</h2>

                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.sellable" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Disponível para venda</span>
                        <span class="text-muted">Desmarque para insumos, como farinha e queijo: eles ficam fora do PDV.</span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-if="form.sellable" v-slot="{ id }" label="Preço de venda (R$)" required :error="form.errors.sale_price">
                        <DecimalInput :id="id" v-model="form.sale_price" pad required />
                    </Field>

                    <div v-if="hasRecipe">
                        <p class="mb-1.5 text-sm font-semibold">Custo</p>
                        <p class="text-muted min-h-10 py-2 text-sm">Calculado pela ficha técnica.</p>
                    </div>

                    <Field v-else-if="!costIsCalculated" v-slot="{ id }" label="Custo (R$)" :error="form.errors.cost_price">
                        <DecimalInput :id="id" v-model="form.cost_price" :decimals="4" />
                    </Field>

                    <div v-else>
                        <p class="mb-1.5 text-sm font-semibold">Custo médio</p>
                        <p class="min-h-10 py-2 text-sm tabular-nums">{{ formatMoney(product.cost_price) }}</p>
                        <p class="text-muted text-xs">Calculado pelas entradas de mercadorias.</p>
                    </div>
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Estoque</h2>

                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.track_stock" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Controlar estoque deste produto</span>
                        <span class="text-muted">Desmarque para itens feitos na hora, como uma pizza, e monte a ficha técnica.</span>
                    </span>
                </label>
                <p v-if="form.errors.track_stock" class="text-danger text-sm" role="alert">{{ form.errors.track_stock }}</p>

                <div v-if="form.track_stock" class="grid gap-4 sm:grid-cols-2">
                    <Field v-if="!editing" v-slot="{ id }" label="Estoque inicial" :error="form.errors.initial_stock">
                        <DecimalInput :id="id" v-model="form.initial_stock" :decimals="3" />
                    </Field>

                    <div v-else>
                        <p class="mb-1.5 text-sm font-semibold">Saldo atual</p>
                        <p class="min-h-10 py-2 text-sm tabular-nums">{{ formatQuantity(product.stock_quantity, form.unit) }}</p>
                    </div>

                    <Field v-slot="{ id }" label="Estoque mínimo" :error="form.errors.min_stock" hint="Abaixo disso, o produto aparece como estoque baixo.">
                        <DecimalInput :id="id" v-model="form.min_stock" :decimals="3" />
                    </Field>
                </div>
            </div>

            <RecipeEditor v-if="!form.track_stock" :form="form" />

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('products.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
