<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    available: { type: Boolean, required: true },
    settings: { type: Object, required: true },
    qr: { type: String, default: null },
    messages: { type: Array, default: () => [] },
    bot: { type: Object, required: true },
});

const page = usePage();
const connectionError = computed(() => page.props.errors?.whatsapp);

const form = useForm({
    enabled: props.settings.enabled,
    templates: Object.fromEntries(props.settings.templates.map((template) => [template.key, template.value ?? ''])),
    auto_reply: props.bot.auto_reply,
    greeting: props.bot.greeting ?? '',
    ignored_numbers: props.bot.ignored_numbers.join('\n'),
});

const save = () => form
    .transform((data) => ({
        ...data,
        ignored_numbers: data.ignored_numbers.split('\n').map((number) => number.trim()).filter(Boolean),
    }))
    .put(route('online.whatsapp.update'), { preserveScroll: true });

const ignoredNumbersError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('ignored_numbers'))?.[1]);
const conversationStatusClasses = { 'Aguardando atendente': 'text-warn font-semibold', 'Com atendente': 'text-brand-strong', 'Pedido feito': 'text-success' };

const working = ref(false);
const connect = () => router.post(route('online.whatsapp.connect'), {}, {
    preserveScroll: true,
    onStart: () => { working.value = true; },
    onFinish: () => { working.value = false; },
});

const confirmingDisconnect = ref(false);
const disconnect = () => router.delete(route('online.whatsapp.disconnect'), {
    preserveScroll: true,
    onStart: () => { working.value = true; },
    onSuccess: () => { confirmingDisconnect.value = false; },
    onFinish: () => { working.value = false; },
});

const showQr = computed(() => props.qr && !props.settings.connected);

const { start, stop } = usePoll(5000, { only: ['settings', 'qr'] }, { autoStart: false });
watch(showQr, (waiting) => (waiting ? start() : stop()), { immediate: true });

