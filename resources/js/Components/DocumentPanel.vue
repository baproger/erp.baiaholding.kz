<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
    // Дизайнер (и руководство) прикрепляет смету — отдельная кнопка и бейдж.
    canEstimate: { type: Boolean, default: false },
});

const fileInput = ref(null);
const form = useForm({ documentable_type: props.entityType, documentable_id: props.entityId, name: '', kind: '', amount: '', file: null });
// Смета (ТЗ 02.11.2026): сначала сумма, потом файл — без суммы сервер не примет.
const estimateOpen = ref(false);
const pickEstimate = () => { if (!(Number(form.amount) > 0)) return; pick('estimate'); };

const pick = (kind = '') => { form.kind = kind; fileInput.value.click(); };
const onFile = (e) => {
    form.file = e.target.files[0];
    if (form.file) form.transform((d) => (d.kind === 'estimate' ? d : { ...d, amount: '' })).post(route('documents.store'), { preserveScroll: true, forceFormData: true, onSuccess: () => { form.reset('file', 'amount'); form.kind = ''; estimateOpen.value = false; } });
    e.target.value = '';
};
const remove = async (d) => { if (await confirmDialog({ title: 'Удалить документ', message: 'Документ будет удалён.', confirmText: 'Удалить', danger: true })) router.delete(route('documents.destroy', d.id), { preserveScroll: true }); };
const kb = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';
</script>

<template>
    <div class="space-y-3">
        <input ref="fileInput" type="file" class="hidden" @change="onFile" />
        <div class="flex flex-wrap items-center gap-2">
            <PrimaryButton :disabled="form.processing" @click="pick('')">{{ form.processing ? 'Загрузка…' : '+ Загрузить документ' }}</PrimaryButton>
            <button v-if="canEstimate && !estimateOpen" :disabled="form.processing" @click="estimateOpen = true"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 transition-colors hover:bg-amber-100 disabled:opacity-50"
                title="Смета дизайнера: сумма + файл (PDF/Excel/фото). Без сметы сделка не уходит с «Дизайн и расчет»">📐 Загрузить смету</button>
            <div v-if="canEstimate && estimateOpen" class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2">
                <span class="text-sm font-semibold text-amber-800">📐 Смета:</span>
                <input v-model="form.amount" type="number" min="1" step="0.01" placeholder="сумма сметы, ₸"
                    class="w-40 rounded-md border-amber-300 py-1.5 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500" @keydown.enter="pickEstimate" />
                <button type="button" :disabled="form.processing || !(Number(form.amount) > 0)" @click="pickEstimate"
                    class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:opacity-50">Выбрать файл</button>
                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" @click="estimateOpen = false; form.amount = ''">отмена</button>
            </div>
        </div>
        <div v-if="form.errors.amount || form.errors.file" class="text-sm text-rose-600">{{ form.errors.amount || form.errors.file }}</div>

        <div class="space-y-2">
            <div v-for="d in documents" :key="d.id" class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-500">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="height:18px;width:18px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                    </span>
                    <div class="min-w-0">
                        <a :href="route('documents.download', d.id)" class="font-medium text-indigo-600 transition-colors duration-150 hover:text-indigo-800 hover:underline">{{ d.name }}</a>
                        <span v-if="d.kind === 'estimate'" class="ml-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-700" title="Смета дизайнера">📐 смета<template v-if="d.amount"> · {{ Number(d.amount).toLocaleString('ru-RU') }} ₸</template></span>
                        <div class="text-xs text-slate-400">v{{ d.version }} · {{ kb(d.size) }} · {{ d.user?.name }}</div>
                    </div>
                </div>
                <button class="rounded p-1 text-slate-400 transition-colors duration-150 hover:text-rose-600" title="Удалить" @click="remove(d)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></svg>
                </button>
            </div>
            <div v-if="!documents.length" class="flex flex-col items-center gap-2 py-6 text-center">
                <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <span class="text-sm text-slate-400">Документов нет</span>
            </div>
        </div>
    </div>
</template>
