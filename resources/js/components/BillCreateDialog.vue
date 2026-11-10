<script setup>
import { computed, nextTick, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import { formatMoney } from '@/lib/format';

/** Nova conta a pagar ou a receber, sem sair da lista. A edição continua na tela própria. */
const props = defineProps({
    type: { type: String, required: true },
    categories: { type: Array, required: true },
    suppliers: { type: Array, default: () => [] },
});

const isPayable = computed(() => props.type === 'pagar');
const dialog = ref(null);
const descriptionInput = ref(null);
const blank = { description: '', category: '', supplier_id: '', counterparty: '', amount: '', due_date: '', installments: 1, notes: '' };
const form = useForm({ ...blank });
const installmentValue = computed(() => Number(form.amount || 0) / Math.max(Number(form.installments) || 1, 1));

async function open() {
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    Object.assign(form, blank);
    form.clearErrors();
    dialog.value?.showModal();
    await nextTick();
    descriptionInput.value?.focus();
}

function close() {
    dialog.value?.close();
}

function submit() {
    form.post(route('bills.store', props.type), { preserveScroll: true, onSuccess: close });
}

defineExpose({ open });
</script>

<template>
    <dialog
        ref="dialog"
        class="bg-surface text-ink m-auto w-[calc(100%-2rem)] max-w-xl rounded-xl p-0 shadow-xl backdrop:bg-black/40"
        aria-labelledby="bill-create-title"
        @cancel.prevent="close"
    >
        <form @submit.prevent="submit">
            <div class="max-h-[80vh] space-y-4 overflow-y-auto p-6">
                <h2 id="bill-create-title" class="text-lg font-bold">{{ isPayable ? 'Nova conta a pagar' : 'Nova conta a receber' }}</h2>

                <Field v-slot="{ id }" label="Descrição" required :error="form.errors.description">
                    <input :id="id" ref="descriptionInput" v-model="form.description" class="input" maxlength="150" required />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Categoria" required :error="form.errors.category">
                        <select :id="id" v-model="form.category" class="input" required>
                            <option value="" disabled>Selecione</option>
                            <option v-for="category in categories" :key="category" :value="category">{{ category }}</option>
                        </select>
                    </Field>

                    <Field v-if="isPayable" v-slot="{ id }" label="Fornecedor" :error="form.errors.supplier_id">
                        <select :id="id" v-model="form.supplier_id" class="input">
                            <option value="">Sem fornecedor</option>
                            <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                        </select>
                    </Field>

                    <Field v-else v-slot="{ id }" label="Cliente" :error="form.errors.counterparty">
                        <input :id="id" v-model="form.counterparty" class="input" maxlength="120" />
                    </Field>

                    <Field v-slot="{ id }" label="Valor total (R$)" required :error="form.errors.amount">
                        <DecimalInput :id="id" v-model="form.amount" pad required />
                    </Field>

                    <Field v-slot="{ id }" label="Primeiro vencimento" required :error="form.errors.due_date">
                        <input :id="id" v-model="form.due_date" class="input" type="date" required />
                    </Field>

                    <Field
                        v-slot="{ id }"
                        label="Parcelas"
                        :error="form.errors.installments"
                        :hint="Number(form.installments) > 1 ? `${form.installments}x de ${formatMoney(installmentValue)}, mês a mês` : null"
                    >
                        <input :id="id" v-model.number="form.installments" class="input" type="number" min="1" max="60" />
                    </Field>
                </div>

                <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                    <input :id="id" v-model="form.notes" class="input" maxlength="255" />
                </Field>
            </div>

            <div class="border-line flex justify-end gap-2 border-t px-6 py-4">
                <button type="button" class="btn btn-secondary" @click="close">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
            </div>
        </form>
    </dialog>
</template>
