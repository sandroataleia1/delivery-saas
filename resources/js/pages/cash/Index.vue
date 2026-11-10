<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowDownToLine, ArrowUpFromLine, History, Lock, ShoppingCart } from '@lucide/vue';
import CashControls from '@/components/CashControls.vue';
import CashEntries from '@/components/CashEntries.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { usePermissions } from '@/lib/can';
import { formatMoney } from '@/lib/format';

defineProps({
    session: { type: Object, default: null },
});

const { can } = usePermissions();

const openForm = useForm({ opening_amount: '' });
const controls = ref(null);
</script>

<template>
    <AppLayout title="Caixa">
        <PageHeader title="Caixa" :subtitle="session ? `Caixa #${session.number} aberto em ${session.opened_at}` : 'Abra o caixa para começar a vender.'">
            <Link :href="route('cash.sessions')" class="btn btn-secondary">
                <History :size="16" />
                Histórico
            </Link>
        </PageHeader>

        <form v-if="!session" class="card max-w-sm space-y-4 p-5" @submit.prevent="openForm.post(route('cash.open'))">
            <Field v-slot="{ id }" label="Dinheiro na gaveta (R$)" required :error="openForm.errors.opening_amount" hint="Conte o troco inicial. Informe 0,00 se a gaveta estiver vazia.">
                <DecimalInput :id="id" v-model="openForm.opening_amount" pad required autofocus />
            </Field>
            <button type="submit" class="btn btn-primary w-full" :disabled="openForm.processing">Abrir caixa</button>
        </form>

        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
                <div class="card p-4">
                    <p class="text-muted text-sm">Valor inicial</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(session.opening_amount) }}</p>
                </div>
                <div class="card p-4">
                    <p class="text-muted text-sm">Vendas neste caixa</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ session.sales_count }}</p>
                </div>
                <div v-if="session.expected_cash !== null" class="card col-span-2 p-4 lg:col-span-1">
                    <p class="text-muted text-sm">Dinheiro esperado na gaveta</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatMoney(session.expected_cash) }}</p>
                </div>
            </div>

            <div class="mb-6 flex flex-wrap gap-2">
                <Link v-if="can('sales.create')" :href="route('pos')" class="btn btn-primary">
                    <ShoppingCart :size="16" />
                    Ir para o PDV
                </Link>
                <button type="button" class="btn btn-secondary" @click="controls.start('withdrawal')">
                    <ArrowUpFromLine :size="16" />
                    Sangria
                </button>
                <button type="button" class="btn btn-secondary" @click="controls.start('deposit')">
                    <ArrowDownToLine :size="16" />
                    Suprimento
                </button>
                <button type="button" class="btn btn-secondary" @click="controls.startClose()">
                    <Lock :size="16" />
                    Fechar caixa
                </button>
            </div>

            <h2 class="mb-3 text-lg font-bold">Lançamentos deste caixa</h2>
            <div class="max-w-3xl">
                <CashEntries :movements="session.movements" :sales="session.sales" />
            </div>

            <CashControls ref="controls" :session="session" />
        </template>
    </AppLayout>
</template>
