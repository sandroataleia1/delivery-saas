<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    status: { type: Number, required: true },
});

const messages = {
    403: ['Acesso negado', 'Seu perfil não tem permissão para esta área.'],
    404: ['Página não encontrada', 'O endereço não existe ou você não tem acesso a ele.'],
    500: ['Algo deu errado', 'Tente novamente em instantes.'],
    503: ['Sistema em manutenção', 'Voltamos em instantes.'],
};

const message = computed(() => messages[props.status] ?? messages[500]);
</script>

<template>
    <main class="flex min-h-dvh flex-col items-center justify-center px-4 text-center">
        <Head :title="message[0]" />
        <p class="text-brand text-sm font-bold">Erro {{ status }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ message[0] }}</h1>
        <p class="text-muted mt-2">{{ message[1] }}</p>
        <a href="/" class="btn btn-primary mt-6">Voltar ao início</a>
    </main>
</template>
