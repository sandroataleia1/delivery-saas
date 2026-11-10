<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePoll } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    printers: { type: Array, required: true },
    sectors: { type: Array, required: true },
    orderPrinterId: { type: Number, default: null },
    agent: { type: Object, required: true },
    jobs: { type: Array, required: true },
});

usePoll(5000, { only: ['agent', 'jobs'] });

const paperOptions = [
    { value: 48, label: '80 mm (48 colunas)' },
    { value: 42, label: '80 mm (42 colunas)' },
    { value: 32, label: '58 mm (32 colunas)' },
];
const statusLabel = { pending: 'Na fila', printed: 'Impresso', failed: 'Falhou' };
const statusClass = { pending: 'bg-warn-soft text-warn', printed: 'bg-ok-soft text-ok', failed: 'bg-danger-soft text-danger' };

const editing = ref(null);
const form = useForm({ name: '', connection: 'network', address: '', port: 9100, columns: 48, active: true });
const isNetwork = computed(() => form.connection === 'network');

function edit(printer) {
    editing.value = printer.id;
    form.defaults({ ...printer, port: printer.port ?? 9100 });
    form.reset();
    form.clearErrors();
}

function reset() {
    editing.value = null;
    form.defaults({ name: '', connection: 'network', address: '', port: 9100, columns: 48, active: true });
    form.reset();
    form.clearErrors();
}

function save() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value) {
        form.put(route('printers.update', editing.value), options);
    } else {
        form.post(route('printers.store'), options);
    }
}

const removing = ref(null);
function remove() {
    router.delete(route('printers.destroy', removing.value.id), { preserveScroll: true, onFinish: () => (removing.value = null) });
}

function test(printer) {
    router.post(route('printers.test', printer.id), {}, { preserveScroll: true });
}

const destinations = useForm({
    order_printer_id: props.orderPrinterId,
    sectors: Object.fromEntries(props.sectors.map((sector) => [sector.id, sector.printer_id])),
});
function saveDestinations() {
    destinations.put(route('printers.destinations'), { preserveScroll: true });
}

const replacingAgent = ref(false);
function downloadAgent() {
    replacingAgent.value = false;
    window.location.href = route('printers.agent');
    setTimeout(() => router.reload({ only: ['agent'] }), 1500);
}

