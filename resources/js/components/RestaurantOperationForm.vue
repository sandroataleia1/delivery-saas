<script setup>
import { useForm } from '@inertiajs/vue3';
import Field from '@/components/Field.vue';
const props = defineProps({ operation: { type: Object, required: true }, ceilings: { type: Object, required: true } });
const labels = { max_lines: 'Linhas por pedido', max_line_units: 'Unidades por linha', max_order_units: 'Unidades por pedido', max_order_value: 'Valor por pedido (R$)', max_pending_orders: 'Pedidos pendentes por mesa', max_pending_units: 'Unidades pendentes por mesa', max_pending_value: 'Valor pendente por mesa (R$)', late_after_minutes: 'Alerta de atraso (minutos)' };
const form = useForm({ automatic_enabled: props.operation.automatic_enabled ?? false, remote_risk_accepted: false, ...Object.fromEntries(['confirmation_mode', 'visual_alerts', 'sound_alerts', 'waiter_notices', ...Object.keys(labels)].map(key => [key, props.operation[key]])) });
</script>
<template>
    <form class="card mt-6 space-y-4 p-5" @submit.prevent="form.put(route('company.operation'), { preserveScroll: true })">
        <h2 class="font-bold">Operação do Restaurante</h2>
        <p class="text-muted text-sm">Modo efetivo: {{ operation.effective_mode === 'automatic' ? 'automático' : 'manual' }}. A confirmação encaminha cada item ao painel de produção.</p>
        <p v-if="operation.automatic_blocked" class="text-warn text-sm" role="status">O modo automático está bloqueado pela administração da plataforma.</p>
        <Field v-slot="{ id }" label="Preferência de confirmação" :error="form.errors.confirmation_mode">
            <select :id="id" v-model="form.confirmation_mode" class="input"><option value="manual">Manual</option><option value="automatic">Automático (exige ativação abaixo)</option></select>
        </Field>
        <p class="text-muted text-sm">A preferência sozinha não ativa o automático. É necessário um setor padrão ativo e aceite explícito. O QR é anônimo e não comprova presença na mesa.</p>
        <label class="flex gap-2 text-sm"><input v-model="form.automatic_enabled" type="checkbox" />Ativar produção automática para novos envios</label>
        <label v-if="form.automatic_enabled" class="flex gap-2 text-sm"><input v-model="form.remote_risk_accepted" type="checkbox" required />Aceito que o QR anônimo permite pedidos remotos. Novos envios serão confirmados e encaminhados sem revisão da equipe.</label>
        <p v-if="form.errors.automatic_enabled || form.errors.remote_risk_accepted" class="text-danger text-sm" role="alert">{{ form.errors.automatic_enabled || form.errors.remote_risk_accepted }}</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <Field v-for="(label, key) in labels" :key="key" v-slot="{ id }" :label="label" :error="form.errors[key]">
                <input :id="id" v-model="form[key]" class="input" type="number" min="1" :max="ceilings[key]" :step="key.includes('value') ? '0.01' : '1'" required />
            </Field>
        </div>
        <label class="flex gap-2 text-sm"><input v-model="form.visual_alerts" type="checkbox" />Alertas visuais de novos pedidos</label>
        <label class="flex gap-2 text-sm"><input v-model="form.sound_alerts" type="checkbox" />Permitir alertas sonoros (ativação em cada tela)</label>
        <Field v-slot="{ id }" label="Avisos no app do garçom" :error="form.errors.waiter_notices">
            <select :id="id" v-model="form.waiter_notices" class="input"><option value="all">Todos os garçons</option><option value="table_waiter">Só o garçom da mesa</option></select>
        </Field>
        <p class="text-muted text-sm">Pedido pelo QR avisa quem pode confirmar pedidos; item pronto avisa quem pode entregar. Mesa sem garçom com o app recebe aviso de todos.</p>
        <button class="btn btn-primary" :disabled="form.processing">Salvar operação</button>
    </form>
</template>
