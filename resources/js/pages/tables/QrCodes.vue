<script setup>
import { onMounted, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Printer, RefreshCw } from '@lucide/vue';
import QRCode from 'qrcode';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    tables: { type: Array, required: true },
    company: { type: String, required: true },
    isLocal: { type: Boolean, required: true },
});

const images = ref({});
const form = useForm({});

async function render() {
    for (const table of props.tables) {
        images.value[table.id] = await QRCode.toDataURL(table.url, { width: 320, margin: 1, errorCorrectionLevel: 'M' });
    }
}

function regenerate(table) {
    if (confirm(`Trocar o QR Code da mesa ${table.number}? O código impresso deixa de funcionar.`)) {
        form.post(route('tables.qr.regenerate', table.id), { preserveScroll: true, onSuccess: render });
    }
}

onMounted(render);
</script>

<template>
    <AppLayout title="QR Codes das mesas">
        <div class="print:hidden">
            <PageHeader title="QR Codes das mesas" subtitle="Imprima e cole em cada mesa.">
                <Link :href="route('tables.manage')" class="btn btn-secondary">
                    <ArrowLeft :size="16" />
                    Voltar
                </Link>
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <Printer :size="16" />
                    Imprimir
                </button>
            </PageHeader>

            <p v-if="isLocal" class="bg-warn-soft text-warn mb-4 max-w-3xl rounded-lg px-4 py-3 text-sm font-medium">
                Estes códigos apontam para este computador e não abrem no celular do cliente. Antes de imprimir, abra esta tela pelo endereço público do sistema.
            </p>
        </div>

        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 print:grid-cols-3">
            <li v-for="table in tables" :key="table.id" class="card p-4 text-center break-inside-avoid">
                <p class="text-muted text-xs">{{ company }}</p>
                <p class="text-xl font-bold">Mesa {{ table.number }}</p>
                <img v-if="images[table.id]" :src="images[table.id]" :alt="`QR Code da mesa ${table.number}`" class="mx-auto my-2 w-full max-w-44" />
                <p class="text-sm font-semibold">Aponte a câmera e peça aqui</p>
                <div class="mt-2 flex justify-center gap-2 print:hidden">
                    <a :href="table.url" target="_blank" rel="noopener" class="btn btn-ghost">Abrir</a>
                    <button type="button" class="btn btn-ghost" :aria-label="`Trocar QR Code da mesa ${table.number}`" title="Trocar código" @click="regenerate(table)">
                        <RefreshCw :size="16" />
                    </button>
                </div>
            </li>
        </ul>
    </AppLayout>
</template>
