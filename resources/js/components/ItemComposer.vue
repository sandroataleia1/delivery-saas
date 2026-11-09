<script setup>
import { computed, ref } from 'vue';
import { Minus, Plus } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { label as compositionLabel, missing as compositionMissing, priceCents } from '@/lib/composition';
import { formatMoney } from '@/lib/format';
import { fromCents } from '@/lib/money';

const props = defineProps({
    product: { type: Object, required: true },
    composition: { type: Object, required: true },
    withNotes: { type: Boolean, default: true },
    // Vindo da lista por tamanho: abre com o tamanho e o sabor tocados já marcados.
    initialSizeId: { type: Number, default: null },
    initialFlavorId: { type: Number, default: null },
});

const emit = defineEmits(['add', 'cancel']);

const MAX_OPTION_QUANTITY = 10;

const sizeId = ref(props.initialSizeId);
const flavorIds = ref(props.initialFlavorId ? [props.initialFlavorId] : []);
const quantities = ref({});
const notes = ref('');

const selection = computed(() => ({
    size_id: sizeId.value,
    flavor_ids: flavorIds.value,
    options: Object.entries(quantities.value)
        .filter(([, quantity]) => quantity > 0)
        .map(([optionId, quantity]) => ({ option_id: Number(optionId), quantity })),
}));

const pizza = computed(() => props.composition.pizza);
const size = computed(() => pizza.value?.sizes.find((item) => item.id === sizeId.value) ?? null);
const problems = computed(() => compositionMissing(props.composition, selection.value));
const unitCents = computed(() => priceCents(props.product, props.composition, selection.value));
const preview = computed(() => compositionLabel(props.composition, selection.value));

const flavorPrice = (flavor) => (size.value ? flavor.prices?.[size.value.id] ?? null : null);
const optionPrice = (option) => (size.value ? option.prices?.[size.value.id] : null) ?? option.price;
const flavorBlocked = (flavor) => flavor.paused || (size.value !== null && flavorPrice(flavor) === null);
const groupTotal = (group) => group.options.reduce((sum, option) => sum + (quantities.value[option.id] ?? 0), 0);

function toggleFlavor(flavor) {
    if (flavorIds.value.includes(flavor.id)) {
        flavorIds.value = flavorIds.value.filter((id) => id !== flavor.id);
    } else if (!flavorBlocked(flavor) && flavorIds.value.length < (size.value?.max_flavors ?? 1)) {
        flavorIds.value = [...flavorIds.value, flavor.id];
    }
}

function chooseSize(item) {
    sizeId.value = item.id;
    flavorIds.value = flavorIds.value.filter((id) => {
        const flavor = pizza.value.flavors.find((candidate) => candidate.id === id);

        return flavor && flavor.prices?.[item.id] !== undefined;
    }).slice(0, item.max_flavors);
}

function toggleOption(group, option) {
    if (quantities.value[option.id]) {
        quantities.value = { ...quantities.value, [option.id]: 0 };

        return;
    }

    if (option.paused) {
        return;
    }

    if (group.max === 1) {
        const cleared = Object.fromEntries(group.options.map((item) => [item.id, 0]));
        quantities.value = { ...quantities.value, ...cleared, [option.id]: 1 };
    } else if (groupTotal(group) < group.max) {
        quantities.value = { ...quantities.value, [option.id]: 1 };
    }
}

function stepOption(group, option, delta) {
    const next = (quantities.value[option.id] ?? 0) + delta;

    if (next < 0 || (delta > 0 && groupTotal(group) >= group.max) || next > MAX_OPTION_QUANTITY) {
        return;
    }

    quantities.value = { ...quantities.value, [option.id]: next };
}

function confirm() {
    if (problems.value.length > 0) {
        return;
    }

    emit('add', {
        selection: { size_id: sizeId.value, flavor_ids: [...flavorIds.value], options: selection.value.options },
        unitCents: unitCents.value,
        label: preview.value,
        notes: notes.value.trim(),
    });
}
</script>

