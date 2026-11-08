<script setup>
import { computed } from 'vue';
import DecimalInput from '@/components/DecimalInput.vue';
import EmptyState from '@/components/EmptyState.vue';
import Field from '@/components/Field.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import { formatMoney, formatQuantity } from '@/lib/format';
import { Trash2 } from '@lucide/vue';

const props = defineProps({
    form: { type: Object, required: true },
    title: { type: String, default: 'Ficha técnica' },
    description: { type: String, default: 'Insumos usados em uma unidade do produto. Cada venda baixa essas quantidades do estoque.' },
    emptyText: { type: String, default: 'Sem ficha técnica, a venda deste produto não baixa estoque.' },
    // Fichas de opções e tamanhos não têm preço de venda próprio: sem custo, margem e porções.
    summary: { type: Boolean, default: true },
});

const lineCost = (item) => Number(item.quantity || 0) * Number(item.cost_price || 0);

// Arredonda em centavos para a margem fechar com os valores que aparecem na tela.
const cost = computed(() => {
    const sum = props.form.recipe.reduce((total, item) => total + lineCost(item), 0);

    return Math.round(Number((sum * 100).toFixed(4))) / 100;
});
const price = computed(() => Number(props.form.sale_price || 0));
const margin = computed(() => price.value - cost.value);
const marginPercent = computed(() => (price.value > 0 ? (margin.value / price.value) * 100 : 0));

const portions = computed(() => {
    const filled = props.form.recipe.filter((item) => Number(item.quantity) > 0);

    return filled.length === 0
        ? null
        : Math.min(...filled.map((item) => Math.floor(Number(item.stock_quantity) / Number(item.quantity))));
});

function addIngredient(product) {
    if (props.form.recipe.some((item) => item.ingredient_id === product.id)) {
        return;
    }

    props.form.recipe.push({
        ingredient_id: product.id,
        name: product.name,
        unit: product.unit,
        cost_price: product.cost_price,
        stock_quantity: product.stock_quantity,
        quantity: '',
    });
}
</script>

<template>
    <div class="card space-y-4 p-5">
        <div>
            <h2 class="font-bold">{{ title }}</h2>
            <p class="text-muted mt-1 text-sm">{{ description }}</p>
        </div>

        <ProductPicker placeholder="Buscar insumo por nome ou código de barras" label="Buscar insumo" @select="addIngredient" />

        <p v-if="form.errors.recipe" class="text-danger text-sm" role="alert">{{ form.errors.recipe }}</p>

        <EmptyState v-if="form.recipe.length === 0" title="Nenhum insumo adicionado" :text="emptyText" />

        <ul v-else class="divide-line divide-y">
            <li
                v-for="(item, index) in form.recipe"
                :key="item.ingredient_id"
                class="grid grid-cols-[1fr_auto] items-start gap-x-3 gap-y-2 py-3 sm:grid-cols-[1fr_8rem_5.5rem_2.5rem]"
            >
                <div class="min-w-0 sm:pt-7">
                    <p class="truncate text-sm font-semibold">{{ item.name }}</p>
                    <p class="text-muted text-xs tabular-nums">Saldo: {{ formatQuantity(item.stock_quantity, item.unit) }}</p>
                    <p v-if="form.errors[`recipe.${index}.ingredient_id`]" class="text-danger text-sm" role="alert">
                        {{ form.errors[`recipe.${index}.ingredient_id`] }}
                    </p>
                </div>

                <div class="row-start-2 sm:row-start-auto">
                    <Field v-slot="{ id }" :label="`Qtd. (${item.unit})`" required :error="form.errors[`recipe.${index}.quantity`]">
                        <DecimalInput :id="id" v-model="item.quantity" :decimals="3" required />
                    </Field>
                </div>

                <p class="row-start-2 pt-9 text-right text-sm font-semibold whitespace-nowrap tabular-nums sm:row-start-auto">{{ formatMoney(lineCost(item)) }}</p>

                <div class="col-start-2 row-start-1 flex justify-end sm:col-start-auto sm:row-start-auto sm:pt-7">
                    <button type="button" class="btn btn-ghost" :aria-label="`Remover ${item.name}`" title="Remover" @click="form.recipe.splice(index, 1)">
                        <Trash2 :size="16" />
                    </button>
                </div>
            </li>
        </ul>

        <dl v-if="summary && form.recipe.length > 0" class="border-line grid grid-cols-2 gap-3 border-t pt-4 text-sm lg:grid-cols-4">
            <div>
                <dt class="text-muted">Custo da ficha</dt>
                <dd class="font-bold tabular-nums">{{ formatMoney(cost) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Preço de venda</dt>
                <dd class="font-bold tabular-nums">{{ formatMoney(price) }}</dd>
            </div>
            <div>
                <dt class="text-muted">Margem</dt>
                <dd class="font-bold tabular-nums" :class="margin < 0 ? 'text-danger' : 'text-ok'">
                    {{ formatMoney(margin) }} ({{ marginPercent.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) }}%)
                </dd>
            </div>
            <div>
                <dt class="text-muted">Dá para fazer</dt>
                <dd class="font-bold tabular-nums">{{ portions === null ? '—' : `${portions} ${portions === 1 ? 'unidade' : 'unidades'}` }}</dd>
            </div>
        </dl>
    </div>
</template>
