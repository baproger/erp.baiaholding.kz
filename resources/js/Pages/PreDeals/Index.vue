<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { assemblyLabel } from '@/utils/companyTerms';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { useStickyFilters } from '@/composables/useStickyFilters';
import TextInput from '@/Components/TextInput.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import { SOURCES } from '@/utils/dealOptions';
import { formatDate } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({
    preDeals: Array, minMargin: Number, taxPercent: Number,
    leadership: Boolean, stats: Array, managers: Array, filters: Object,
    byAction: { type: Array, default: () => [] },
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры: менеджер, статус и месяц внесения (какие лоты в какой день вводили).
const managerF = ref(props.filters?.manager ?? '');
const statusF = ref(props.filters?.status ?? '');
const monthF = ref(props.filters?.month ?? '');
const dayF = ref(props.filters?.day ?? '');      // конкретный день внесения (приоритет над месяцем)
const actionF = ref(props.filters?.action ?? '');
const applyFilters = () => router.get(route('preDeals.index'), {
    manager: managerF.value || undefined, status: statusF.value || undefined,
    month: monthF.value || undefined, day: dayF.value || undefined, action: actionF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
// Фильтр страницы запоминается: вернулся в предсделки — тот же месяц/день/действие.
useStickyFilters('pre-deals', { managerF, statusF, monthF, dayF, actionF }, applyFilters);

// Форма лота: живой расчёт как в Excel (партнёр/налог/остаток/маржа).
// «Сегодня + прошлые» как блок «Расходы» на Финансах: сегодняшние лоты сверху,
// прошлые — аккордеоном (свёрнуты, раскрываются кликом по заголовку).
const isToday = (d) => d && new Date(d).toDateString() === new Date().toDateString();
const showPast = ref(false);
const lotGroups = computed(() => {
    // Выбран месяц или день — секция на КАЖДУЮ дату внесения (свежие сверху), все раскрыты.
    if (monthF.value || dayF.value) {
        const byDate = new Map();
        for (const p of props.preDeals) {
            const k = (p.created_at || '').slice(0, 10);
            if (!byDate.has(k)) byDate.set(k, []);
            byDate.get(k).push(p);
        }
        return [...byDate.entries()]
            .sort((a, b) => b[0].localeCompare(a[0]))
            .map(([d, list]) => ({ key: d, label: formatDate(d), list, open: true, toggle: false }));
    }
    const today = props.preDeals.filter((p) => isToday(p.created_at));
    const past = props.preDeals.filter((p) => !isToday(p.created_at));
    return [
        { key: 'today', label: 'Сегодня', list: today, open: true, toggle: false },
        { key: 'past', label: 'Прошлые', list: past, open: showPast.value, toggle: true },
    ];
});

const showForm = ref(false);
const editingId = ref(null);
// Действие по лоту — тип записи, выбирается при создании:
// «Участие» (умолчание) считает маржу, «Звонок» и «КП» фиксируют только
// контакт и сумму — им расчёт не нужен.
const actionLabels = { participation: 'Участие', call: 'Звонок', offer: 'КП (ватсап)' };
const actionClass = (a) => a === 'call' ? 'bg-sky-50 text-sky-700'
    : a === 'offer' ? 'bg-indigo-50 text-indigo-700'
    : 'bg-emerald-50 text-emerald-700';
const SHORT_ACTIONS = ['call', 'offer'];

const form = useForm({ action: 'participation', comment: '', lot_number: '', contract_number: '', tender_deadline: '', bin: '', customer: '', source: '', client_name: '', client_phone: '', product: '', contract_sum: '', purchase_price: '', partner_pct: '', delivery: '', assembly: '', commission: '' });
// Короткая форма: у звонка и КП нет расчёта маржи.
const isShort = computed(() => SHORT_ACTIONS.includes(form.action));
// Срок окончания тендера: сегодня/прошёл у невыигранного лота — подсветка.
const tenderUrgent = (p) => p.tender_deadline && p.status === 'new' && new Date(p.tender_deadline) <= new Date(new Date().toDateString());
const calc = computed(() => {
    const sum = Number(form.contract_sum || 0);
    const partner = Math.round(sum * Number(form.partner_pct || 0)) / 100;
    const tax = Math.round(sum * (props.taxPercent ?? 3)) / 100;
    const remainder = Math.round((sum - Number(form.purchase_price || 0) - partner - Number(form.delivery || 0) - Number(form.assembly || 0) - Number(form.commission || 0) - tax) * 100) / 100;
    const margin = sum > 0 ? Math.round(remainder / sum * 10000) / 100 : 0;
    return { partner, tax, remainder, margin, pass: margin >= (props.minMargin ?? 15) };
});
// Кнопка «Проверить» у № лота: занят ли номер — ДО заполнения остальных полей.
const lotCheck = ref(null);      // null | {exists, manager, date, status}
const lotChecking = ref(false);
const checkLot = async () => {
    if (!form.lot_number || lotChecking.value) return;
    lotChecking.value = true;
    try {
        const { data } = await window.axios.get(route('preDeals.checkLot'), {
            params: { lot_number: form.lot_number, ignore: editingId.value || undefined },
        });
        lotCheck.value = data;
    } catch (e) { lotCheck.value = null; }
    lotChecking.value = false;
};

// Проверка № ДОГОВОРА: как у лота — дубликат показывается красным и блокируется валидацией.
const contractCheck = ref(null);
const contractChecking = ref(false);
const checkContract = async () => {
    if (!form.contract_number || contractChecking.value) return;
    contractChecking.value = true;
    try {
        const { data } = await window.axios.get(route('preDeals.checkLot'), {
            params: { lot_number: form.contract_number, ignore: editingId.value || undefined },
        });
        contractCheck.value = data;
    } catch (e) { contractCheck.value = null; }
    contractChecking.value = false;
};

const openCreate = () => { editingId.value = null; form.reset(); form.action = 'participation'; form.clearErrors(); lotCheck.value = null; contractCheck.value = null; showForm.value = true; };
const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    lotCheck.value = null;
    contractCheck.value = null;
    Object.assign(form, {
        action: p.action ?? 'participation', comment: p.comment ?? '',
        lot_number: p.lot_number ?? '', contract_number: p.contract_number ?? '', tender_deadline: p.tender_deadline ? p.tender_deadline.slice(0, 10) : '', bin: p.bin ?? '', customer: p.customer ?? '', source: p.source ?? '',
        client_name: p.client_name ?? '', client_phone: p.client_phone ?? '', product: p.product,
        contract_sum: Number(p.contract_sum), purchase_price: Number(p.purchase_price),
        partner_pct: Number(p.partner_pct), delivery: Number(p.delivery), assembly: Number(p.assembly), commission: Number(p.commission),
    });
    showForm.value = true;
};
const submit = () => (editingId.value
    ? form.put(route('preDeals.update', editingId.value), { preserveScroll: true, onSuccess: () => (showForm.value = false) })
    : form.post(route('preDeals.store'), { preserveScroll: true, onSuccess: () => (showForm.value = false) }));

// Откат случайного «Выиграл ✓»: сделка удалится, лот вернётся «В работе».
const revertDeal = async (p) => {
    if (!(await confirmDialog({ title: 'Вернуть в предварительные?', message: `Сделка ${p.deal?.number ?? ''} будет удалена, лот снова станет «В работе». Возможно, только пока по сделке нет счетов, расходов и заказа цеха.`, confirmText: '↩ Вернуть', danger: true }))) return;
    router.post(route('preDeals.revert', p.id), {}, { preserveScroll: true });
};

const confirmDeal = async (p) => {
    if (!(await confirmDialog({ title: 'Выиграл — создать сделку?', message: `«${p.product}» на ${money(p.contract_sum)} (маржа ${p.margin}%): лот отметится выигранным, сделка появится на странице «Сделки».`, confirmText: 'Выиграл ✓' }))) return;
    router.post(route('preDeals.confirm', p.id), {}, { preserveScroll: true });
};
const del = async (p) => {
    if (!(await confirmDialog({ title: 'Удалить лот?', message: `«${p.product}» будет удалён.`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('preDeals.destroy', p.id), { preserveScroll: true });
};

// Раскрытие строки: действие и комментарий по лоту.
const expanded = ref(null);
// 👁 Полная информация о лоте в модалке (кнопка-глаз рядом с карандашом).
const viewLot = ref(null);
// История лота (аудит): подгружается при открытии глаза.
const lotHistory = ref([]);
const lotHistoryLoading = ref(false);
watch(viewLot, async (p) => {
    lotHistory.value = [];
    if (!p) return;
    lotHistoryLoading.value = true;
    try {
        const r = await fetch(route('preDeals.history', p.id), { headers: { Accept: 'application/json' } });
        lotHistory.value = r.ok ? await r.json() : [];
    } catch (e) { lotHistory.value = []; } finally { lotHistoryLoading.value = false; }
});

const marginClass = (m) => Number(m) >= (props.minMargin ?? 15)
    ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600';
</script>

<template>
    <Head title="Предварительные сделки" />
    <AppLayout>
        <template #header>Предварительные сделки</template>

        <PageLayout title="Предварительные сделки" subtitle="лоты: маржа, действия, рейтинг" full>
            <template #actions>
                <label class="flex items-center gap-1 text-xs text-slate-400" title="Лоты, внесённые в конкретный день">день
                    <input v-model="dayF" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <label class="flex items-center gap-1 text-xs text-slate-400" title="Лоты, внесённые в выбранном месяце — по датам (если выбран день, он важнее)">месяц
                    <input v-model="monthF" @change="applyFilters" type="month" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <button v-if="monthF || dayF" @click="monthF = ''; dayF = ''; applyFilters()" class="text-xs font-medium text-indigo-600 hover:underline">сбросить</button>
                <select v-if="leadership" v-model="managerF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все менеджеры</option>
                    <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <select v-model="actionF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все действия</option>
                    <option v-for="(label, a) in actionLabels" :key="a" :value="a">{{ label }}</option>
                </select>
                <select v-model="statusF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все статусы</option>
                    <option value="new">В работе</option>
                    <option value="confirmed">Подтверждённые</option>
                </select>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">порог маржи: <b class="tabular-nums">{{ minMargin }}%</b></span>
                <PrimaryButton @click="openCreate">+ Предв. сделка</PrimaryButton>
            </template>

            <!-- Одна строка над таблицей, минимализм: слева — рейтинг менеджеров
                 (чипы: место, имя, выиграл/всего, сумма), справа — итог по действиям.
                 Клик по чипу менеджера / действия фильтрует лоты (повторный — снять). -->
            <div v-if="leadership && (stats?.length || byAction?.length)" class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-[11px] uppercase tracking-wide text-slate-400" title="По подтверждённым лотам за выбранный период">Рейтинг</span>
                    <button v-for="(m, i) in stats" :key="m.name" type="button"
                        @click="managerF = String(managerF) === String(m.id) ? '' : m.id; applyFilters()"
                        class="flex items-center gap-1.5 rounded-full border bg-white py-0.5 pl-1 pr-2.5 text-[11px] shadow-sm transition-colors hover:bg-slate-50"
                        :class="String(managerF) === String(m.id) ? 'border-indigo-400 ring-2 ring-indigo-500/20' : (i === 0 && m.confirmed > 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-200')"
                        :title="m.name + ': выиграл ' + m.confirmed + ' из ' + m.total + ' лотов на ' + money(m.sum) + (m.actions ? ' · ' + m.actions.map((a) => a.label + ' ' + a.total + (a.won ? ' ✓' + a.won : '')).join(', ') : '')">
                        <span class="w-4 text-center font-bold tabular-nums" :class="i === 0 && m.confirmed > 0 ? '' : 'text-slate-300'">{{ i === 0 && m.confirmed > 0 ? '👑' : i + 1 }}</span>
                        <Avatar :name="m.name" :src="m.avatar" :size="18" />
                        <span class="max-w-32 truncate font-medium text-slate-700">{{ m.name }}</span>
                        <span class="font-bold tabular-nums text-emerald-600">✓ {{ m.confirmed }}</span>
                        <span class="tabular-nums text-slate-400">/ {{ m.total }}</span>
                        <span class="tabular-nums text-slate-500">· {{ money(m.sum) }}</span>
                    </button>
                </div>
                <div v-if="byAction?.length" class="flex flex-wrap items-center gap-1.5">
                    <button v-for="a in byAction" :key="a.action" type="button"
                        @click="actionF = actionF === a.action ? '' : a.action; applyFilters()"
                        class="flex items-center gap-1.5 rounded-full border bg-white px-2.5 py-1 text-[11px] shadow-sm transition-colors hover:bg-slate-50"
                        :class="actionF === a.action ? 'border-indigo-400 ring-2 ring-indigo-500/20' : 'border-slate-200'"
                        :title="a.label + ': ' + a.total + ' лотов, выиграно ' + a.won + ' (' + a.conversion + '%) на ' + money(a.won_sum)">
                        <span class="rounded-full px-1.5 py-px font-medium" :class="actionClass(a.action)">{{ a.label }}</span>
                        <span class="tabular-nums text-slate-500">{{ a.total }}</span>
                        <span class="font-bold tabular-nums text-emerald-600">✓ {{ a.won }}</span>
                        <span class="tabular-nums text-slate-400">· {{ a.conversion }}%</span>
                    </button>
                </div>
            </div>
            <!-- Таблица лотов §7 -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full whitespace-nowrap text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr class="divide-x divide-slate-100">
                                <th class="px-6 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <span>№ лота</span>
                                        <!-- Кнопка создания прямо у колонки «№ лота» — чтобы жать не тянувшись в шапку -->
                                        <button @click="openCreate" title="Новая предварительная сделка"
                                            class="rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-bold normal-case tracking-normal text-white shadow-sm transition-colors hover:bg-indigo-700">+ Новая</button>
                                    </div>
                                </th>
                                <th class="px-4 py-2.5">Заказчик · товар</th>
                                <th class="px-4 py-2.5 text-right">Договор · закуп</th>
                                <th class="px-4 py-2.5">
                                    <div class="grid min-w-[24rem] grid-cols-5 gap-x-3 text-right">
                                        <span>Партнёр</span>
                                        <span>Доставка</span>
                                        <span>{{ assemblyLabel() }}</span>
                                        <span>Комиссия</span>
                                        <span>Налог</span>
                                    </div>
                                </th>
                                <th class="px-4 py-2.5 text-right">Остаток</th>
                                <th class="px-4 py-2.5 text-center">Маржа</th>
                                <th class="px-4 py-2.5 text-center">Чек-лист</th>
                                <th class="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <template v-for="g in lotGroups" :key="g.key">
                            <!-- Секция «Сегодня» / «Прошлые» (аккордеон) -->
                            <tr v-if="g.list.length || g.toggle" class="bg-slate-100/70 transition-colors duration-150" :class="g.toggle ? 'cursor-pointer select-none hover:bg-slate-200/60' : ''"
                                @click="g.toggle && (showPast = !showPast)">
                                <td colspan="8" class="px-6 py-2">
                                    <span class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                                        <svg v-if="g.toggle" class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200" :class="showPast ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        {{ g.label }} <span class="font-medium normal-case tracking-normal text-slate-400">{{ g.list.length }}</span>
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="g.key === 'today' && !g.list.length"><td colspan="8" class="px-6 py-4 text-center text-xs text-slate-400">Сегодня лотов ещё нет</td></tr>
                            <template v-if="g.open">
                            <template v-for="p in g.list" :key="p.id">
                                <tr class="group divide-x divide-slate-100 transition-colors duration-150 hover:bg-slate-50/60">
                                    <td class="px-6 py-2.5 text-slate-500">{{ p.lot_number || '—' }}<span class="block text-[11px] text-slate-400">{{ formatDate(p.created_at) }}</span>
                                        <span v-if="p.tender_deadline" class="block text-[11px] font-medium" :class="tenderUrgent(p) ? 'text-rose-600' : 'text-slate-400'">⏳ тендер до {{ formatDate(p.tender_deadline) }}</span>
                                    </td>
                                    <td class="max-w-56 px-4 py-2.5">
                                        <div class="truncate font-medium text-slate-800" :title="p.customer">{{ p.customer || '—' }}<span v-if="p.bin" class="text-xs text-slate-400"> · {{ p.bin }}</span></div>
                                        <div class="truncate text-[11px] text-slate-400" :title="p.product">{{ p.product }}<span v-if="p.source" class="ml-1.5 rounded-full bg-sky-50 px-1.5 py-0.5 font-medium text-sky-700">{{ p.source }}</span></div>
                                        <div v-if="leadership" class="truncate text-[11px] text-slate-500">👤 {{ p.user?.name ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <span class="font-semibold tabular-nums text-slate-800">{{ money(p.contract_sum) }}</span>
                                        <span class="block text-[11px] tabular-nums text-slate-400">закуп {{ money(p.purchase_price) }}</span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="grid min-w-[24rem] grid-cols-5 items-baseline gap-x-3 text-right text-[11px] tabular-nums">
                                            <span :class="Number(p.partner_sum) ? 'font-semibold text-slate-700' : 'text-slate-300'">
                                                {{ Number(p.partner_sum) ? money(p.partner_sum) : '—' }}
                                                <span v-if="Number(p.partner_pct)" class="block text-[9px] font-normal text-slate-400">{{ Number(p.partner_pct) }}%</span>
                                            </span>
                                            <span :class="Number(p.delivery) ? 'font-semibold text-slate-700' : 'text-slate-300'">{{ Number(p.delivery) ? money(p.delivery) : '—' }}</span>
                                            <span :class="Number(p.assembly) ? 'font-semibold text-slate-700' : 'text-slate-300'">{{ Number(p.assembly) ? money(p.assembly) : '—' }}</span>
                                            <span :class="Number(p.commission) ? 'font-semibold text-slate-700' : 'text-slate-300'">{{ Number(p.commission) ? money(p.commission) : '—' }}</span>
                                            <span :class="Number(p.tax) ? 'font-semibold text-slate-700' : 'text-slate-300'">{{ Number(p.tax) ? money(p.tax) : '—' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums" :class="Number(p.remainder) >= 0 ? 'text-slate-800' : 'text-rose-600'">{{ money(p.remainder) }}</td>
                                    <td class="px-4 py-2.5 text-center">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" :class="marginClass(p.margin)"
                                            :title="Number(p.margin) >= minMargin ? `Проходит порог ${minMargin}%` : `Ниже порога ${minMargin}%`">
                                            <span class="h-1.5 w-1.5 rounded-full" :class="Number(p.margin) >= minMargin ? 'bg-emerald-500' : 'bg-rose-500'"></span>{{ Number(p.margin) }}%
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button @click="expanded = expanded === p.id ? null : p.id"
                                            class="rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors duration-150" :class="actionClass(p.action)">
                                            {{ actionLabels[p.action] ?? '—' }}</button>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                        <button class="mr-1 rounded p-1 text-slate-300 transition-colors hover:text-emerald-600" title="Информация о предсделке" @click="viewLot = p">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <template v-if="p.status === 'confirmed'">
                                            <span class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700">→ {{ p.deal?.number ?? 'сделка' }}</span>
                                            <button class="ml-1 rounded p-1 text-slate-300 transition-colors hover:text-amber-600" title="Вернуть в предварительные (нажали «Выиграл» случайно)" @click="revertDeal(p)">↩</button>
                                        </template>
                                        <template v-else>
                                            <button v-if="Number(p.margin) >= minMargin" @click="confirmDeal(p)"
                                                class="rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100">Выиграл ✓</button>
                                            <span v-else class="text-[11px] text-rose-400" title="Маржа ниже порога — сделка отклонена">отклонена</span>
                                            <button class="ml-1 rounded p-1 text-slate-300 transition-colors hover:text-indigo-600" title="Изменить" @click="openEdit(p)">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                            </button>
                                            <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Удалить" @click="del(p)">✕</button>
                                        </template>
                                    </td>
                                </tr>
                                <!-- Комментарий + контакт клиента -->
                                <tr v-if="expanded === p.id" class="bg-slate-50/60">
                                    <td colspan="8" class="px-6 py-3">
                                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                            <span class="text-sm text-slate-600">
                                                <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="actionClass(p.action)">{{ actionLabels[p.action] ?? '—' }}</span>
                                                <span v-if="p.comment" class="ml-2">{{ p.comment }}</span>
                                                <span v-else class="ml-2 text-slate-400">без комментария</span>
                                            </span>
                                            <span v-if="p.client_name || p.client_phone" class="ml-auto text-sm text-slate-500">
                                                Клиент: <b class="text-slate-700">{{ p.client_name || '—' }}</b>
                                                <a v-if="p.client_phone" :href="'tel:' + p.client_phone" class="ml-2 font-medium text-indigo-600 hover:underline">{{ p.client_phone }}</a>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            </template>
                            </template>
                            <tr v-if="!preDeals.length"><td colspan="8" class="px-6 py-10 text-center text-sm text-slate-400">Пока нет предварительных сделок — «+ Предв. сделка»</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </PageLayout>

        <!-- 👁 Модалка просмотра лота: всё, что ввёл менеджер (зелёное «стекло» — расчёт справочный) -->
        <Modal :show="!!viewLot" max-width="2xl" @close="viewLot = null">
            <div v-if="viewLot" class="p-6">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Предварительная сделка</h2>
                        <p class="mt-0.5 text-xs text-slate-400">внёс {{ viewLot.user?.name ?? '—' }} · {{ formatDate(viewLot.created_at) }}<template v-if="viewLot.deal"> · сделка <Link :href="route('deals.show', viewLot.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ viewLot.deal.number }}</Link></template></p>
                    </div>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="actionClass(viewLot.action)">{{ actionLabels[viewLot.action] ?? '—' }}</span>
                </div>
                <div class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">№ лота</div><div class="font-semibold text-slate-800">{{ viewLot.lot_number || '—' }}</div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">№ договора</div><div class="font-semibold text-slate-800">{{ viewLot.contract_number || '—' }}</div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">Источник (портал)</div><div class="font-semibold text-slate-800">{{ viewLot.source || '—' }}</div></div>
                    <div class="sm:col-span-2"><div class="text-[11px] uppercase tracking-wide text-slate-400">Заказчик</div><div class="font-semibold text-slate-800">{{ viewLot.customer || '—' }}<span v-if="viewLot.bin" class="ml-1 text-xs font-normal text-slate-400">· БИН {{ viewLot.bin }}</span></div></div>
                    <div><div class="text-[11px] uppercase tracking-wide text-slate-400">Срок тендера</div><div class="font-semibold" :class="viewLot.tender_deadline ? 'text-slate-800' : 'text-slate-300'">{{ viewLot.tender_deadline ? formatDate(viewLot.tender_deadline) : '—' }}</div></div>
                    <div class="sm:col-span-3"><div class="text-[11px] uppercase tracking-wide text-slate-400">Товар</div><div class="font-semibold text-slate-800">{{ viewLot.product || '—' }}</div></div>
                    <div v-if="viewLot.client_name || viewLot.client_phone" class="sm:col-span-3"><div class="text-[11px] uppercase tracking-wide text-slate-400">Контакт клиента</div>
                        <div class="text-slate-800">{{ viewLot.client_name || '—' }}<a v-if="viewLot.client_phone" :href="'tel:' + viewLot.client_phone" class="ml-2 font-medium text-indigo-600 hover:underline">{{ viewLot.client_phone }}</a></div></div>
                </div>
                <div v-if="!SHORT_ACTIONS.includes(viewLot.action)" class="mt-5 rounded-xl border border-emerald-300/70 bg-gradient-to-br from-emerald-100/90 via-emerald-50/80 to-emerald-200/60 p-4 backdrop-blur">
                    <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Расчёт лота (справочно)</div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-3">
                        <div class="flex justify-between"><span class="text-slate-500">Сумма договора</span><b class="tabular-nums text-slate-900">{{ money(viewLot.contract_sum) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Закуп</span><b class="tabular-nums text-slate-700">{{ money(viewLot.purchase_price) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Партнёр<template v-if="Number(viewLot.partner_pct)"> · {{ Number(viewLot.partner_pct) }}%</template></span><b class="tabular-nums text-slate-700">{{ money(viewLot.partner_sum) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">🚚 Доставка</span><b class="tabular-nums text-slate-700">{{ money(viewLot.delivery) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">🔧 {{ assemblyLabel() }}</span><b class="tabular-nums text-slate-700">{{ money(viewLot.assembly) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Комиссия</span><b class="tabular-nums text-slate-700">{{ money(viewLot.commission) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Налог</span><b class="tabular-nums text-rose-600">{{ money(viewLot.tax) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Остаток</span><b class="tabular-nums text-slate-900">{{ money(viewLot.remainder) }}</b></div>
                        <div class="flex justify-between"><span class="text-slate-500">Маржа</span><b class="tabular-nums" :class="Number(viewLot.margin) >= minMargin ? 'text-emerald-700' : 'text-rose-600'">{{ Number(viewLot.margin) }}%</b></div>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400">Цифры лота — план менеджера, в расходы сделки не переносятся.</p>
                </div>
                <div v-else class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm"><span class="text-slate-500">Сумма договора</span> <b class="ml-2 tabular-nums text-slate-900">{{ money(viewLot.contract_sum) }}</b></div>
                <p v-if="viewLot.comment" class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ viewLot.comment }}</p>
                <!-- История: кто и что делал с лотом (из аудита) -->
                <div class="mt-5">
                    <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">История · кто что менял</div>
                    <div v-if="lotHistoryLoading" class="py-3 text-center text-xs text-slate-400">Загрузка…</div>
                    <HistoryPanel v-else-if="lotHistory.length" :history="lotHistory" />
                    <div v-else class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400">Записей пока нет — журнал ведётся с 25.08.2026</div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton v-if="viewLot.status !== 'confirmed'" @click="openEdit(viewLot); viewLot = null">Изменить</SecondaryButton>
                    <PrimaryButton @click="viewLot = null">Закрыть</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Модалка лота: живой расчёт §11 -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ editingId ? 'Изменить предварительную сделку' : 'Новая предварительная сделка' }}</h2>
                <!-- Действие: у звонка и КП только контакты и сумма, у участия — расчёт маржи -->
                <div class="mb-4">
                    <InputLabel value="Действие *" />
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        <button v-for="(label, a) in actionLabels" :key="a" type="button" @click="form.action = a"
                            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                            :class="form.action === a ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                            {{ label }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-[11px] text-slate-400">
                        {{ isShort ? 'Фиксируем контакт и сумму — расчёт маржи не нужен.' : 'Полный расчёт: закуп, доставка, сборка, комиссия и маржа.' }}
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-if="!isShort">
                        <InputLabel value="№ лота" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.lot_number" class="w-full" @input="lotCheck = null" @keydown.enter.prevent="checkLot" />
                            <button type="button" @click="checkLot" :disabled="!form.lot_number || lotChecking"
                                class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50 disabled:opacity-50">
                                {{ lotChecking ? '…' : 'Проверить' }}
                            </button>
                        </div>
                        <p v-if="lotCheck?.exists" class="mt-1 text-xs font-semibold text-rose-600">
                            ✗ Такой лот уже внесён<template v-if="lotCheck.manager"> — {{ lotCheck.manager }}</template><template v-if="lotCheck.date"> ({{ formatDate(lotCheck.date) }})</template><template v-if="lotCheck.status === 'confirmed'"> · уже выигран</template>
                        </p>
                        <p v-if="lotCheck?.deal" class="mt-1 text-xs font-semibold text-rose-600">
                            ✗ По этому номеру уже есть договор {{ lotCheck.deal.number }}<template v-if="lotCheck.deal.manager"> — {{ lotCheck.deal.manager }}</template><template v-if="lotCheck.deal.date"> ({{ formatDate(lotCheck.deal.date) }})</template>
                        </p>
                        <p v-else-if="lotCheck && !lotCheck.exists" class="mt-1 text-xs font-semibold text-emerald-600">✓ Свободен — можно заполнять</p>
                        <InputError :message="form.errors.lot_number" class="mt-1" />
                    </div>
                    <div v-if="!isShort">
                        <InputLabel value="№ договора" />
                        <div class="mt-1 flex gap-2">
                            <TextInput v-model="form.contract_number" class="w-full" @input="contractCheck = null" @keydown.enter.prevent="checkContract" />
                            <button type="button" @click="checkContract" :disabled="!form.contract_number || contractChecking"
                                class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50 disabled:opacity-50">
                                {{ contractChecking ? '…' : 'Проверить' }}
                            </button>
                        </div>
                        <p v-if="contractCheck?.deal" class="mt-1 text-xs font-semibold text-rose-600">
                            ✗ Такой номер договора уже есть — {{ contractCheck.deal.number }}<template v-if="contractCheck.deal.manager"> · {{ contractCheck.deal.manager }}</template><template v-if="contractCheck.deal.date"> ({{ formatDate(contractCheck.deal.date) }})</template>
                        </p>
                        <p v-else-if="contractCheck?.contract_lot" class="mt-1 text-xs font-semibold text-rose-600">
                            ✗ Этот № договора уже указан на другом лоте<template v-if="contractCheck.contract_lot.manager"> — {{ contractCheck.contract_lot.manager }}</template><template v-if="contractCheck.contract_lot.date"> ({{ formatDate(contractCheck.contract_lot.date) }})</template>
                        </p>
                        <p v-else-if="contractCheck" class="mt-1 text-xs font-semibold text-emerald-600">✓ Свободен — можно заполнять</p>
                        <InputError :message="form.errors.contract_number" class="mt-1" />
                    </div>
                    <div v-if="!isShort">
                        <InputLabel value="Срок окончания тендера" />
                        <TextInput v-model="form.tender_deadline" type="date" class="mt-1 w-full" />
                        <p class="mt-1 text-[11px] text-slate-400">В день окончания менеджеру придёт уведомление</p>
                        <InputError :message="form.errors.tender_deadline" class="mt-1" />
                    </div>
                    <div><InputLabel value="БИН заказчика" /><TextInput v-model="form.bin" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Заказчик (компания)" /><TextInput v-model="form.customer" class="mt-1 w-full" /></div>
                    <div>
                        <InputLabel value="Источник (портал)" />
                        <select v-model="form.source" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">—</option>
                            <option v-for="src in SOURCES" :key="src" :value="src">{{ src }}</option>
                        </select>
                        <InputError :message="form.errors.source" class="mt-1" />
                    </div>
                    <div><InputLabel value="Название товара *" /><TextInput v-model="form.product" class="mt-1 w-full" /><div v-if="form.errors.product" class="mt-1 text-xs text-red-600">{{ form.errors.product }}</div></div>
                    <div><InputLabel value="Имя клиента (контакт)" /><TextInput v-model="form.client_name" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Телефон клиента" /><TextInput v-model="form.client_phone" class="mt-1 w-full" placeholder="+7 ___ ___ __ __" /></div>
                    <div><InputLabel value="Сумма договора *" /><TextInput v-model="form.contract_sum" type="number" min="1" class="mt-1 w-full" /><div v-if="form.errors.contract_sum" class="mt-1 text-xs text-red-600">{{ form.errors.contract_sum }}</div></div>
                    <div v-if="!isShort"><InputLabel value="Закуп цена" /><TextInput v-model="form.purchase_price" type="number" min="0" class="mt-1 w-full" /></div>
                    <div v-if="!isShort"><InputLabel value="Доля партнёра, %" /><TextInput v-model="form.partner_pct" type="number" min="0" max="100" step="0.1" class="mt-1 w-full" /></div>
                    <div v-if="!isShort"><InputLabel value="Доставка, грузчики" /><TextInput v-model="form.delivery" type="number" min="0" class="mt-1 w-full" /></div>
                    <div v-if="!isShort"><InputLabel :value="assemblyLabel()" /><TextInput v-model="form.assembly" type="number" min="0" class="mt-1 w-full" /></div>
                    <div v-if="!isShort"><InputLabel value="Комиссия (ГЗ, Омаркет, Самрук)" /><TextInput v-model="form.commission" type="number" min="0" class="mt-1 w-full" /></div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Комментарий" />
                        <TextInput v-model="form.comment" class="mt-1 w-full" placeholder="Что обсудили, о чём договорились…" />
                        <InputError :message="form.errors.comment" class="mt-1" />
                    </div>
                </div>

                <!-- Живой расчёт: только у «Участия» -->
                <div v-if="!isShort" class="mt-4 rounded-xl border p-4" :class="calc.pass ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60'">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                        <span class="text-slate-500">Партнёр: <b class="tabular-nums text-slate-700">{{ money(calc.partner) }}</b></span>
                        <span class="text-slate-500">Налог {{ taxPercent }}%: <b class="tabular-nums text-slate-700">{{ money(calc.tax) }}</b></span>
                        <span class="text-slate-500">Остаток: <b class="tabular-nums" :class="calc.remainder >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(calc.remainder) }}</b></span>
                        <span class="ml-auto flex items-center gap-2">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" :class="calc.pass ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'">маржа {{ calc.margin }}%</span>
                            <span class="text-xs font-semibold" :class="calc.pass ? 'text-emerald-700' : 'text-rose-600'">{{ calc.pass ? 'выиграл — да' : 'ниже ' + minMargin + '% — отклоняется' }}</span>
                        </span>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? 'Сохранить' : 'Добавить' }}</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
