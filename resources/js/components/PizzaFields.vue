<script setup>
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import { newSizeKey } from '@/lib/pizzaForm';
import { Plus, Trash2 } from '@lucide/vue';

const props = defineProps({
    form: { type: Object, required: true },
});

const error = (path) => props.form.errors[`pizza.${path}`];

function addSize() {
    const key = newSizeKey();

    props.form.pizza.sizes.push({ key, id: null, name: '', slices: '', max_flavors: 1, active: true });
    props.form.pizza.flavors.forEach((flavor) => {
        flavor.prices[key] = '';
    });
}

function addFlavor() {
    props.form.pizza.flavors.push({
        id: null,
        name: '',
        active: true,
        prices: Object.fromEntries(props.form.pizza.sizes.map((size) => [size.key, ''])),
    });
}
</script>

<template>
    <div class="space-y-4">
        <Field v-slot="{ id }" label="Preço da pizza com mais de um sabor" required :error="error('pizza_pricing')" hint="O preço de cada sabor é o da pizza inteira naquele tamanho.">
            <select :id="id" v-model="form.pizza.pizza_pricing" class="input">
                <option value="highest">Cobrar o sabor mais caro</option>
                <option value="average">Cobrar a média dos sabores</option>
            </select>
        </Field>

        <div class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold">Tamanhos</h3>
                <button type="button" class="btn btn-secondary" @click="addSize">
                    <Plus :size="16" />
                    Adicionar tamanho
                </button>
            </div>
            <p v-if="error('sizes')" class="text-danger text-sm" role="alert">{{ error('sizes') }}</p>
            <p v-if="form.pizza.sizes.length === 0" class="text-muted text-sm">Ex.: Pequena (4 fatias, 1 sabor), Média (6 fatias, 2 sabores), Grande (8 fatias, 3 sabores).</p>

            <div v-for="(size, index) in form.pizza.sizes" :key="size.key" class="border-line grid items-start gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_5rem_6.5rem_auto_auto]">
                <Field v-slot="{ id }" label="Nome" required :error="error(`sizes.${index}.name`) ?? error(`sizes.${index}.id`)">
                    <input :id="id" v-model="size.name" class="input" maxlength="40" required placeholder="Ex.: Grande" />
                </Field>
                <Field v-slot="{ id }" label="Fatias" :error="error(`sizes.${index}.slices`)">
                    <input :id="id" v-model.number="size.slices" type="number" min="1" max="60" class="input" />
                </Field>
                <Field v-slot="{ id }" label="Máx. sabores" required :error="error(`sizes.${index}.max_flavors`)">
                    <input :id="id" v-model.number="size.max_flavors" type="number" min="1" max="4" class="input" required />
                </Field>
                <label class="flex items-center gap-2 text-sm sm:pt-8"><input v-model="size.active" type="checkbox" class="accent-brand size-4" />Ativo</label>
                <div class="sm:pt-7">
                    <button type="button" class="btn btn-ghost" :aria-label="`Remover ${size.name || 'tamanho'}`" title="Remover" @click="form.pizza.sizes.splice(index, 1)">
                        <Trash2 :size="16" />
                    </button>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold">Sabores</h3>
                <button type="button" class="btn btn-secondary" :disabled="form.pizza.sizes.length === 0" @click="addFlavor">
                    <Plus :size="16" />
                    Adicionar sabor
                </button>
            </div>
            <p v-if="error('flavors')" class="text-danger text-sm" role="alert">{{ error('flavors') }}</p>
            <p v-if="form.pizza.sizes.length === 0" class="text-muted text-sm">Cadastre os tamanhos primeiro: cada sabor tem um preço por tamanho.</p>

            <div v-for="(flavor, index) in form.pizza.flavors" :key="flavor.id ?? `new-${index}`" class="border-line space-y-3 rounded-lg border p-3">
                <div class="grid items-start gap-3 sm:grid-cols-[1fr_auto_auto]">
                    <Field v-slot="{ id }" label="Nome do sabor" required :error="error(`flavors.${index}.name`) ?? error(`flavors.${index}.id`)">
                        <input :id="id" v-model="flavor.name" class="input" maxlength="80" required placeholder="Ex.: Calabresa" />
                    </Field>
                    <label class="flex items-center gap-2 text-sm sm:pt-8"><input v-model="flavor.active" type="checkbox" class="accent-brand size-4" />Ativo</label>
                    <div class="sm:pt-7">
                        <button type="button" class="btn btn-ghost" :aria-label="`Remover ${flavor.name || 'sabor'}`" title="Remover" @click="form.pizza.flavors.splice(index, 1)">
                            <Trash2 :size="16" />
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <Field v-for="size in form.pizza.sizes" :key="size.key" v-slot="{ id }" :label="`${size.name || 'Tamanho'} (R$)`">
                        <DecimalInput :id="id" v-model="flavor.prices[size.key]" pad />
                    </Field>
                </div>
                <p v-if="error(`flavors.${index}.prices`)" class="text-danger text-sm" role="alert">{{ error(`flavors.${index}.prices`) }}</p>
            </div>
        </div>
    </div>
</template>