<template>
    <ConfirmDialog
        :open="true"
        :title="product.name"
        confirm-label="Adicionar"
        :disabled="problems.length > 0"
        @confirm="confirm"
        @cancel="emit('cancel')"
    >
        <div class="mt-4 max-h-[60dvh] space-y-4 overflow-y-auto">
            <fieldset v-if="pizza">
                <legend class="mb-1.5 text-sm font-semibold">Tamanho</legend>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="item in pizza.sizes"
                        :key="item.id"
                        type="button"
                        class="btn"
                        :class="sizeId === item.id ? 'btn-primary' : 'btn-secondary'"
                        :aria-pressed="sizeId === item.id"
                        @click="chooseSize(item)"
                    >
                        {{ item.name }} <span class="text-xs opacity-80">{{ item.slices }} fatias</span>
                    </button>
                </div>
            </fieldset>

            <fieldset v-if="pizza">
                <legend class="mb-1.5 text-sm font-semibold">
                    Sabores <span v-if="size" class="text-muted font-normal">(até {{ size.max_flavors }})</span>
                </legend>
                <p v-if="!size" class="text-muted text-sm">Escolha o tamanho para ver os preços.</p>
                <ul class="space-y-1">
                    <li v-for="flavor in pizza.flavors" :key="flavor.id">
                        <label class="flex cursor-pointer items-center gap-2 text-sm" :class="{ 'opacity-50': flavorBlocked(flavor) && !flavorIds.includes(flavor.id) }">
                            <input
                                type="checkbox"
                                class="accent-brand size-4"
                                :checked="flavorIds.includes(flavor.id)"
                                :disabled="flavorBlocked(flavor) && !flavorIds.includes(flavor.id)"
                                @change="toggleFlavor(flavor)"
                            />
                            <span class="min-w-0 flex-1 break-words">{{ flavor.name }}<span v-if="flavor.paused" class="text-danger"> · Pausado</span></span>
                            <span v-if="flavorPrice(flavor) !== null" class="text-muted tabular-nums">{{ formatMoney(flavorPrice(flavor)) }}</span>
                        </label>
                    </li>
                </ul>
            </fieldset>

            <fieldset v-for="group in composition.groups" :key="group.id">
                <legend class="mb-1.5 text-sm font-semibold">
                    {{ group.name }}
                    <span class="text-muted font-normal">({{ group.min >= 1 ? 'obrigatório' : 'opcional' }}{{ group.max > 1 ? `, até ${group.max}` : '' }})</span>
                </legend>
                <ul class="space-y-1">
                    <li v-for="option in group.options" :key="option.id" class="flex items-center gap-2 text-sm" :class="{ 'opacity-50': option.paused && !quantities[option.id] }">
                        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                            <input
                                :type="group.max === 1 ? 'radio' : 'checkbox'"
                                :name="`group-${group.id}`"
                                class="accent-brand size-4"
                                :checked="Boolean(quantities[option.id])"
                                :disabled="option.paused && !quantities[option.id]"
                                @click.prevent="toggleOption(group, option)"
                            />
                            <span class="min-w-0 flex-1 break-words">{{ option.name }}<span v-if="option.paused" class="text-danger"> · Pausado</span></span>
                        </label>
                        <span class="text-muted tabular-nums">+ {{ formatMoney(optionPrice(option)) }}</span>
                        <span v-if="group.allows_quantity && group.max > 1 && quantities[option.id]" class="flex items-center gap-1">
                            <button type="button" class="btn btn-secondary size-8 min-h-8 px-0" :aria-label="`Diminuir ${option.name}`" @click="stepOption(group, option, -1)"><Minus :size="14" /></button>
                            <span class="w-5 text-center font-bold tabular-nums">{{ quantities[option.id] }}</span>
                            <button type="button" class="btn btn-secondary size-8 min-h-8 px-0" :aria-label="`Aumentar ${option.name}`" @click="stepOption(group, option, 1)"><Plus :size="14" /></button>
                        </span>
                    </li>
                </ul>
            </fieldset>

            <div v-if="withNotes">
                <label for="composer-notes" class="mb-1.5 block text-sm font-semibold">Observação</label>
                <input id="composer-notes" v-model="notes" class="input" maxlength="120" placeholder="Sem cebola, bem passada..." />
            </div>

            <p v-if="preview" class="text-muted text-sm break-words">{{ preview }}</p>
            <ul v-if="problems.length" class="text-danger space-y-0.5 text-sm" role="alert">
                <li v-for="message in problems" :key="message">{{ message }}</li>
            </ul>
            <p class="text-right text-lg font-bold tabular-nums">{{ formatMoney(fromCents(unitCents)) }}</p>
        </div>
    </ConfirmDialog>
</template>
