<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({ byEmployee: Array, monthsFilter: Number, funnel: Array, byStatus: Object, monthly: Array, abc: Array, abcSummary: Object, conversion: Object, totals: Object });

const tab = ref('general');
const selected = ref(props.byEmployee?.[0] ?? null);
// Фильтр по сотрудникам: поиск по имени, список и выбор обновляются сразу.
const empSearch = ref('');
const filteredEmployees = computed(() => {
    const s = empSearch.value.trim().toLowerCase();
    const list = s ? props.byEmployee.filter((e) => (e.user ?? '').toLowerCase().includes(s)) : props.byEmployee;
    if (list.length && (!selected.value || !list.some((e) => e.uid === selected.value.uid))) selected.value = list[0];
    return list;
});
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
const initials = (name) => (name ?? '?').trim().charAt(0).toUpperCase();
// Colour a per-deal margin badge: healthy ≥ 40%, thin 20–40%, poor/negative below.
const marginClass = (m) => m >= 40 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : m >= 20 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200';
const maxFunnel = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));
const maxMonthly = computed(() => Math.max(1, ...props.monthly.flatMap((m) => [m.income, m.expense])));
const setMonths = (m) => router.get(route('analytics.index'), { months: m }, { preserveState: true, preserveScroll: true, replace: true });
const statusLabels = { draft: 'Черновик', active: 'Активные', closed: 'Закрыты', cancelled: 'Отменены' };

// Trend: last vs previous month (for KPI +/- indicators and sparkline).
const spark = (key) => props.monthly.map((m) => key === 'profit' ? (m.income - m.expense) : Number(m[key] ?? 0));
const trend = (key) => {
    const a = spark(key);
    if (a.length < 2) return 0;
    const prev = a[a.length - 2], last = a[a.length - 1];
    if (prev === 0) return last > 0 ? 100 : 0;
    return Math.round((last - prev) / Math.abs(prev) * 100);
};
</script>

