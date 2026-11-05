<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    decimals: { type: Number, default: 2 },
    pad: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const toText = (value) => {
    if (value === '' || value === null || value === undefined) {
        return '';
    }

    const number = Number(value);

    return (props.pad ? number.toFixed(props.decimals) : String(number)).replace('.', ',');
};

const text = ref(toText(props.modelValue));

// O formulário guarda sempre com ponto; o usuário vê e digita com vírgula.
watch(
    () => props.modelValue,
    (value) => {
        if (String(value ?? '') !== text.value.replace(',', '.')) {
            text.value = toText(value);
        }
    },
);

function onInput(event) {
    const [whole, ...rest] = event.target.value.replace(/\./g, ',').replace(/[^\d,]/g, '').split(',');
    const hasComma = rest.length > 0;

    text.value = hasComma ? `${whole},${rest.join('').slice(0, props.decimals)}` : whole;
    event.target.value = text.value;

    emit('update:modelValue', text.value.replace(',', '.'));
}

function onBlur() {
    if (props.pad && text.value !== '') {
        text.value = toText(text.value.replace(',', '.'));
    }
}
</script>

<template>
    <input class="input tabular-nums" inputmode="decimal" :value="text" @input="onInput" @blur="onBlur" />
</template>
