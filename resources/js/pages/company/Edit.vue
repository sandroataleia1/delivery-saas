<script setup>
import { useForm } from '@inertiajs/vue3';
import AddressFields from '@/components/AddressFields.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCep, formatCnpj, formatPhone } from '@/lib/format';

const props = defineProps({
    company: { type: Object, required: true },
    segments: { type: Array, required: true },
    states: { type: Array, required: true },
});

const form = useForm({
    legal_name: props.company.legal_name,
    trade_name: props.company.trade_name,
    segment: props.company.segment ?? '',
    email: props.company.email ?? '',
    phone: formatPhone(props.company.phone),
    zip_code: formatCep(props.company.zip_code),
    street: props.company.street ?? '',
    number: props.company.number ?? '',
    complement: props.company.complement ?? '',
    district: props.company.district ?? '',
    city: props.company.city ?? '',
    state: props.company.state ?? '',
    service_fee_percent: props.company.service_fee_percent,
});
</script>

<template>
    <AppLayout title="Empresa">
        <PageHeader title="Dados da empresa" />

        <form class="max-w-xl space-y-4" @submit.prevent="form.put(route('company.update'))">
            <div class="card space-y-4 p-5">
                <Field v-slot="{ id }" label="CNPJ" hint="Para alterar o CNPJ, fale com o suporte.">
                    <input :id="id" class="input" :value="formatCnpj(company.document)" disabled />
                </Field>

                <Field v-slot="{ id }" label="Razão social" :error="form.errors.legal_name">
                    <input :id="id" v-model="form.legal_name" class="input" required />
                </Field>

                <Field v-slot="{ id }" label="Nome fantasia" :error="form.errors.trade_name">
                    <input :id="id" v-model="form.trade_name" class="input" required />
                </Field>

                <Field v-slot="{ id }" label="Especialidade" :error="form.errors.segment">
                    <select :id="id" v-model="form.segment" class="input">
                        <option value="">Não informada</option>
                        <option v-for="segment in segments" :key="segment" :value="segment">{{ segment }}</option>
                    </select>
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="E-mail" :error="form.errors.email">
                        <input :id="id" v-model="form.email" class="input" type="email" />
                    </Field>

                    <Field v-slot="{ id }" label="Telefone" :error="form.errors.phone">
                        <input
                            :id="id"
                            class="input"
                            inputmode="tel"
                            :value="form.phone"
                            @input="form.phone = $event.target.value = formatPhone($event.target.value)"
                        />
                    </Field>
                </div>
            </div>

            <div class="card p-5">
                <h2 class="mb-4 font-bold">Endereço</h2>
                <AddressFields :form="form" :states="states" />
            </div>

            <div class="card p-5">
                <h2 class="mb-4 font-bold">Mesas</h2>
                <Field v-slot="{ id }" label="Taxa de serviço (%)" :error="form.errors.service_fee_percent" hint="Sugerida ao fechar a conta. Pode ser retirada se o cliente preferir.">
                    <DecimalInput :id="id" v-model="form.service_fee_percent" class="max-w-32" />
                </Field>
            </div>

            <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
        </form>
    </AppLayout>
</template>
