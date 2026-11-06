<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    role: { type: Object, default: null },
    groups: { type: Array, required: true },
});

const page = usePage();
const editing = computed(() => props.role !== null);

const form = useForm({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permissions: props.role?.permissions ?? [],
});

const canGrant = (key) => page.props.auth.permissions.includes(key);

function submit() {
    if (editing.value) {
        form.put(route('roles.update', props.role.id));
    } else {
        form.post(route('roles.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar perfil' : 'Novo perfil'">
        <PageHeader :title="editing ? 'Editar perfil' : 'Novo perfil'" />

        <form class="max-w-xl space-y-4" @submit.prevent="submit">
            <div class="card space-y-4 p-5">
                <Field v-slot="{ id }" label="Nome" :error="form.errors.name">
                    <input :id="id" v-model="form.name" class="input" maxlength="60" required autofocus />
                </Field>

                <Field v-slot="{ id }" label="Descrição" :error="form.errors.description">
                    <input :id="id" v-model="form.description" class="input" maxlength="255" />
                </Field>
            </div>

            <fieldset class="card p-5">
                <legend class="sr-only">Permissões</legend>
                <h2 class="font-bold">Permissões</h2>
                <p v-if="form.errors.permissions" class="text-danger mt-1 text-sm" role="alert">{{ form.errors.permissions }}</p>

                <div v-for="group in groups" :key="group.label" class="mt-4">
                    <p class="text-muted text-xs font-semibold tracking-wide uppercase">{{ group.label }}</p>
                    <label
                        v-for="(label, key) in group.permissions"
                        :key="key"
                        class="mt-2 flex items-center gap-2.5 text-sm"
                        :class="canGrant(key) ? 'cursor-pointer' : 'text-muted cursor-not-allowed'"
                    >
                        <input v-model="form.permissions" type="checkbox" class="accent-brand size-4" :value="key" :disabled="!canGrant(key)" />
                        {{ label }}
                    </label>
                </div>
            </fieldset>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
                <Link :href="route('roles.index')" class="btn btn-secondary">Cancelar</Link>
            </div>
        </form>
    </AppLayout>
</template>
