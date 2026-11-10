<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import CashEntries from '@/components/CashEntries.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import { formatMoney } from '@/lib/format';

/**
 * Diálogos do caixa aberto: sangria, suprimento, lançamentos e fechamento.
 * Quem usa desenha os próprios botões e chama start / openEntries / startClose.
 */
const props = defineProps({
    session: { type: Object, required: true },
});

const movementType = ref(null);
const movementForm = useForm({ type: '', amount: '', reason: '' });
const movementTitle = computed(() => (movementType.value === 'withdrawal' ? 'Sangria' : 'Suprimento'));

function start(type) {
    // Depois de um envio com sucesso, reset() volta ao último valor enviado: é preciso limpar à mão.
    Object.assign(movementForm, { type, amount: '', reason: '' });
    movementForm.clearErrors();
    movementType.value = type;
}

function saveMovement() {
    movementForm.post(route('cash.movement'), {
        preserveScroll: true,
        onSuccess: () => (movementType.value = null),
    });
}

const closing = ref(false);
const closeForm = useForm({ counted_cash: '', closing_notes: '' });

function startClose() {
    closeForm.clearErrors();
    closing.value = true;
}

function closeCash() {
    closeForm.post(route('cash.close', props.session.id), { preserveScroll: true });
}

const entriesOpen = ref(false);
const entriesDialog = ref(null);

function openEntries() {
    entriesOpen.value = true;
}

watch(entriesOpen, (open) => (open ? entriesDialog.value?.showModal() : entriesDialog.value?.close()));
onMounted(() => entriesOpen.value && entriesDialog.value?.showModal());

defineExpose({ start, startClose, openEntries });
</script>

<template>
    <div class="contents">
        <ConfirmDialog
            :open="movementType !== null"
            :title="movementTitle"
            :message="movementType === 'withdrawal' ? 'Dinheiro que sai da gaveta.' : 'Dinheiro que entra na gaveta.'"
            :confirm-label="`Registrar ${movementTitle.toLowerCase()}`"
            :processing="movementForm.processing"
            @confirm="saveMovement"
            @cancel="movementType = null"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Valor (R$)" required :error="movementForm.errors.amount">
                    <DecimalInput :id="id" v-model="movementForm.amount" pad />
                </Field>
                <Field v-slot="{ id }" label="Motivo" required :error="movementForm.errors.reason" hint="Fica registrado na auditoria.">
                    <input :id="id" v-model="movementForm.reason" class="input" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="closing"
            title="Fechar caixa"
            message="Conte o dinheiro da gaveta e informe o valor. Pix e cartões entram automaticamente."
            confirm-label="Fechar caixa"
            :processing="closeForm.processing"
            @confirm="closeCash"
            @cancel="closing = false"
        >
            <div class="mt-4 space-y-4">
                <Field v-slot="{ id }" label="Dinheiro contado (R$)" required :error="closeForm.errors.counted_cash">
                    <DecimalInput :id="id" v-model="closeForm.counted_cash" pad />
                </Field>
                <Field v-slot="{ id }" label="Observação" :error="closeForm.errors.closing_notes" hint="Obrigatória se o valor não conferir.">
                    <input :id="id" v-model="closeForm.closing_notes" class="input" maxlength="255" />
                </Field>
            </div>
        </ConfirmDialog>

        <dialog
            ref="entriesDialog"
            class="bg-surface text-ink m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl p-0 shadow-xl backdrop:bg-black/40"
            aria-labelledby="cash-entries-title"
            @cancel.prevent="entriesOpen = false"
        >
            <div class="border-line bg-surface sticky top-0 z-10 flex items-center justify-between gap-3 border-b px-5 py-3">
                <div>
                    <h2 id="cash-entries-title" class="text-lg font-bold">Lançamentos do caixa #{{ session.number }}</h2>
                    <p class="text-muted text-sm">
                        Aberto em {{ session.opened_at }} com {{ formatMoney(session.opening_amount) }}
                        <template v-if="session.expected_cash !== null"> · Esperado na gaveta: {{ formatMoney(session.expected_cash) }}</template>
                    </p>
                </div>
                <button type="button" class="btn btn-ghost size-10 shrink-0 px-0" aria-label="Fechar lançamentos" @click="entriesOpen = false">
                    <X :size="18" />
                </button>
            </div>
            <div class="p-5">
                <CashEntries :movements="session.movements" :sales="session.sales" />
            </div>
        </dialog>
    </div>
</template>
