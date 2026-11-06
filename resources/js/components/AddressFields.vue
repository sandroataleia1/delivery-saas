<script setup>
import { ref } from 'vue';
import Field from '@/components/Field.vue';
import { fetchAddress, formatCep } from '@/lib/format';

const props = defineProps({
    form: { type: Object, required: true },
    states: { type: Array, required: true },
    required: { type: Boolean, default: false },
});

const searching = ref(false);
const notice = ref(null);
const numberField = ref(null);

async function onCepInput(event) {
    const form = props.form;

    form.zip_code = event.target.value = formatCep(event.target.value);
    notice.value = null;

    if (form.zip_code.length !== 9) {
        return;
    }

    searching.value = true;
    const address = await fetchAddress(form.zip_code);
    searching.value = false;

    if (!address) {
        notice.value = 'Não encontramos este CEP. Preencha o endereço manualmente.';

        return;
    }

    Object.assign(form, address);
    form.clearErrors('zip_code', 'street', 'district', 'city', 'state');
    numberField.value?.focus();
}
</script>

<template>
    <div class="space-y-4">
        <Field
            v-slot="{ id }"
            label="CEP"
            :required="required"
            :error="form.errors.zip_code"
            :hint="searching ? 'Buscando endereço...' : notice"
        >
            <input
                :id="id"
                class="input"
                inputmode="numeric"
                autocomplete="postal-code"
                placeholder="00000-000"
                :value="form.zip_code"
                :required="required"
                @input="onCepInput"
            />
        </Field>

        <Field v-slot="{ id }" label="Rua" :required="required" :error="form.errors.street">
            <input :id="id" v-model="form.street" class="input" autocomplete="address-line1" :required="required" />
        </Field>

        <div class="grid grid-cols-[7rem_1fr] gap-4">
            <Field v-slot="{ id }" label="Número" :required="required" :error="form.errors.number">
                <input :id="id" ref="numberField" v-model="form.number" class="input" :required="required" />
            </Field>

            <Field v-slot="{ id }" :label="required ? 'Complemento (opcional)' : 'Complemento'" :error="form.errors.complement">
                <input :id="id" v-model="form.complement" class="input" autocomplete="address-line2" />
            </Field>
        </div>

        <Field v-slot="{ id }" label="Bairro" :required="required" :error="form.errors.district">
            <input :id="id" v-model="form.district" class="input" :required="required" />
        </Field>

        <div class="grid grid-cols-[1fr_6rem] gap-4">
            <Field v-slot="{ id }" label="Cidade" :required="required" :error="form.errors.city">
                <input :id="id" v-model="form.city" class="input" :required="required" />
            </Field>

            <Field v-slot="{ id }" label="UF" :required="required" :error="form.errors.state">
                <select :id="id" v-model="form.state" class="input" :required="required">
                    <option value="" disabled></option>
                    <option v-for="state in states" :key="state" :value="state">{{ state }}</option>
                </select>
            </Field>
        </div>
    </div>
</template>
