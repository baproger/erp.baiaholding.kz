<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TaskPanel from '@/Components/TaskPanel.vue';
import FinancePanel from '@/Components/FinancePanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import CustomFieldsPanel from '@/Components/CustomFieldsPanel.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import DealChat from '@/Components/DealChat.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';
import { UNITS, SOURCES } from '@/utils/dealOptions';
import { formatDate, formatDateTime, formatDuration, money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ deal: Object, stages: Array, users: Array, finance: Object, profit: Object, customFields: Array, history: Array, chatId: Number, can: Object, stageTask: Object, materials: { type: Array, default: () => [] }, balances: { type: Object, default: null }, workshops: { type: Array, default: () => [] }, stageLogs: { type: Array, default: () => [] }, preDeal: { type: Object, default: null } });

const tab = ref('finance');
const visibleFields = computed(() => (props.customFields ?? []).filter((f) => f.is_visible && f.value));
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLastStage = computed(() => props.deal.deal_stage_id === lastStage.value?.id);
// Спец-этапы ищем по названию/флагу — этапы можно перемещать в настройках.
// "Отправить в цех" — на этапе «Закуп ЛДСП,МДФ».
const workshopStage = computed(() => props.stages.find((s) => s.name?.toLowerCase().includes('закуп')) ?? props.stages[props.stages.length - 3]);
const isWorkshopStage = computed(() => props.deal.deal_stage_id === workshopStage.value?.id);
const actStage = computed(() => props.stages.find((s) => s.name?.toLowerCase().includes('акт')));
const esfStage = computed(() => props.stages.find((s) => s.name?.toLowerCase().includes('эсф')));
const wonStage = computed(() => props.stages.find((s) => s.is_won) ?? lastStage.value);
const preWonStage = computed(() => esfStage.value ?? actStage.value);
// Этапы АКТ/ЭСФ/Оплата двигает ТОЛЬКО бухгалтер (financist) или админ;
// менеджер и директор видят сделку, но эти этапы не переводят.
const canAccounting = computed(() => (usePage().props.auth.user?.roles ?? []).some((r) => ['admin', 'financist'].includes(r)));
// Гейт-галочку ставит роль гейта этапа (дизайнер/снабженец/бухгалтер) или админ.
const canConfirmGate = computed(() => {
    const roles = usePage().props.auth.user?.roles ?? [];
    return roles.includes('admin') || (props.stageTask?.role && roles.includes(props.stageTask.role));
});
const postActIds = computed(() => [actStage.value?.id, esfStage.value?.id, wonStage.value?.id].filter(Boolean));
const managerFrozen = computed(() => !canAccounting.value && postActIds.value.includes(props.deal.deal_stage_id));
const stageLocked = (stage) => {
    if (!stage) return false;
    if (managerFrozen.value) return true;
    if (!canAccounting.value && postActIds.value.includes(stage.id) && stage.id !== actStage.value?.id) return true;
    if (stage.id === esfStage.value?.id && props.deal.deal_stage_id !== actStage.value?.id) return true;
    // Won-этап открыт с «ЭСФ» и любого более позднего этапа (напр. «Оплата»).
    if (stage.id === wonStage.value?.id && currentStageIndex.value < props.stages.findIndex((s) => s.id === preWonStage.value?.id)) return true;
    return false;
};
const lockHint = (stage) => {
    if (managerFrozen.value) return 'После «Акт утверждение» сделку двигает только бухгалтер или админ';
    if (!canAccounting.value && postActIds.value.includes(stage.id) && stage.id !== actStage.value?.id) return 'Этот этап переводит только бухгалтер или админ';
    if (stage.id === esfStage.value?.id) return 'Сначала «Акт утверждение» (галочка акта, срок 3 дня)';
    return 'Сначала «ЭСФ»';
};
// С «ЭСФ» и дальше (Оплата, Тендер закрыт) просрочка снимается автоматически.
const pastEsf = computed(() => {
    const esfIdx = props.stages.findIndex((s) => s.id === esfStage.value?.id);
    return esfIdx !== -1 && currentStageIndex.value >= esfIdx;
});
const overdue = computed(() => !pastEsf.value && isPastDue(props.deal.deadline, props.deal.status === 'closed'));
// Чисто визуальное: индекс текущего этапа для галочек пройденных шагов и % воронки.
const currentStageIndex = computed(() => props.stages.findIndex((s) => s.id === props.deal.deal_stage_id));
const funnelProgress = computed(() => (props.stages.length > 1 ? (Math.max(0, currentStageIndex.value) / (props.stages.length - 1)) * 100 : 0));
// Remaining amount to be paid = deal sum − paid income (справочно; полная оплата для «Оплата успешно» не требуется).
const remainder = computed(() => Math.max(0, Number(props.deal.budget || 0) - Number(props.finance?.income || 0)));

