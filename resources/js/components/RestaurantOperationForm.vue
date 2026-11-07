<script setup>
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
const props = defineProps({ operation: { type: Object, required: true }, ceilings: { type: Object, required: true } });
const labels = { max_lines: 'Linhas por pedido', max_line_units: 'Unidades por linha', max_order_units: 'Unidades por pedido', max_order_value: 'Valor por pedido (R$)', max_pending_orders: 'Pedidos pendentes por mesa', max_pending_units: 'Unidades pendentes por mesa', max_pending_value: 'Valor pendente por mesa (R$)', late_after_minutes: 'Alerta de atraso (minutos)' };
const form = useForm(Object.fromEntries(['confirmation_mode', 'visual_alerts', 'sound_alerts', ...Object.keys(labels)].map(key => [key, props.operation[key]])));
</script>
<template>
    <form class="card mt-6 space-y-4 p-5" @submit.prevent="form.put(route('company.operation'), { preserveScroll: true })">
        <h2 class="font-bold">Operação do Restaurante</h2>
        <p class="text-muted text-sm">Modo efetivo: confirmação manual. Pedidos do QR aguardam a equipe e ainda precisam de encaminhamento manual.</p>
        <p v-if="operation.automatic_blocked" class="text-warn text-sm" role="status">O modo automático está bloqueado pela administração da plataforma.</p>
        <Field v-slot="{ id }" label="Preferência de confirmação" :error="form.errors.confirmation_mode">
            <select :id="id" v-model="form.confirmation_mode" class="input"><option value="manual">Manual</option><option value="automatic">Automático (indisponível nesta fase)</option></select>
        </Field>
        <p class="text-muted text-sm">A preferência automática fica salva, mas só poderá entrar em vigor com produção e encaminhamento funcionais. O QR é anônimo e não comprova presença na mesa.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <Field v-for="(label, key) in labels" :key="key" v-slot="{ id }" :label="label" :error="form.errors[key]">
                <input :id="id" v-model="form[key]" class="input" type="number" min="1" :max="ceilings[key]" :step="key.includes('value') ? '0.01' : '1'" required />
            </Field>
        </div>
        <label class="flex gap-2 text-sm"><input v-model="form.visual_alerts" type="checkbox" />Alertas visuais de novos pedidos</label>
        <label class="flex gap-2 text-sm"><input v-model="form.sound_alerts" type="checkbox" />Permitir alertas sonoros (ativação em cada tela)</label>
        <button class="btn btn-primary" :disabled="form.processing">Salvar operação</button>
    </form>
</template>