<template>
    <Head title="Аналитика" />
    <AppLayout>
        <template #header>{{ $t('page.analytics', 'Аналитика') }}</template>

        <div class="mb-5 inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
            <button :class="tab==='general' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'" class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors" @click="tab='general'">Обзор</button>
            <button :class="tab==='employees' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:text-slate-900'" class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors" @click="tab='employees'">По сотрудникам</button>
        </div>

        <!-- ============ BENTO: ОБЗОР ============ -->
        <div v-show="tab==='general'" class="space-y-4">
            <!-- KPI row with trend + sparkline -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="k in [
                        { label: 'Сумма договоров', value: totals.budget, key: 'income', accent: 'text-slate-900', good: 'up' },
                        { label: 'Расходы', value: totals.expense, key: 'expense', accent: 'text-rose-600', good: 'down' },
                        { label: 'Чистая прибыль', value: totals.net, key: 'profit', accent: 'text-emerald-600', good: 'up' },
                    ]" :key="k.label" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-500">{{ k.label }}</span>
                        <span :class="(k.good==='up' ? trend(k.key)>=0 : trend(k.key)<=0) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                            class="rounded-md px-1.5 py-0.5 text-[11px] font-semibold tabular-nums">{{ trend(k.key)>=0 ? '+' : '' }}{{ trend(k.key) }}%</span>
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold tracking-tight" :class="k.accent">{{ money(k.value) }}</div>
                    <div class="mt-3 flex h-8 items-end gap-0.5">
                        <div v-for="(v, i) in spark(k.key)" :key="i" class="flex-1 rounded-sm bg-slate-100">
                            <div class="rounded-sm bg-slate-300" :style="{ height: Math.max(2, Math.abs(v) / maxMonthly * 32) + 'px' }"></div>
                        </div>
                    </div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">Конверсия</span>
                    <div class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">{{ conversion.rate }}%</div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-2 rounded-full bg-slate-900" :style="{ width: conversion.rate + '%' }"></div>
                    </div>
                    <div class="mt-2 text-xs text-slate-400">{{ conversion.won }} из {{ conversion.total }} сделок</div>
                </div>
            </div>

            <!-- Bento cells -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-6">
                <!-- Monthly (wide) -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-4">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">Доходы и расходы по месяцам</h3>
                        <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs">
                            <button v-for="m in [3, 6, 12]" :key="m" @click="setMonths(m)"
                                :class="monthsFilter === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900'"
                                class="rounded-md px-2.5 py-1 font-medium transition-colors">{{ m }} мес</button>
                        </div>
                    </div>
                    <div class="flex items-end justify-between gap-2" style="height: 180px">
                        <div v-for="m in monthly" :key="m.month" class="group flex flex-1 flex-col items-center justify-end gap-1">
                            <div class="flex w-full items-end justify-center gap-1" style="height: 144px">
                                <div class="w-2.5 rounded-md bg-emerald-500 transition-opacity group-hover:opacity-80 sm:w-3" :style="{ height: Math.max(2, m.income / maxMonthly * 144) + 'px' }" :title="'Доход: ' + money(m.income)"></div>
                                <div class="w-2.5 rounded-md bg-slate-300 transition-opacity group-hover:opacity-80 sm:w-3" :style="{ height: Math.max(2, m.expense / maxMonthly * 144) + 'px' }" :title="'Расход: ' + money(m.expense)"></div>
                            </div>
                            <span class="text-[10px] font-medium text-slate-400">{{ m.month.slice(5) }}</span>
                        </div>
                    </div>
                    <div class="mt-3 flex gap-4 text-xs text-slate-500">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-emerald-500"></span> Доход</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-slate-300"></span> Расход</span>
                    </div>
                </div>

                <!-- Deals by status -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-slate-900">Сделки по статусам</h3>
                    <div class="space-y-1">
                        <div v-for="(cnt, status) in byStatus" :key="status" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                            <span class="text-slate-600">{{ statusLabels[status] ?? status }}</span><span class="font-semibold tabular-nums text-slate-900">{{ cnt }}</span>
                        </div>
                        <div v-if="!Object.keys(byStatus).length" class="py-4 text-center text-sm text-slate-400">Нет данных</div>
                    </div>
                </div>

                <!-- Funnel -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-3">
                    <h3 class="mb-4 text-sm font-semibold text-slate-900">Воронка продаж</h3>
                    <div class="space-y-3">
                        <div v-for="f in funnel" :key="f.name">
                            <div class="mb-1 flex justify-between text-xs"><span class="text-slate-600">{{ f.name }}</span><span class="tabular-nums text-slate-400">{{ f.count }} · {{ money(f.total) }}</span></div>
                            <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full" :style="{ width: (f.count / maxFunnel * 100) + '%', backgroundColor: f.color }"></div></div>
                        </div>
                    </div>
                </div>

                <!-- ABC summary -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-3">
                    <h3 class="mb-1 text-sm font-semibold text-slate-900">ABC-анализ</h3>
                    <p class="mb-3 text-xs text-slate-400">По фактическому доходу: A ≈ 80%, B ≈ 15%, C ≈ 5%</p>
                    <div class="grid grid-cols-3 gap-2">
                        <div v-for="(s, cls) in abcSummary" :key="cls" class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                            <div class="flex items-center gap-1.5"><span class="flex h-5 w-5 items-center justify-center rounded-md text-[10px] font-bold text-white" :class="{ A: 'bg-emerald-600', B: 'bg-amber-500', C: 'bg-slate-400' }[cls]">{{ cls }}</span><span class="text-xs text-slate-500">{{ s.count }} сд.</span></div>
                            <div class="mt-1.5 text-sm font-semibold tabular-nums text-slate-900">{{ money(s.value) }}</div>
                        </div>
                    </div>
                </div>

                <!-- ABC table (full width) -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-6">
                    <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">Топ сделок по доходу</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr><th class="px-5 py-2.5">Класс</th><th class="px-5 py-2.5">Сделка</th><th class="px-5 py-2.5">Доход</th><th class="px-5 py-2.5">Доля</th><th class="px-5 py-2.5">Накопл.</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="row in abc" :key="row.number" class="hover:bg-slate-50">
                                    <td class="px-5 py-2.5"><span class="flex h-5 w-5 items-center justify-center rounded-md text-[10px] font-bold text-white" :class="{ A: 'bg-emerald-600', B: 'bg-amber-500', C: 'bg-slate-400' }[row.class]">{{ row.class }}</span></td>
                                    <td class="px-5 py-2.5"><span class="text-slate-400">{{ row.number }}</span> <span class="text-slate-700">{{ row.name }}</span></td>
                                    <td class="px-5 py-2.5 font-medium tabular-nums text-slate-900">{{ money(row.value) }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-slate-500">{{ row.share }}%</td>
                                    <td class="px-5 py-2.5 tabular-nums text-slate-400">{{ row.cumulative }}%</td>
                                </tr>
                                <tr v-if="!abc.length"><td colspan="5" class="px-5 py-8 text-center text-slate-400">Пока нет оплат</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ ПО СОТРУДНИКАМ ============ -->
        <div v-show="tab==='employees'" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Employee list -->
            <div class="space-y-2 lg:col-span-1">
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="empSearch" type="text" placeholder="Фильтр по сотруднику…"
                        class="w-full rounded-xl border-slate-200 py-2 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
                <button v-for="e in filteredEmployees" :key="e.uid" @click="selected = e"
                    :class="selected && selected.uid===e.uid ? 'border-slate-900 ring-1 ring-slate-900' : 'border-slate-200 hover:border-slate-300 hover:shadow-md'"
                    class="group flex w-full items-center gap-3 rounded-2xl border bg-white p-4 text-left shadow-sm transition-all">
                    <Avatar :name="e.user" :src="e.avatar" :size="44" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold text-slate-900">{{ e.user }}</div>
                        <div class="mt-0.5 flex items-center gap-1.5">
                            <span class="rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset" :class="marginClass(e.margin)">маржа {{ e.margin }}%</span>
                            <span class="text-[11px] text-slate-400">успешных {{ e.closed }}</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-bold tabular-nums text-emerald-600">{{ money(e.bonus) }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-slate-400">ЗП</div>
                    </div>
                </button>
                <div v-if="!filteredEmployees.length" class="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-400 shadow-sm">{{ byEmployee.length ? 'Никто не найден' : 'Нет данных' }}</div>
            </div>

            <!-- Detail -->
            <div v-if="selected" class="space-y-4 lg:col-span-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center gap-3">
                        <Avatar :name="selected.user" :src="selected.avatar" :size="48" />
                        <div>
                            <div class="text-base font-semibold text-slate-900">{{ selected.user }}</div>
                            <div class="text-xs text-slate-400">Маржа {{ selected.margin }}% · успешных сделок {{ selected.closed }}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div v-for="k in [
                                { l: 'Доход', v: money(selected.income), c: 'text-emerald-600' },
                                { l: 'Расход', v: money(selected.expense), c: 'text-rose-600' },
                                { l: 'Чистая прибыль', v: money(selected.net), c: 'text-slate-900' },
                                { l: 'Налог', v: money(selected.tax), c: 'text-rose-500' },
                                { l: 'Маржа', v: selected.margin + '%', c: 'text-slate-900' },
                                { l: 'ЗП', v: money(selected.bonus), c: 'text-emerald-600' },
                            ]" :key="k.l" class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ k.l }}</div>
                            <div class="mt-1 font-semibold tabular-nums" :class="k.c">{{ k.v }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div v-for="col in [
                            { t: 'Оплата успешно', items: selected.won_deals, dot: 'bg-emerald-500' },
                            { t: 'Акт утверждение', items: selected.act_deals, dot: 'bg-amber-500' },
                            { t: 'Просроченные', items: selected.overdue_deals, dot: 'bg-rose-500' },
                        ]" :key="col.t" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900"><span class="h-2 w-2 rounded-full" :class="col.dot"></span>{{ col.t }} <span class="text-slate-400">({{ col.items.length }})</span></h4>
                        <div class="space-y-1.5">
                            <Link v-for="d in col.items" :key="d.id" :href="route('deals.show', d.id)"
                                class="block rounded-xl border border-slate-100 px-3 py-2 text-xs transition-all hover:border-indigo-300 hover:bg-indigo-50/40 hover:shadow-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="min-w-0 flex-1 truncate font-medium text-slate-800">{{ d.company || d.number }}</span>
                                    <span class="flex-shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset" :class="marginClass(d.margin)">{{ d.margin }}%</span>
                                </div>
                                <div class="mt-1 flex items-center justify-between tabular-nums">
                                    <span class="text-slate-400">{{ money(d.budget) }}<span v-if="d.overdue_days" class="font-medium text-rose-500"> · {{ d.overdue_days }} дн.</span></span>
                                    <span class="text-slate-500">чист. {{ money(d.net) }}</span>
                                </div>
                            </Link>
                            <div v-if="!col.items.length" class="py-3 text-center text-xs text-slate-300">—</div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-400 shadow-sm lg:col-span-2">Выберите сотрудника</div>
        </div>
    </AppLayout>
</template>