const wonStageId = computed(() => props.stages.find((s) => s.is_won)?.id);
const moveStage = async (id) => {
    if (id === props.deal.deal_stage_id) return;
    // «Оплата успешно» is the final successful stage — confirm before leaving it.
    if (props.deal.deal_stage_id === wonStageId.value
        && ! (await confirmDialog({ title: 'Сделка уже успешна', message: 'Сделка на этапе «Оплата успешно». Точно перевести её на другой этап?', confirmText: 'Перевести', danger: true }))) return;
    router.patch(route('deals.stage', props.deal.id), { deal_stage_id: id }, { preserveScroll: true });
};
const advance = () => router.patch(route('deals.advance', props.deal.id), {}, { preserveScroll: true });
// У BAIA два цеха — сначала выбор («Металл цех» / «Ағаш цех»), у ASU сразу.
const showWorkshopPick = ref(false);
const sendToWorkshop = (w = null) => {
    if (!w && props.workshops.length > 1) { showWorkshopPick.value = true; return; }
    router.post(route('deals.toWorkshop', props.deal.id), { workshop: w ?? props.workshops[0] ?? null },
        { preserveScroll: true, onSuccess: () => (showWorkshopPick.value = false) });
};
const setResponsible = (e) => router.patch(route('deals.responsible', props.deal.id), { responsible_user_id: e.target.value || null }, { preserveScroll: true });
// Ручной % бонуса менеджера по сделке — меняет финансист/админ.
const editBonusRate = ref(false);
const bonusRateInput = ref(null);
const openBonusEdit = () => {
    bonusRateInput.value = props.deal.bonus_rate_override !== null ? Number(props.deal.bonus_rate_override) : Number(props.profit.bonusRate);
    editBonusRate.value = true;
};
const saveBonusRate = (auto = false) => {
    router.patch(route('deals.bonusRate', props.deal.id),
        { bonus_rate_override: auto ? null : bonusRateInput.value },
        { preserveScroll: true, onSuccess: () => (editBonusRate.value = false) });
};
const destroy = async () => {
    if (await confirmDialog({ title: 'Удалить сделку', message: 'Сделка будет удалена. Это действие необратимо.', confirmText: 'Удалить', danger: true })) {
        router.delete(route('deals.destroy', props.deal.id), { onSuccess: () => router.get(route('deals.index')) });
    }
};

// 👁 Модалка с полной информацией о предсделке (лоте), из которой выросла сделка.
const showPreDeal = ref(false);

