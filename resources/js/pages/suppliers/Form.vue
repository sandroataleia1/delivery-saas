<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCnpj, formatPhone } from '@/lib/format';

const props = defineProps({
    supplier: { type: Object, default: null },
});

const editing = computed(() => props.supplier !== null);

const form = useForm({
    name: props.supplier?.name ?? '',
    document: formatCnpj(props.supplier?.document),
    email: props.supplier?.email ?? '',
    phone: formatPhone(props.supplier?.phone),
    notes: props.supplier?.notes ?? '',
});

function submit() {
    if (editing.value) {
        form.put(route('suppliers.update', props.supplier.id));
    } else {
        form.post(route('suppliers.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar fornecedor' : 'Novo fornecedor'">
        <PageHeader :title="editing ? 'Editar fornecedor' : 'Novo fornecedor'" />

        <form class="card max-w-xl space-y-4 p-5" @submit.prevent="submit">
            <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                <input :id="id" v-model="form.name" class="input" maxlength="120" required autofocus />
            </Field>

            <Field v-slot="{ id }" label="CNPJ" :error="form.errors.document">
                <input
                    :id="id"
                    class="input"
                    placeholder="00.000.000/0000-00"
                    :value="form.document"
                    @input="form.document = $event.target.value = formatCnpj($event.target.value)"
                />
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

            <Field v-slot="{ id }" label="Observações" :error="form.errors.notes">
                <textarea :id="id" v-model="form.notes" class="input" rows="3" maxlength="500" />
            </Field>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('suppliers.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
