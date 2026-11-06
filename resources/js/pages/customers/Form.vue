<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatPhone } from '@/lib/format';

const props = defineProps({
    customer: { type: Object, default: null },
});

const { can } = usePermissions();
const editing = computed(() => props.customer !== null);

const blankAddress = () => ({ street: '', number: '', complement: '', district: '', reference: '' });

const form = useForm({
    name: props.customer?.name ?? '',
    phone: formatPhone(props.customer?.phone),
    document: props.customer?.document ?? '',
    notes: props.customer?.notes ?? '',
    allow_credit: props.customer?.allow_credit ?? false,
    credit_limit: props.customer?.credit_limit ?? '',
    credit_days: props.customer?.credit_days ?? 30,
    addresses: (props.customer?.addresses ?? []).map(({ street, number, complement, district, reference }) => ({
        street,
        number,
        complement: complement ?? '',
        district,
        reference: reference ?? '',
    })),
});

function submit() {
    if (editing.value) {
        form.put(route('customers.update', props.customer.id));
    } else {
        form.post(route('customers.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar cliente' : 'Novo cliente'">
        <PageHeader :title="editing ? 'Editar cliente' : 'Novo cliente'" />

        <form class="max-w-xl space-y-4" @submit.prevent="submit">
            <div class="card space-y-4 p-5">
                <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                    <input :id="id" v-model="form.name" class="input" maxlength="120" required autofocus />
                </Field>
                <Field v-slot="{ id }" label="Telefone" :error="form.errors.phone">
                    <input :id="id" class="input" inputmode="tel" :value="form.phone" @input="form.phone = $event.target.value = formatPhone($event.target.value)" />
                </Field>
                <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                    <input :id="id" v-model="form.notes" class="input" maxlength="255" />
                </Field>
            </div>

            <div v-if="can('sales.credit')" class="card space-y-4 p-5">
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.allow_credit" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Liberar fiado</span>
                        <span class="text-muted">Cada venda fiado vira uma conta a receber.</span>
                    </span>
                </label>
                <div v-if="form.allow_credit" class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Limite (R$)" :error="form.errors.credit_limit" hint="Em branco: sem limite.">
                        <DecimalInput :id="id" v-model="form.credit_limit" pad />
                    </Field>
                    <Field v-slot="{ id }" label="Prazo (dias)" :error="form.errors.credit_days">
                        <input :id="id" v-model.number="form.credit_days" class="input" type="number" min="1" max="365" />
                    </Field>
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Endereços</h2>

                <div v-for="(address, index) in form.addresses" :key="index" class="border-line space-y-3 border-b pb-4">
                    <div class="grid grid-cols-[1fr_6rem] gap-3">
                        <Field v-slot="{ id }" label="Rua" required :error="form.errors[`addresses.${index}.street`]">
                            <input :id="id" v-model="address.street" class="input" required />
                        </Field>
                        <Field v-slot="{ id }" label="Número" required :error="form.errors[`addresses.${index}.number`]">
                            <input :id="id" v-model="address.number" class="input" required />
                        </Field>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Field v-slot="{ id }" label="Bairro" required :error="form.errors[`addresses.${index}.district`]">
                            <input :id="id" v-model="address.district" class="input" required />
                        </Field>
                        <Field v-slot="{ id }" label="Complemento">
                            <input :id="id" v-model="address.complement" class="input" />
                        </Field>
                    </div>
                    <Field v-slot="{ id }" label="Referência">
                        <input :id="id" v-model="address.reference" class="input" />
                    </Field>
                    <button type="button" class="btn btn-ghost" @click="form.addresses.splice(index, 1)">
                        <Trash2 :size="16" />
                        Remover endereço
                    </button>
                </div>

                <button type="button" class="btn btn-secondary" @click="form.addresses.push(blankAddress())">
                    <Plus :size="16" />
                    Adicionar endereço
                </button>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('customers.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