const showEdit = ref(false);
// input[type=date] принимает только YYYY-MM-DD, а бэкенд отдаёт ISO-дату со временем.
const dateOnly = (v) => (v ?? '').slice(0, 10);
const editFields = () => ({
    company_name: props.deal.company_name ?? '', bin: props.deal.bin ?? '', client_name: props.deal.client_name ?? '',
    address: props.deal.address ?? '',
    contract_date: dateOnly(props.deal.contract_date), source: props.deal.source ?? '',
    lot_number: props.deal.lot_number ?? '', unit: props.deal.unit ?? '', budget: props.deal.budget, partner_pct: props.deal.partner_pct ?? '', deadline: dateOnly(props.deal.deadline),
    description: props.deal.description ?? '', note: props.deal.note ?? '',
});
const editForm = useForm(editFields());
const openEdit = () => {
    Object.assign(editForm, editFields());
    showEdit.value = true;
};
const saveEdit = () => editForm.put(route('deals.update', props.deal.id), { preserveScroll: true, onSuccess: () => (showEdit.value = false) });
// Галочка бухгалтера (Акт/ЭСФ выставлен) — закрывает задачу-блокировку этапа.
const confirmStageTask = () => router.patch(route('deals.stageTask', props.deal.id), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="deal.number" />
    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('deals.index')" class="inline-flex flex-shrink-0 items-center gap-1 text-sm font-medium text-slate-400 transition-colors duration-150 hover:text-slate-600">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    {{ $t('page.deals', 'Сделки') }}
                </Link>
                <!-- Длинные названия (НАО, ГУ…) обрезаются с многоточием, полное — в title -->
                <span class="min-w-0 truncate text-lg font-semibold tracking-tight text-slate-900 sm:text-xl" :title="deal.company_name || deal.name">{{ deal.company_name || deal.name }}</span>
                <span class="flex-shrink-0 whitespace-nowrap rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ deal.number }}</span>
                <span v-if="overdue" class="inline-flex flex-shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-700">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></svg>
                    {{ $t('deal.overdue_badge', 'Просрочено') }}
                </span>
            </div>
        </template>

        <PageLayout :title="`${deal.number} · ${deal.company_name || deal.name}`" :subtitle="stages[currentStageIndex]?.name">
        <template #actions>
            <!-- 👁 Предсделка: рядом с карандашом — полная информация о лоте в модалке -->
            <button v-if="preDeal" @click="showPreDeal = true" title="Информация о предсделке (лоте)"
                class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-gradient-to-br from-emerald-50/90 to-white px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors duration-150 hover:from-emerald-100/90">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                Предсделка
            </button>
            <button v-if="can.update" @click="openEdit" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5l3 3L7 16l-3.5.5L4 13z"/></svg>
                Изменить
            </button>
            <button v-if="can.delete" @click="destroy" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 transition-colors duration-150 hover:bg-rose-100">
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h12M8 6V4h4v2M6 6l.5 10h7l.5-10M8.5 9v4M11.5 9v4"/></svg>
                Удалить
            </button>
        </template>

        <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1">
                <button v-for="(stage, idx) in stages" :key="stage.id" @click="moveStage(stage.id)" :disabled="!can.update || stageLocked(stage)"
                    :title="stageLocked(stage) ? lockHint(stage) : ''"
                    :class="stage.id === deal.deal_stage_id
                        ? 'text-white shadow-md'
                        : currentStageIndex >= 0 && idx < currentStageIndex
                            ? 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-100 hover:bg-indigo-100'
                            : 'bg-white text-slate-500 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 hover:text-slate-700'"
                    :style="stage.id === deal.deal_stage_id ? { backgroundColor: stage.color } : {}"
                    class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-40">
                    <svg v-if="currentStageIndex >= 0 && idx < currentStageIndex" class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    {{ stage.name }}
                </button>
            </div>
            <!-- Прогресс воронки -->
            <div class="mt-2.5 h-1 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-400 to-indigo-600 transition-all duration-500 ease-out" :style="{ width: funnelProgress + '%' }"></div>
            </div>
            <!-- Галочка бухгалтера на этапах «Акт утверждение» / «ЭСФ» -->
            <div v-if="stageTask" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg px-3 py-2"
                :class="stageTask.done ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-amber-50 ring-1 ring-amber-200'">
                <span v-if="stageTask.done" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                    {{ stageTask.label }} — можно переводить дальше
                </span>
                <template v-else>
                    <span class="inline-flex items-center gap-2 text-sm font-semibold text-amber-800">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>
                        {{ stageTask.label }}
                    </span>
                    <span v-if="stageTask.due" class="text-xs font-medium text-amber-700">срок до {{ formatDate(stageTask.due) }}</span>
                    <button v-if="canConfirmGate" @click="confirmStageTask"
                        class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        Поставить галочку
                    </button>
                    <span v-else class="ml-auto text-xs font-medium text-amber-700">подтверждает {{ stageTask.roleLabel || 'бухгалтер' }}</span>
                </template>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <PrimaryButton v-if="(can.advance ?? can.update) && !isWorkshopStage && !isLastStage && !managerFrozen" @click="advance">Далее →</PrimaryButton>
                <button v-if="can.update && isWorkshopStage && (!deal.project || deal.project.status === 'completed')" @click="sendToWorkshop()" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-emerald-700">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                    Отправить в цех
                </button>
                <Link v-if="deal.project && deal.project.status !== 'completed'" :href="route('projects.show', deal.project.id)" class="inline-flex items-center rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100">В цеху: {{ deal.project.number }} →</Link>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <!-- Информация — компактная сетка -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Компания</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900">{{ deal.company_name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Адрес</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">📍 {{ deal.address || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Номер договора</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ deal.bin || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Наименование товара</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ deal.client_name || deal.client?.name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Количество</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ deal.lot_number ? `${deal.lot_number} ${deal.unit || ''}` : '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Дата договора</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ deal.contract_date ? formatDate(deal.contract_date) : '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Источник (портал)</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ deal.source || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Срок</div>
                            <div class="mt-1 text-sm font-medium" :class="deadlineClass(deal.deadline, deal.status==='closed' || pastEsf)">{{ formatDate(deal.deadline) }}<span v-if="overdue"> · просрочено</span></div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Ответственный</div>
                            <select :value="deal.responsible_user_id ?? ''" @change="setResponsible" class="mt-1 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">— не назначен —</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div v-for="f in visibleFields" :key="f.id">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ f.name }}</div>
                            <div class="mt-1 text-sm font-medium text-slate-900">{{ f.value }}</div>
                        </div>
                    </div>
                    <div v-if="deal.note" class="mt-6 rounded-xl bg-amber-50 px-4 py-3 ring-1 ring-inset ring-amber-100">
                        <div class="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5M9 10.8V5a3 3 0 0 1 6 0v5.8l2.7 1.6a2 2 0 0 1 1 1.7V15H5.3v-.9a2 2 0 0 1 1-1.7z"/></svg>
                            Заметка
                        </div>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ deal.note }}</p>
                    </div>
                    <div v-if="deal.description" class="mt-4">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400">Описание</div>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ deal.description }}</p>
                    </div>
                </div>

                        <!-- Из предварительной сделки: сразу перед Финансами — зелёное «стекло» (glassmorphism) -->
                <div v-if="preDeal" class="overflow-hidden rounded-2xl border border-emerald-200/70 bg-gradient-to-br from-emerald-50/90 via-white/60 to-emerald-100/50 shadow-sm backdrop-blur">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-emerald-100/80 bg-white/40 px-5 py-2.5">
                        <span class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-emerald-700">
                            ◧ Из предварительной сделки
                            <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold normal-case tracking-normal text-emerald-700 ring-1 ring-emerald-200">{{ preDeal.action }}</span>
                        </span>
                        <Link :href="route('preDeals.index')" class="text-xs font-semibold text-emerald-700 hover:underline">Все лоты →</Link>
                    </div>
                    <div class="grid gap-x-6 gap-y-2 px-5 py-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div v-if="preDeal.lot_number">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">№ лота</div>
                            <div class="font-semibold text-slate-800">{{ preDeal.lot_number }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Внёс</div>
                            <div class="font-semibold text-slate-800">{{ preDeal.manager ?? '—' }}<span class="ml-1 text-xs font-normal text-slate-400">{{ preDeal.created_at }}</span></div>
                        </div>
                        <div v-if="preDeal.tender_deadline">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Срок тендера</div>
                            <div class="font-semibold text-slate-800">{{ preDeal.tender_deadline }}</div>
                        </div>
                        <div v-if="preDeal.margin > 0">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Расчётная маржа лота</div>
                            <div class="font-semibold text-emerald-600">{{ preDeal.margin }}%</div>
                        </div>
                    </div>
                    <!-- Цифры лота: доставка/сборка уже перенесены в расходы сделки (подтверждены) -->
                    <div v-if="preDeal.purchase_price || preDeal.delivery || preDeal.assembly || preDeal.commission"
                        class="flex flex-wrap gap-2 border-t border-emerald-100/80 px-5 py-2.5 text-[11px]">
                        <span v-if="preDeal.purchase_price" class="rounded-full bg-white/70 px-2.5 py-1 text-slate-600 ring-1 ring-emerald-100">закуп <b class="tabular-nums">{{ money(preDeal.purchase_price) }}</b></span>
                        <span v-if="preDeal.delivery" class="rounded-full bg-white/70 px-2.5 py-1 text-slate-600 ring-1 ring-emerald-100">🚚 доставка, грузчики <b class="tabular-nums">{{ money(preDeal.delivery) }}</b></span>
                        <span v-if="preDeal.assembly" class="rounded-full bg-white/70 px-2.5 py-1 text-slate-600 ring-1 ring-emerald-100">🔧 сборка <b class="tabular-nums">{{ money(preDeal.assembly) }}</b></span>
                        <span v-if="preDeal.commission" class="rounded-full bg-white/70 px-2.5 py-1 text-slate-600 ring-1 ring-emerald-100">комиссия <b class="tabular-nums">{{ money(preDeal.commission) }}</b></span>
                    </div>
                    <p v-if="preDeal.comment" class="border-t border-emerald-100/80 px-5 py-2.5 text-sm text-slate-600">{{ preDeal.comment }}</p>
                </div>

                <!-- Финансы сразу под информацией — расход вводится без скролла вниз -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
                        <button :class="tab==='finance' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='finance'">Финансы</button>
                        <button :class="tab==='docs' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='docs'">Документы</button>
                        <button :class="tab==='custom' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='custom'">Доп. поля</button>
                        <button :class="tab==='history' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='history'">История</button>
                    </nav>
                    <FinancePanel v-if="tab==='finance'" :entity-type="'deal'" :entity-id="deal.id" :client-id="deal.client_id" :invoices="deal.invoices" :expenses="deal.expenses" :finance="finance" :materials="materials" :balances="balances" />
                    <DocumentPanel v-else-if="tab==='docs'" :documents="deal.documents" entity-type="deal" :entity-id="deal.id" />
                    <CustomFieldsPanel v-else-if="tab==='custom'" :fields="customFields" entity-type="deal" :entity-id="deal.id" />
                    <div v-else>
                        <!-- Тайминг этапов сделки: каждый шаг — когда, сколько заняло и кто перевёл -->
                        <div v-if="stageLogs.length" class="mb-5">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">⏱ Тайминг этапов</div>
                            <div class="space-y-1.5">
                                <div v-for="(l, i) in stageLogs" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm"
                                    :class="l.open ? 'bg-indigo-50' : 'bg-slate-50'">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span class="font-medium text-slate-800">{{ l.stage }}</span>
                                        <span v-if="l.open" class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">сейчас</span>
                                        <span v-if="l.mover" class="truncate text-[11px] text-slate-400">перевёл(а): {{ l.mover }}</span>
                                    </div>
                                    <div class="flex items-center gap-3 tabular-nums">
                                        <span class="text-xs text-slate-400">{{ formatDateTime(l.entered_at) }}<template v-if="l.left_at"> → {{ formatDateTime(l.left_at) }}</template></span>
                                        <b :class="l.open ? 'text-indigo-700' : 'text-slate-700'">{{ formatDuration(l.seconds) }}</b>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <HistoryPanel :history="history" />
                    </div>
                </div>

                <!-- Задачи -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="6" height="6" rx="1"/><path d="m4 16 2 2 4-4M11 6h10M11 11h10M11 18h10"/></svg>
                            Задачи <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-indigo-700">{{ deal.tasks.length }}</span>
                        </h3>
                    </div>
                    <div class="p-4">
                        <TaskPanel :tasks="deal.tasks" taskable-type="deal" :taskable-id="deal.id" :users="users" />
                    </div>
                </div>

                <!-- Документы (только если прикреплены) -->
                <div v-if="deal.documents.length" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            Документы <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-indigo-700">{{ deal.documents.length }}</span>
                        </h3>
                    </div>
                    <div class="p-6">
                        <DocumentPanel :documents="deal.documents" entity-type="deal" :entity-id="deal.id" />
                    </div>
                </div>

                <!-- Комментарии -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            Комментарии <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-indigo-700">{{ deal.comments.length }}</span>
                        </h3>
                    </div>
                    <div class="p-6">
                        <CommentPanel :comments="deal.comments" entity-type="deal" :entity-id="deal.id" />
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Сумма договора</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-900">{{ money(deal.budget) }}</div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Статус</span><StatusBadge :status="deal.status" /></div>
                        <div class="flex justify-between"><span class="text-slate-500">Аванс (оплачено)</span><span class="font-medium tabular-nums text-emerald-600">{{ money(finance.income) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Остаток к оплате</span><span class="font-bold tabular-nums" :class="remainder > 0 ? 'text-rose-600' : 'text-emerald-600'">{{ remainder > 0 ? money(remainder) : 'оплачено ✓' }}</span></div>
                        <div class="mt-2 border-t border-slate-100 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Расчёт прибыли</div>
                        <div class="flex justify-between"><span class="text-slate-500">Налог {{ profit.taxRate }}%</span><span class="font-medium tabular-nums text-rose-600">− {{ money(profit.tax) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Прочие расходы</span><span class="font-medium tabular-nums text-rose-600">− {{ money(profit.expense) }}</span></div>
                        <div v-if="profit.partnerPct != null" class="flex justify-between"><span class="text-slate-500">Доля партнёра {{ profit.partnerPct }}%</span><span class="font-medium tabular-nums text-rose-600">− {{ money(profit.partner) }}</span></div>
                        <div class="flex justify-between border-t border-slate-100 pt-2"><span class="text-slate-500">Остаток</span><span class="font-semibold tabular-nums text-slate-800">{{ money(profit.remainder) }}</span></div>
                        <!-- Бонус менеджера: авто-ступень от маржи или ручной % финансиста -->
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-1.5 text-slate-500">
                                ЗП сотрудника {{ profit.bonusRate }}%
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="profit.bonusManual ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'">{{ profit.bonusManual ? 'вручную' : 'авто' }}</span>
                                <button v-if="canAccounting" @click="openBonusEdit" title="Изменить % бонуса" class="text-slate-300 hover:text-indigo-500">✎</button>
                            </span>
                            <span class="font-medium tabular-nums text-emerald-600">− {{ money(profit.bonus) }}</span>
                        </div>
                        <div v-if="editBonusRate" class="rounded-lg bg-slate-50 p-2.5">
                            <div class="mb-1.5 text-[11px] text-slate-500">Ручной % бонуса по этой сделке (пусто/«Авто» — по ступеням маржи):</div>
                            <div class="flex items-center gap-1.5">
                                <input v-model="bonusRateInput" type="number" min="0" max="100" step="0.5"
                                    class="w-20 rounded-lg border-slate-200 py-1 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                                <span class="text-sm text-slate-500">%</span>
                                <button @click="saveBonusRate(false)" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-700">Сохранить</button>
                                <button @click="saveBonusRate(true)" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-white">Авто</button>
                                <button @click="editBonusRate = false" class="ml-auto text-slate-400 hover:text-slate-600">✕</button>
                            </div>
                        </div>
                        <div class="rounded-xl px-4 py-3 shadow-md" style="background-color: #1A3B5C">
                            <div class="text-[11px] uppercase tracking-wide text-white/60">Чистая прибыль компании</div>
                            <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-300">{{ money(profit.company) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Чат сделки — сразу на виду -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <svg class="h-4 w-4 text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/></svg>
                            Чат сделки
                        </h3>
                    </div>
                    <div class="p-6">
                        <DealChat :chat-id="chatId" />
                    </div>
                </div>

            </div>
        </div>

        <Modal :show="showEdit" @close="showEdit = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Изменить сделку</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div><InputLabel value="Название компании *" /><TextInput v-model="editForm.company_name" class="mt-1 w-full" /><InputError :message="editForm.errors.company_name" class="mt-1" /></div>
                    <div><InputLabel value="Номер договора" /><TextInput v-model="editForm.bin" class="mt-1 w-full" /><InputError :message="editForm.errors.bin" class="mt-1" /></div>
                    <div class="sm:col-span-2"><InputLabel value="Адрес *" /><TextInput v-model="editForm.address" class="mt-1 w-full" placeholder="Город, улица, дом" /><InputError :message="editForm.errors.address" class="mt-1" /></div>
                    <div><InputLabel value="Дата договора" /><TextInput v-model="editForm.contract_date" type="date" class="mt-1 w-full" /><InputError :message="editForm.errors.contract_date" class="mt-1" /></div>
                    <div>
                        <InputLabel value="Источник (портал)" />
                        <select v-model="editForm.source" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                            <option value="">—</option>
                            <option v-for="s in SOURCES" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <InputError :message="editForm.errors.source" class="mt-1" />
                    </div>
                    <div><InputLabel value="Наименование товара *" /><TextInput v-model="editForm.client_name" class="mt-1 w-full" /><InputError :message="editForm.errors.client_name" class="mt-1" /></div>
                    <div>
                        <InputLabel value="Количество" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="editForm.lot_number" type="number" min="0" step="any" class="w-1/2" />
                            <select v-model="editForm.unit" class="w-1/2 rounded-md border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="">ед. изм.</option>
                                <option v-for="u in UNITS" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                        <InputError :message="editForm.errors.unit || editForm.errors.lot_number" class="mt-1" />
                    </div>
                    <div><InputLabel value="Сумма договора *" /><TextInput v-model="editForm.budget" type="number" step="0.01" class="mt-1 w-full" /><InputError :message="editForm.errors.budget" class="mt-1" /></div>
                    <div>
                        <InputLabel value="Доля партнёра, %" /><TextInput v-model="editForm.partner_pct" type="number" min="0" max="100" step="0.01" class="mt-1 w-full" placeholder="0" />
                        <p v-if="Number(editForm.partner_pct) > 0 && Number(editForm.budget) > 0" class="mt-1 text-[11px] text-slate-400">= {{ money(Number(editForm.budget) * Number(editForm.partner_pct) / 100) }} партнёру (вычитается из остатка)</p>
                        <InputError :message="editForm.errors.partner_pct" class="mt-1" />
                    </div>
                    <div><InputLabel value="Срок" /><TextInput v-model="editForm.deadline" type="date" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2"><InputLabel value="Описание" /><textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"></textarea></div>
                    <div class="sm:col-span-2"><InputLabel value="Заметка (кратко)" /><textarea v-model="editForm.note" rows="2" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm transition duration-150 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showEdit = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
        <!-- 👁 Модалка предсделки: всё, что менеджер ввёл в лоте -->
        <Modal :show="showPreDeal" max-width="2xl" @close="showPreDeal = false">
            <div v-if="preDeal" class="p-6">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Предварительная сделка</h2>
                        <p class="mt-0.5 text-xs text-slate-400">лот, из которого выросла сделка {{ deal.number }} · внёс {{ preDeal.manager ?? '—' }} · {{ preDeal.created_at }}</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">{{ preDeal.action }}</span>
                </div>
                <div class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">№ лота</div><div class="font-semibold text-slate-800">{{ preDeal.lot_number || '—' }}</div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">№ договора</div><div class="font-semibold text-slate-800">{{ preDeal.contract_number || '—' }}</div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">Источник (портал)</div><div class="font-semibold text-slate-800">{{ preDeal.source || '—' }}</div></div>
                    <div class="sm:col-span-2"><div class="text-[11px] uppercase tracking-wide text-slate-400">Заказчик</div><div class="font-semibold text-slate-800">{{ preDeal.customer || '—' }}<span v-if="preDeal.bin" class="ml-1 text-xs font-normal text-slate-400">· БИН {{ preDeal.bin }}</span></div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">Срок тендера</div><div class="font-semibold" :class="preDeal.tender_deadline ? 'text-slate-800' : 'text-slate-300'">{{ preDeal.tender_deadline || '—' }}</div></div>
                    <div class="sm:col-span-3"><div class="text-[11px] uppercase tracking-wide text-slate-400">Товар</div><div class="font-semibold text-slate-800">{{ preDeal.product || '—' }}</div></div>
                    <div class="sm:col-span-3" v-if="preDeal.client_name || preDeal.client_phone"><div class="text-[11px] uppercase tracking-wide text-slate-400">Контакт клиента</div>
                        <div class="text-slate-800">{{ preDeal.client_name || '—' }}<a v-if="preDeal.client_phone" :href="'tel:' + preDeal.client_phone" class="ml-2 font-medium text-indigo-600 hover:underline">{{ preDeal.client_phone }}</a></div></div>
                </div>
                <!-- Расчёт лота — справочный: в расходы сделки не переносится -->
                <div class="mt-5 rounded-xl border border-emerald-200/70 bg-gradient-to-br from-emerald-50/90 via-white/60 to-emerald-100/50 p-4 backdrop-blur">
                    <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Расчёт лота (справочно)</div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-3">
                        <div class="flex justify-between"><span class="text-slate-500">Сумма договора</span><b class="tabular-nums text-slate-900">{{ money(preDeal.contract_sum) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Закуп</span><b class="tabular-nums text-slate-700">{{ money(preDeal.purchase_price) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Партнёр<template v-if="preDeal.partner_pct"> · {{ preDeal.partner_pct }}%</template></span><b class="tabular-nums text-slate-700">{{ money(preDeal.partner_sum) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">🚚 Доставка</span><b class="tabular-nums text-slate-700">{{ money(preDeal.delivery) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">🔧 Сборка / работа</span><b class="tabular-nums text-slate-700">{{ money(preDeal.assembly) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Комиссия</span><b class="tabular-nums text-slate-700">{{ money(preDeal.commission) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Налог</span><b class="tabular-nums text-rose-600">{{ money(preDeal.tax) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Остаток</span><b class="tabular-nums text-slate-900">{{ money(preDeal.remainder) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Маржа лота</span><b class="tabular-nums text-emerald-700">{{ preDeal.margin }}%</b></div>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400">Цифры лота — план менеджера. Реальные расходы вводятся в сделке вручную и подтверждаются бухгалтером.</p>
                </div>
                <p v-if="preDeal.comment" class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ preDeal.comment }}</p>
                <div class="mt-6 flex items-center justify-between gap-2">
                    <Link :href="route('preDeals.index')" class="text-xs font-semibold text-emerald-700 hover:underline">Все лоты →</Link>
                    <SecondaryButton @click="showPreDeal = false">Закрыть</SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Выбор цеха (BAIA: Металл / Ағаш) -->
        <Modal :show="showWorkshopPick" max-width="sm" @close="showWorkshopPick = false">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900">В какой цех отправить?</h3>
                <p class="mb-4 text-xs text-slate-400">У компании несколько цехов — у каждого своя воронка этапов.</p>
                <div class="space-y-2">
                    <button v-for="w in workshops" :key="w" @click="sendToWorkshop(w)"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-semibold text-slate-800 transition hover:border-emerald-400 hover:bg-emerald-50">{{ w }}</button>
                </div>
                <div class="mt-4 text-right">
                    <SecondaryButton @click="showWorkshopPick = false">Отмена</SecondaryButton>
                </div>
            </div>
        </Modal>
        </PageLayout>
    </AppLayout>
</template>
