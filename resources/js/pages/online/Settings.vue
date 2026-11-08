<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import DecimalInput from '@/components/DecimalInput.vue';
import Field from '@/components/Field.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps({
    store: { type: Object, default: null },
});

const weekdays = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

const form = useForm({
    enabled: props.store?.enabled ?? false,
    slug: props.store?.slug ?? '',
    accepts_delivery: props.store?.accepts_delivery ?? true,
    accepts_pickup: props.store?.accepts_pickup ?? true,
    min_order: props.store?.min_order ?? '0',
    eta_text: props.store?.eta_text ?? '',
    whatsapp: props.store?.whatsapp ?? '',
    accept_minutes: props.store?.accept_minutes ?? 15,
    logo: null,
    remove_logo: false,
    hours: (props.store?.hours ?? []).map((shift) => ({ ...shift })),
});

const onLogoChange = (event) => {
    form.logo = event.target.files[0] ?? null;
    if (form.logo) {
        form.remove_logo = false;
    }
};

const save = () => {
    // Multipart só com arquivo anexado: o FormData descarta listas vazias.
    if (form.logo instanceof File) {
        form.transform((data) => ({ ...data, _method: 'put' }))
            .post(route('online.settings.update'), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.logo = null; } });
        return;
    }
    form.transform((data) => data).put(route('online.settings.update'), { preserveScroll: true });
};

const pauseReason = ref('');
const pausing = ref(false);
const pause = () => router.post(route('online.pause'), { reason: pauseReason.value }, {
    preserveScroll: true,
    onStart: () => { pausing.value = true; },
    onSuccess: () => { pauseReason.value = ''; },
    onFinish: () => { pausing.value = false; },
});
const resume = () => router.delete(route('online.resume'), {
    preserveScroll: true,
    onStart: () => { pausing.value = true; },
    onFinish: () => { pausing.value = false; },
});

const days = computed(() => weekdays.map((name, weekday) => ({
    name,
    weekday,
    shifts: form.hours.map((shift, index) => ({ shift, index })).filter(({ shift }) => shift.weekday === weekday),
})));

const addShift = (weekday) => form.hours.push({ weekday, opens_at: '18:00', closes_at: '23:00' });
const removeShift = (index) => form.hours.splice(index, 1);
const hoursError = computed(() => form.errors.hours
    ?? Object.entries(form.errors).find(([key]) => key.startsWith('hours.'))?.[1]);
</script>