function retry(job) {
    router.post(route('printers.retry', job.id), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Impressoras">
        <PageHeader title="Impressoras" subtitle="Comandas da cozinha e pedidos saindo nas impressoras térmicas da loja." />

        <div class="grid items-start gap-5 lg:grid-cols-2">
            <section class="card space-y-3 p-5" aria-labelledby="agent-title">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="agent-title" class="font-bold">Agente de impressão</h2>
                    <span v-if="!agent.installed" class="badge bg-canvas text-muted">Não instalado</span>
                    <span v-else-if="agent.online" class="badge bg-ok-soft text-ok">Ligado</span>
                    <span v-else class="badge bg-danger-soft text-danger">Desligado</span>
                </div>
                <p class="text-muted text-sm">
                    Um programa pequeno que fica num computador Windows da loja e entrega os papéis para as impressoras.
                    <template v-if="agent.installed && !agent.online && agent.seen_at"> Último sinal: {{ agent.seen_at }}.</template>
                </p>
                <ol class="list-decimal space-y-1 pl-5 text-sm">
                    <li>Baixe o agente no computador da loja que fica ligado durante o expediente.</li>
                    <li>Clique com o botão direito no arquivo e escolha <strong>Executar com o PowerShell</strong>.</li>
                    <li>Pronto: ele abre sozinho junto com o Windows.</li>
                </ol>
                <button type="button" class="btn btn-primary" @click="agent.installed ? (replacingAgent = true) : downloadAgent()">Baixar agente</button>
            </section>

            <section class="card space-y-4 p-5" aria-labelledby="destinations-title">
                <h2 id="destinations-title" class="font-bold">Onde cada papel sai</h2>
                <p v-if="!printers.length" class="text-muted text-sm">Cadastre uma impressora para escolher os destinos.</p>
                <form v-else class="space-y-4" @submit.prevent="saveDestinations">
                    <Field v-slot="{ id }" label="Pedido completo (entregas e loja online)" :error="destinations.errors.order_printer_id">
                        <select :id="id" v-model="destinations.order_printer_id" class="input">
                            <option :value="null">Não imprimir</option>
                            <option v-for="printer in printers" :key="printer.id" :value="printer.id">{{ printer.name }}</option>
                        </select>
                    </Field>
                    <Field v-for="sector in sectors" :key="sector.id" v-slot="{ id }" :label="`Comanda: ${sector.name}${sector.active ? '' : ' (inativo)'}`" :error="destinations.errors[`sectors.${sector.id}`]">
                        <select :id="id" v-model="destinations.sectors[sector.id]" class="input">
                            <option :value="null">Não imprimir</option>
                            <option v-for="printer in printers" :key="printer.id" :value="printer.id">{{ printer.name }}</option>
                        </select>
                    </Field>
                    <button class="btn btn-primary" :disabled="destinations.processing">Salvar destinos</button>
                </form>
            </section>

            <section class="card space-y-4 p-5" aria-labelledby="printer-form-title">
                <h2 id="printer-form-title" class="font-bold">{{ editing ? 'Editar impressora' : 'Nova impressora' }}</h2>
                <form class="space-y-4" @submit.prevent="save">
                    <Field v-slot="{ id }" label="Nome" required :error="form.errors.name">
                        <input :id="id" v-model="form.name" class="input" maxlength="60" placeholder="Ex.: Cozinha" required />
                    </Field>
                    <Field v-slot="{ id }" label="Como está ligada" required :error="form.errors.connection">
                        <select :id="id" v-model="form.connection" class="input">
                            <option value="network">Na rede (tem IP próprio)</option>
                            <option value="windows">No Windows (instalada ou compartilhada)</option>
                        </select>
                    </Field>
                    <div class="grid gap-4" :class="isNetwork ? 'sm:grid-cols-[1fr_7rem]' : ''">
                        <Field
                            v-slot="{ id }"
                            :label="isNetwork ? 'IP da impressora' : 'Nome ou caminho da impressora'"
                            required
                            :error="form.errors.address"
                            :hint="isNetwork ? 'Aparece na folha de configuração da impressora.' : 'Nome como aparece no Windows, ou \\\\COMPUTADOR\\Compartilhamento.'"
                        >
                            <input :id="id" v-model="form.address" class="input" :placeholder="isNetwork ? '192.168.0.50' : '\\\\CAIXA\\Cozinha'" required />
                        </Field>
                        <Field v-if="isNetwork" v-slot="{ id }" label="Porta" required :error="form.errors.port">
                            <input :id="id" v-model.number="form.port" type="number" min="1" max="65535" class="input" required />
                        </Field>
                    </div>
                    <Field v-slot="{ id }" label="Papel" required :error="form.errors.columns">
                        <select :id="id" v-model.number="form.columns" class="input">
                            <option v-for="option in paperOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </Field>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.active" type="checkbox" class="accent-brand size-4" /> Ativa</label>
                    <div class="flex gap-2">
                        <button class="btn btn-primary" :disabled="form.processing">{{ editing ? 'Salvar impressora' : 'Cadastrar impressora' }}</button>
                        <button v-if="editing" type="button" class="btn btn-secondary" @click="reset">Cancelar</button>
                    </div>
                </form>
            </section>

            <section class="card p-5" aria-labelledby="printers-title">
                <h2 id="printers-title" class="mb-3 font-bold">Impressoras cadastradas</h2>
                <p v-if="!printers.length" class="text-muted text-sm">Nenhuma impressora ainda.</p>
                <ul v-else class="divide-line divide-y">
                    <li v-for="printer in printers" :key="printer.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ printer.name }} <span v-if="!printer.active" class="text-muted text-sm font-normal">(inativa)</span></p>
                            <p class="text-muted truncate text-sm">{{ printer.connection === 'network' ? `${printer.address}:${printer.port}` : printer.address }}</p>
                        </div>
                        <div class="flex gap-1">
                            <button type="button" class="btn btn-ghost" :disabled="!printer.active" @click="test(printer)">Testar</button>
                            <button type="button" class="btn btn-ghost" @click="edit(printer)">Editar</button>
                            <button type="button" class="btn btn-ghost text-danger" @click="removing = printer">Excluir</button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <section class="card mt-5 p-5" aria-labelledby="jobs-title">
            <h2 id="jobs-title" class="mb-3 font-bold">Últimos papéis</h2>
            <p v-if="!jobs.length" class="text-muted text-sm">Nada impresso ainda.</p>
            <ul v-else class="divide-line divide-y text-sm">
                <li v-for="job in jobs" :key="job.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ job.title }}</p>
                        <p class="text-muted">{{ job.printer }} · {{ job.created_at }}</p>
                        <p v-if="job.error" class="text-danger">{{ job.error }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge" :class="statusClass[job.status]">{{ statusLabel[job.status] }}</span>
                        <button v-if="job.status !== 'pending'" type="button" class="btn btn-ghost" @click="retry(job)">Imprimir de novo</button>
                    </div>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Excluir a impressora ${removing?.name ?? ''}?`"
            message="Os papéis que iam para ela deixam de ser impressos."
            confirm-label="Excluir"
            danger
            @confirm="remove"
            @cancel="removing = null"
        />
        <ConfirmDialog
            :open="replacingAgent"
            title="Baixar um agente novo?"
            message="O agente instalado hoje para de funcionar. Instale o novo no computador da loja logo em seguida."
            confirm-label="Baixar"
            @confirm="downloadAgent"
            @cancel="replacingAgent = false"
        />
    </AppLayout>
</template>