const statusLabels = { sent: 'Enviada', pending: 'Na fila', sending: 'Enviando', failed: 'Falhou' };
const statusClasses = { sent: 'text-success', failed: 'text-danger' };
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <AppLayout title="WhatsApp">
        <PageHeader title="WhatsApp da loja" />

        <div v-if="!available" class="card mb-4 max-w-2xl p-5 text-sm">
            O WhatsApp ainda não foi configurado no servidor. Fale com o suporte para ativar este recurso.
        </div>

        <div class="card mb-4 max-w-2xl space-y-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold">Conexão</h2>
                    <p class="text-sm font-semibold" :class="settings.connected ? 'text-success' : 'text-muted'">
                        {{ settings.connected ? 'Conectado' : 'Desconectado' }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <button v-if="settings.connected" type="button" class="btn" :disabled="working" @click="confirmingDisconnect = true">Desconectar</button>
                    <button v-else type="button" class="btn btn-primary" :disabled="working || !available" @click="connect">
                        {{ settings.has_instance ? 'Gerar novo QR' : 'Conectar WhatsApp' }}
                    </button>
                </div>
            </div>
            <p v-if="connectionError" class="text-danger text-sm">{{ connectionError }}</p>
            <div v-if="showQr" class="space-y-2">
                <img :src="qr" alt="QR code para conectar o WhatsApp" class="size-64 rounded-lg border bg-white p-2" />
                <p class="text-muted text-sm">Abra o WhatsApp no celular da loja > Aparelhos conectados > Conectar aparelho e leia o código.</p>
            </div>
        </div>

        <form class="mb-4 max-w-2xl space-y-4" @submit.prevent="save">
            <div class="card space-y-4 p-5">
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.enabled" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Enviar mensagens aos clientes</span>
                        <span class="text-muted">Avisa o cliente a cada etapa do pedido online.</span>
                    </span>
                </label>

                <p class="text-muted text-sm">
                    Marcadores: <span class="font-mono">{nome}</span>, <span class="font-mono">{loja}</span>, <span class="font-mono">{numero}</span>,
                    <span class="font-mono">{link}</span>, <span class="font-mono">{motivo}</span>, <span class="font-mono">{total}</span>.
                    Deixe em branco para usar o texto padrão. O link de acompanhamento vai sempre junto.
                </p>

                <div v-for="template in settings.templates" :key="template.key" class="space-y-1">
                    <label :for="`tpl-${template.key}`" class="block text-sm font-semibold">{{ template.label }}</label>
                    <textarea
                        :id="`tpl-${template.key}`"
                        v-model="form.templates[template.key]"
                        class="input"
                        rows="3"
                        maxlength="1000"
                        :placeholder="template.default"
                    />
                    <p v-if="form.errors[`templates.${template.key}`]" class="text-danger text-sm">{{ form.errors[`templates.${template.key}`] }}</p>
                </div>
                <p v-if="form.errors.templates" class="text-danger text-sm">{{ form.errors.templates }}</p>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Atendimento automático</h2>
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.auto_reply" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Responder clientes automaticamente</span>
                        <span class="text-muted">O robô cumprimenta, mostra o cardápio e monta o pedido pela conversa. O cliente digita 0 para falar com a loja.</span>
                    </span>
                </label>

                <div class="space-y-1">
                    <label for="bot-greeting" class="block text-sm font-semibold">Mensagem de boas-vindas</label>
                    <textarea id="bot-greeting" v-model="form.greeting" class="input" rows="3" maxlength="1000" :placeholder="bot.default_greeting" />
                    <p class="text-muted text-sm">Marcadores: <span class="font-mono">{loja}</span>, <span class="font-mono">{link}</span>. Deixe em branco para usar o texto padrão.</p>
                    <p v-if="form.errors.greeting" class="text-danger text-sm">{{ form.errors.greeting }}</p>
                </div>

                <div class="space-y-1">
                    <label for="bot-ignored" class="block text-sm font-semibold">Números que o robô não responde</label>
                    <textarea id="bot-ignored" v-model="form.ignored_numbers" class="input font-mono" rows="3" placeholder="(11) 98888-7777" />
                    <p class="text-muted text-sm">Um número por linha, com DDD. Use para fornecedores, entregadores e a própria equipe.</p>
                    <p v-if="ignoredNumbersError" class="text-danger text-sm">{{ ignoredNumbersError }}</p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
        </form>

        <div class="card mb-4 max-w-4xl p-5">
            <h2 class="mb-3 font-bold">Conversas recentes</h2>
            <p v-if="bot.conversations.length === 0" class="text-muted text-sm">Nenhum cliente escreveu ainda.</p>
            <div v-else class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Número</th>
                            <th>Situação</th>
                            <th>Última atividade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="conversation in bot.conversations" :key="conversation.id">
                            <td>{{ conversation.name ?? '—' }}</td>
                            <td class="whitespace-nowrap tabular-nums">
                                <a v-if="conversation.phone" :href="`https://wa.me/55${conversation.phone}`" target="_blank" rel="noopener" class="underline">{{ conversation.phone }}</a>
                                <span v-else>—</span>
                            </td>
                            <td :class="conversationStatusClasses[conversation.status_label]">{{ conversation.status_label }}</td>
                            <td class="whitespace-nowrap tabular-nums">{{ formatDate(conversation.updated_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card max-w-4xl p-5">
            <h2 class="mb-3 font-bold">Últimas mensagens</h2>
            <p v-if="messages.length === 0" class="text-muted text-sm">Nenhuma mensagem enviada ainda.</p>
            <div v-else class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Etapa</th>
                            <th>Situação</th>
                            <th>Tentativas</th>
                            <th>Erro</th>
                            <th>Horário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="message in messages" :key="message.id">
                            <td class="whitespace-nowrap">#{{ message.number }}</td>
                            <td>{{ message.event_label }}</td>
                            <td :class="statusClasses[message.status]">{{ statusLabels[message.status] ?? message.status }}</td>
                            <td class="tabular-nums">{{ message.attempts }}</td>
                            <td class="text-muted text-sm">{{ message.last_error ?? '—' }}</td>
                            <td class="whitespace-nowrap tabular-nums">{{ formatDate(message.sent_at ?? message.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingDisconnect"
            title="Desconectar o WhatsApp?"
            message="A loja deixa de enviar mensagens aos clientes até conectar de novo."
            confirm-label="Desconectar"
            danger
            :processing="working"
            @confirm="disconnect"
            @cancel="confirmingDisconnect = false"
        />
    </AppLayout>
</template>
