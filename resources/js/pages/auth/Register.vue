<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AddressFields from '@/components/AddressFields.vue';
import Field from '@/components/Field.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { formatCnpj, formatPhone } from '@/lib/format';

defineProps({
    segments: { type: Array, required: true },
    states: { type: Array, required: true },
});

const steps = [
    { title: 'Seus dados', fields: ['owner_name', 'email', 'owner_phone', 'password'] },
    { title: 'Sua loja', fields: ['document', 'legal_name', 'trade_name', 'segment', 'phone'] },
    { title: 'Endereço da loja', fields: ['zip_code', 'street', 'number', 'complement', 'district', 'city', 'state'] },
];

const current = ref(0);
const lastStep = computed(() => current.value === steps.length - 1);

const form = useForm('post', route('register'), {
    owner_name: '',
    email: '',
    owner_phone: '',
    password: '',
    password_confirmation: '',
    document: '',
    legal_name: '',
    trade_name: '',
    segment: '',
    phone: '',
    zip_code: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
});

function showFirstStepWithError() {
    const index = steps.findIndex((step) => step.fields.some((field) => form.errors[field]));

    if (index !== -1) {
        current.value = index;
    }
}

function next() {
    if (lastStep.value) {
        form.submit({ onError: showFirstStepWithError });

        return;
    }

    form.touch(steps[current.value].fields).validate({
        only: steps[current.value].fields,
        onSuccess: () => current.value++,
    });
}
</script>

<template>
    <GuestLayout title="Crie sua conta" :subtitle="`Etapa ${current + 1} de ${steps.length}: ${steps[current].title}`" wide>
        <div class="mb-6 flex gap-2" aria-hidden="true">
            <span
                v-for="(step, index) in steps"
                :key="step.title"
                class="h-1.5 flex-1 rounded-full transition-colors duration-300"
                :class="index <= current ? 'bg-brand' : 'bg-line'"
            />
        </div>

        <p class="text-muted mb-4 text-xs">Campos com <span class="text-danger font-semibold">*</span> são obrigatórios.</p>

        <form class="space-y-4" @submit.prevent="next">
            <template v-if="current === 0">
                <Field v-slot="{ id }" required label="Nome completo" :error="form.errors.owner_name">
                    <input :id="id" v-model="form.owner_name" class="input" autocomplete="name" required autofocus />
                </Field>

                <Field v-slot="{ id }" required label="E-mail" :error="form.errors.email">
                    <input :id="id" v-model="form.email" class="input" type="email" autocomplete="username" required />
                </Field>

                <Field v-slot="{ id }" required label="Celular" :error="form.errors.owner_phone" hint="Com DDD. Usaremos para falar sobre a sua conta.">
                    <input
                        :id="id"
                        class="input"
                        inputmode="tel"
                        autocomplete="tel-national"
                        placeholder="(00) 00000-0000"
                        :value="form.owner_phone"
                        required
                        @input="form.owner_phone = $event.target.value = formatPhone($event.target.value)"
                    />
                </Field>

                <Field v-slot="{ id }" required label="Senha" :error="form.errors.password" hint="Mínimo de 8 caracteres, com letras e números.">
                    <input :id="id" v-model="form.password" class="input" type="password" autocomplete="new-password" required />
                </Field>

                <Field v-slot="{ id }" required label="Confirme a senha">
                    <input :id="id" v-model="form.password_confirmation" class="input" type="password" autocomplete="new-password" required />
                </Field>
            </template>

            <template v-else-if="current === 1">
                <Field v-slot="{ id }" required label="CNPJ" :error="form.errors.document">
                    <input
                        :id="id"
                        class="input"
                        placeholder="00.000.000/0000-00"
                        :value="form.document"
                        required
                        autofocus
                        @input="form.document = $event.target.value = formatCnpj($event.target.value)"
                    />
                </Field>

                <Field v-slot="{ id }" required label="Razão social" :error="form.errors.legal_name">
                    <input :id="id" v-model="form.legal_name" class="input" autocomplete="organization" required />
                </Field>

                <Field v-slot="{ id }" required label="Nome da loja" :error="form.errors.trade_name" hint="O nome que seus clientes conhecem.">
                    <input :id="id" v-model="form.trade_name" class="input" required />
                </Field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" required label="Especialidade" :error="form.errors.segment">
                        <select :id="id" v-model="form.segment" class="input" required>
                            <option value="" disabled>Selecione</option>
                            <option v-for="segment in segments" :key="segment" :value="segment">{{ segment }}</option>
                        </select>
                    </Field>

                    <Field v-slot="{ id }" required label="Telefone da loja" :error="form.errors.phone">
                        <input
                            :id="id"
                            class="input"
                            inputmode="tel"
                            placeholder="(00) 0000-0000"
                            :value="form.phone"
                            required
                            @input="form.phone = $event.target.value = formatPhone($event.target.value)"
                        />
                    </Field>
                </div>
            </template>

            <AddressFields v-else :form="form" :states="states" required />

            <div class="flex gap-2 pt-2">
                <button
                    v-if="current > 0"
                    type="button"
                    class="btn btn-secondary"
                    aria-label="Voltar para a etapa anterior"
                    @click="current--"
                >
                    <ArrowLeft :size="16" />
                </button>
                <button type="submit" class="btn btn-primary flex-1" :disabled="form.processing || form.validating">
                    {{ lastStep ? 'Criar minha loja' : 'Continuar' }}
                </button>
            </div>
        </form>

        <p class="text-muted mt-6 text-center text-sm">
            Já tem conta?
            <Link :href="route('login')" class="text-brand font-semibold hover:underline">Entrar</Link>
        </p>
    </GuestLayout>
</template>