<template>
    <AppLayout title="Loja online">
        <PageHeader title="Loja online" />

        <div v-if="store" class="card mb-4 max-w-2xl space-y-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-bold">Situação agora</h2>
                    <p class="text-sm font-semibold">{{ store.label }}</p>
                    <p v-if="store.paused" class="text-muted text-sm">
                        Pausada por {{ store.paused_by_name }}<span v-if="store.paused_reason"> · {{ store.paused_reason }}</span>
                    </p>
                </div>
                <button v-if="store.paused" type="button" class="btn btn-primary" :disabled="pausing" @click="resume">Reabrir</button>
            </div>
            <form v-if="!store.paused" class="flex flex-wrap items-center gap-2" @submit.prevent="pause">
                <input v-model="pauseReason" class="input flex-1" maxlength="200" placeholder="Motivo (opcional)" aria-label="Motivo da pausa" />
                <button type="submit" class="btn" :disabled="pausing">Pausar loja</button>
            </form>
        </div>

        <form class="max-w-2xl space-y-4" @submit.prevent="save">
            <div class="card space-y-4 p-5">
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input v-model="form.enabled" type="checkbox" class="accent-brand mt-0.5 size-4" />
                    <span>
                        <span class="block font-semibold">Loja aberta para pedidos</span>
                        <span class="text-muted">Desmarque para tirar a loja do ar.</span>
                    </span>
                </label>

                <div class="space-y-2">
                    <Field v-slot="{ id }" label="Logo da loja" :error="form.errors.logo" hint="JPG, PNG ou WebP, até 1 MB e 2000 x 2000 px.">
                        <input :id="id" type="file" class="input" accept="image/jpeg,image/png,image/webp" @change="onLogoChange" />
                    </Field>
                    <div v-if="store?.logo_url && !form.remove_logo && !form.logo" class="flex items-center gap-3">
                        <img :src="store.logo_url" alt="Logo atual" class="size-16 rounded-lg border object-cover" />
                        <button type="button" class="btn" @click="form.remove_logo = true">Remover</button>
                    </div>
                    <p v-if="form.remove_logo" class="text-muted text-sm">
                        O logo será removido ao salvar.
                        <button type="button" class="underline" @click="form.remove_logo = false">Desfazer</button>
                    </p>
                </div>

                <Field v-slot="{ id }" label="Endereço da loja" :error="form.errors.slug" hint="Apenas letras minúsculas, números e hífen.">
                    <input :id="id" v-model="form.slug" class="input" required minlength="3" maxlength="60" />
                </Field>
                <p class="text-muted text-sm">Link público: <span class="font-mono">/loja/{{ form.slug || 'sua-loja' }}</span></p>

                <div class="space-y-2">
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                        <input v-model="form.accepts_delivery" type="checkbox" class="accent-brand size-4" />
                        Aceita entrega
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                        <input v-model="form.accepts_pickup" type="checkbox" class="accent-brand size-4" />
                        Aceita retirada
                    </label>
                    <p v-if="form.errors.accepts_delivery" class="text-danger text-sm">{{ form.errors.accepts_delivery }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Field v-slot="{ id }" label="Pedido mínimo (R$)" :error="form.errors.min_order">
                        <DecimalInput :id="id" v-model="form.min_order" />
                    </Field>
                    <Field v-slot="{ id }" label="Prazo para aceitar (min)" :error="form.errors.accept_minutes" hint="De 5 a 120 minutos.">
                        <input :id="id" v-model.number="form.accept_minutes" class="input" type="number" min="5" max="120" required />
                    </Field>
                    <Field v-slot="{ id }" label="Previsão de entrega" :error="form.errors.eta_text">
                        <input :id="id" v-model="form.eta_text" class="input" maxlength="60" placeholder="Ex.: 40 a 60 min" />
                    </Field>
                    <Field v-slot="{ id }" label="WhatsApp" :error="form.errors.whatsapp">
                        <input :id="id" v-model="form.whatsapp" class="input" inputmode="tel" maxlength="20" />
                    </Field>
                </div>
            </div>

            <div class="card p-5">
                <h2 class="mb-1 font-bold">Horários</h2>
                <p class="text-muted mb-4 text-sm">Se o fechamento for menor que a abertura, o turno termina na madrugada do dia seguinte.</p>
                <div class="divide-y">
                    <div v-for="day in days" :key="day.weekday" class="flex flex-wrap items-start gap-3 py-2.5">
                        <span class="w-24 pt-2 text-sm font-semibold">{{ day.name }}</span>
                        <div class="flex-1 space-y-2">
                            <p v-if="day.shifts.length === 0" class="text-muted pt-2 text-sm">Fechado</p>
                            <div v-for="{ shift, index } in day.shifts" :key="index" class="flex items-center gap-2">
                                <input v-model="shift.opens_at" type="time" class="input w-32" required :aria-label="`Abertura ${day.name}`" />
                                <span class="text-muted text-sm">às</span>
                                <input v-model="shift.closes_at" type="time" class="input w-32" required :aria-label="`Fechamento ${day.name}`" />
                                <button type="button" class="btn" @click="removeShift(index)">Remover</button>
                            </div>
                        </div>
                        <button type="button" class="btn" @click="addShift(day.weekday)">Adicionar turno</button>
                    </div>
                </div>
                <p v-if="hoursError" class="text-danger mt-3 text-sm">{{ hoursError }}</p>
            </div>

            <button type="submit" class="btn btn-primary" :disabled="form.processing">Salvar</button>
        </form>
    </AppLayout>
</template>
