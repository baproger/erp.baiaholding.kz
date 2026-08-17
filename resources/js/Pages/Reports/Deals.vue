<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { useStickyFilters, clearStickyFilters } from '@/composables/useStickyFilters';
import { assemblyLabel } from '@/utils/companyTerms';

const props = defineProps({ rows: Array, byManager: { type: Array, default: () => [] }, byStage: { type: Array, default: () => [] }, isLeadership: { type: Boolean, default: true }, totals: Object, taxRate: Number, filters: Object, managers: Array, stageOptions: Array });

const money = (v) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v ?? 0));
const money0 = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Серверные фильтры: поиск, период (по дате создания), менеджер, этап.
const search = ref(props.filters?.search ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const manager = ref(props.filters?.manager ?? '');
const stageF = ref(props.filters?.stage ?? '');
const apply = () => router.get(route('reports.deals'), {
    search: search.value || undefined, from: from.value || undefined, to: to.value || undefined,
    manager: manager.value || undefined, stage: stageF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
let searchTimer = null;
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const hasFilters = () => search.value || from.value || to.value || manager.value || stageF.value;
const reset = () => {
    search.value = ''; from.value = ''; to.value = ''; manager.value = ''; stageF.value = '';
    clearStickyFilters('reports.deals');
    apply();
};
// Фильтр страницы запоминается: вернулся в отчёт — тот же период и менеджер.
useStickyFilters('reports.deals', { search, from, to, manager, stageF }, apply);

// «Месяц» — обёртка над периодом: YYYY-MM ⇢ первое/последнее число месяца.
// Показывается выбранным, только если период РОВНО совпадает с границами месяца.
const monthValue = computed(() => {
    if (!from.value || !to.value) return '';
    const f = new Date(from.value), t = new Date(to.value);
    const first = new Date(f.getFullYear(), f.getMonth(), 1);
    const last = new Date(f.getFullYear(), f.getMonth() + 1, 0);
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return iso(f) === iso(first) && iso(t) === iso(last) ? from.value.slice(0, 7) : '';
});
const setMonth = (ym) => {
    if (!ym) { from.value = ''; to.value = ''; return apply(); }
    const [y, m] = ym.split('-').map(Number);
    from.value = `${ym}-01`;
    to.value = `${ym}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
    apply();
};

// Сводная по МОП сворачивается — блок над таблицей сделок.
const summaryOpen = ref(true);

// Выбор сотрудника: менеджеры отдела продаж — сверху и всегда развёрнуты,
// остальные — по отделам, свёрнуты (раскрываются кликом).
const pickerOpen = ref(false);
const openDepts = ref(new Set());
const salesManagers = computed(() => props.managers.filter((m) => m.is_manager));
const otherGroups = computed(() => {
    const groups = {};
    props.managers.filter((m) => !m.is_manager).forEach((m) => {
        const key = m.department ?? 'Без отдела';
        (groups[key] ??= []).push(m);
    });
    return Object.entries(groups).map(([name, items]) => ({ name, items }))
        .sort((a, b) => (a.name === 'Без отдела') - (b.name === 'Без отдела') || a.name.localeCompare(b.name, 'ru'));
});
const toggleDept = (name) => {
    const s = new Set(openDepts.value);
    s.has(name) ? s.delete(name) : s.add(name);
    openDepts.value = s;
};
const selectedManagerName = computed(() => props.managers.find((m) => m.id === Number(manager.value))?.name ?? 'Все менеджеры');
const pickManager = (id) => { manager.value = id; pickerOpen.value = false; apply(); };
const closePicker = (e) => { if (!e.target.closest?.('.manager-picker')) pickerOpen.value = false; };
onMounted(() => document.addEventListener('click', closePicker));
onUnmounted(() => document.removeEventListener('click', closePicker));

const openDeal = (id) => router.get(route('deals.show', id));
// Цвет маржи — та же шкала, что на Аналитике: ≥40 здоровая, 20–40 тонкая, ниже — плохая.
// Минусовая маржа (проект в убыток) — сплошной красный, отличим от просто низкой.
const marginBadge = (m) => m < 0 ? 'bg-rose-600 text-white ring-rose-600'
    : m >= 40 ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : m >= 20 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-rose-50 text-rose-700 ring-rose-200';
const paidPct = (r) => r.budget > 0 ? Math.min(100, Math.round(r.paid / r.budget * 100)) : 0;
// ЭСФ и won просрочкой не считаются; Акт утверждение — считается.
const isOverdue = (r) => r.deadline && !r.is_won && !r.is_esf && new Date(r.deadline) < new Date(new Date().toDateString());
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('ru-RU') : '—';
// Подсветка строк: won и Акт/ЭСФ — зелёный градиент (успешная / вот-вот);
// Логистика/Сборка — жёлтый; остальные — обычные.
const rowClass = (r) => (r.is_won || r.is_pending_won)
    ? 'row-won bg-gradient-to-r from-emerald-100/90 via-emerald-50/60 to-emerald-50/10 hover:from-emerald-100 hover:via-emerald-50'
    : r.is_logistics
        ? 'row-logistics bg-gradient-to-r from-amber-100/80 via-amber-50/50 to-amber-50/10 hover:from-amber-100 hover:via-amber-50'
        : 'hover:bg-slate-50';
// Доля от общей суммы договоров — как процентная строка в Excel-отчёте.
const share = (v) => props.totals.budget > 0 ? (v / props.totals.budget * 100).toFixed(1) + '%' : '—';
</script>

<template>
    <Head title="Сводный отчет" />
    <AppLayout>
        <template #header>{{ isLeadership ? 'Сводный отчет' : 'Мой отчёт' }}</template>
        <PageLayout :title="isLeadership ? 'Сводный отчёт' : 'Мой отчёт'" subtitle="сделки и цех за период" full>
        <!-- Фильтры: поиск, период, менеджер, этап (серверные) -->
        <template #actions>
            <div class="relative w-full sm:w-60">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="search" @input="onSearch" type="text" placeholder="Поиск: №, компания, договор, адрес…"
                    class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </div>
            <!-- Месяц: быстрый выбор периода (раскрывается в «с … по …» ниже) —
                 та же логика, что «Месяц» на Финансах: по дате договора. -->
            <label class="flex items-center gap-1 text-xs text-slate-400">мес.
                <input :value="monthValue" @change="setMonth($event.target.value)" type="month"
                    class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">с
                <input v-model="from" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <label class="flex items-center gap-1 text-xs text-slate-400">по
                <input v-model="to" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" />
            </label>
            <!-- Выбор сотрудника: менеджеры сверху, остальные отделы — свёрнуты.
                 МОПу не показываем: отчёт и так только по его сделкам. -->
            <div v-if="isLeadership" class="manager-picker relative w-full sm:w-auto">
                <button type="button" @click="pickerOpen = !pickerOpen"
                    class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 shadow-sm sm:w-52">
                    <span class="truncate">{{ selectedManagerName }}</span>
                    <span class="text-slate-400">{{ pickerOpen ? '▲' : '▼' }}</span>
                </button>
                <div v-if="pickerOpen" class="absolute z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                    <button type="button" @click="pickManager('')"
                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50" :class="!manager ? 'font-semibold text-indigo-600' : 'text-slate-700'">Все менеджеры</button>
                    <div class="mt-1 border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400">Менеджеры (отдел продаж)</div>
                    <button v-for="m in salesManagers" :key="m.id" type="button" @click="pickManager(m.id)"
                        class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                        :class="Number(manager) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
                    <div v-if="!salesManagers.length" class="px-3 py-1.5 text-xs text-slate-400">Нет менеджеров</div>
                    <!-- Остальные отделы: свёрнуты, раскрываются кликом -->
                    <template v-for="g in otherGroups" :key="g.name">
                        <button type="button" @click="toggleDept(g.name)"
                            class="mt-1 flex w-full items-center justify-between border-t border-slate-100 px-3 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wide text-slate-400 hover:text-slate-600">
                            {{ g.name }} ({{ g.items.length }})
                            <span>{{ openDepts.has(g.name) ? '▲' : '▼' }}</span>
                        </button>
                        <template v-if="openDepts.has(g.name)">
                            <button v-for="m in g.items" :key="m.id" type="button" @click="pickManager(m.id)"
                                class="block w-full px-3 py-1.5 text-left text-sm hover:bg-indigo-50"
                                :class="Number(manager) === m.id ? 'font-semibold text-indigo-600' : 'text-slate-700'">{{ m.name }}</button>
                        </template>
                    </template>
                </div>
            </div>
            <select v-model="stageF" @change="apply" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm sm:w-auto">
                <option value="">Все этапы</option>
                <option v-for="s in stageOptions" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <button v-if="hasFilters()" @click="reset"
                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-600">Сбросить ✕</button>
        </template>

        <!-- Итоги: плитки-итоги (§5) -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <div class="rise rounded-xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 0ms">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Сумма договоров</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ money0(totals.budget) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ totals.count }} сделок · 100%</div>
            </div>
            <div class="rise rounded-xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 40ms">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Оплачено</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ money0(totals.paid) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.paid) }} от договоров</div>
            </div>
            <div class="rise rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm" style="animation-delay: 80ms">
                <div class="text-[11px] uppercase tracking-wide text-rose-500">Расходы</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-rose-600">−{{ money0(totals.material + (totals.delivery ?? 0) + (totals.purchase ?? 0) + (totals.assembly ?? 0) + totals.other) }}</div>
                <div class="mt-0.5 text-[11px] text-rose-500">склад {{ money0(totals.material) }} · 🚚 {{ money0(totals.delivery ?? 0) }} · 📦 {{ money0(totals.purchase ?? 0) }} · 🔧 {{ money0(totals.assembly ?? 0) }} · прочие {{ money0(totals.other) }}</div>
            </div>
            <div class="rise rounded-xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 120ms">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Налог {{ taxRate }}%</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-rose-600">−{{ money0(totals.tax) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.tax) }} от договоров</div>
            </div>
            <div class="rise rounded-xl border border-slate-200 bg-white p-4 shadow-sm" style="animation-delay: 160ms">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ isLeadership ? 'Бонусы менеджеров' : 'Мой бонус' }}</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ money0(totals.bonus) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">{{ share(totals.bonus) }} от договоров</div>
            </div>
            <!-- Доход компании и маржа — только руководству: МОП видит свой оборот
                 и свой бонус, прибыль фирмы по его сделкам ему не показываем. -->
            <div v-if="isLeadership" class="rise rounded-xl p-4 shadow-md" style="animation-delay: 200ms; background-color: #1A3B5C">
                <div class="text-[11px] uppercase tracking-wide text-white/60">Доход</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-300">{{ money0(totals.company) }}</div>
                <div class="mt-0.5 text-[11px] text-white/60">маржа {{ totals.margin }}%</div>
            </div>
        </div>

        <!-- «На каком этапе стоят сделки»: сколько штук и на какую сумму висит
             на каждом этапе. Клик по плитке фильтрует таблицу по этому этапу. -->
        <div v-if="byStage.length" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">{{ isLeadership ? 'Сделки по этапам' : 'Мои сделки по этапам' }}</h3>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ byStage.length }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                <button v-for="s in byStage" :key="s.stage_id ?? 0" type="button"
                    class="rise rounded-xl border bg-white p-4 text-left shadow-sm transition-shadow hover:shadow-md"
                    :class="Number(stageF) === s.stage_id ? 'border-indigo-400 ring-2 ring-indigo-500/20' : 'border-slate-200'"
                    :title="'Показать только сделки на этапе «' + s.stage + '»'"
                    @click="stageF = Number(stageF) === s.stage_id ? '' : s.stage_id; apply()">
                    <div class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: s.color || '#94a3b8' }"></span>
                        <span class="truncate text-[11px] uppercase tracking-wide text-slate-400">{{ s.stage }}</span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-xl font-bold tabular-nums" :class="s.is_won ? 'text-emerald-600' : 'text-slate-800'">{{ s.count }}</span>
                        <span class="text-[11px] text-slate-400">сделок</span>
                    </div>
                    <div class="mt-0.5 truncate whitespace-nowrap text-[11px] tabular-nums text-slate-400">{{ money0(s.budget) }}</div>
                </button>
            </div>
        </div>

        <!-- Сводная по МОП: строка = менеджер (а не сделка). Считается из тех же
             сделок, что и таблица ниже, — с тем же фильтром и теми же итогами.
             У МОПа тут ровно одна строка — его собственная. -->
        <div v-if="byManager.length" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <button type="button" @click="summaryOpen = !summaryOpen"
                class="flex w-full flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4 text-left transition-colors duration-150 hover:bg-slate-50">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    <svg class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200" :class="summaryOpen ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                    {{ isLeadership ? 'Сводная по МОП' : 'Мои показатели за период' }}
                    <span v-if="isLeadership" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ byManager.length }} чел.</span>
                </span>
                <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-700">
                    оборот {{ money0(totals.budget) }}
                </span>
            </button>
            <div v-if="summaryOpen" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr class="divide-x divide-slate-100">
                            <th class="whitespace-nowrap px-6 py-2.5">Менеджер</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Сделок</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Выиграл</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Оборот</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Оплачено</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Расходы</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Налог</th>
                            <th class="whitespace-nowrap px-4 py-2.5 text-right">Бонус</th>
                            <th v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right">Доход</th>
                            <th v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right">Маржа</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="m in byManager" :key="m.manager_id ?? 0"
                            class="cursor-pointer divide-x divide-slate-100 transition-colors duration-150 hover:bg-slate-50/60"
                            :class="Number(manager) === m.manager_id ? 'bg-indigo-50' : ''"
                            :title="'Показать только сделки: ' + m.manager"
                            @click="pickManager(Number(manager) === m.manager_id ? '' : (m.manager_id ?? ''))">
                            <td class="px-6 py-2.5 font-medium text-slate-800">{{ m.manager }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-500">{{ m.deals }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums text-emerald-600">{{ m.won }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money0(m.budget) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-600">{{ money0(m.paid) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-rose-600">−{{ money0(m.expense) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-rose-600">−{{ money0(m.tax) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-600">{{ money0(m.bonus) }}</td>
                            <td v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums" :class="m.company < 0 ? 'text-rose-600' : 'text-slate-800'">{{ money0(m.company) }}</td>
                            <td v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="m.margin < 0 ? 'font-semibold text-rose-600' : 'text-slate-500'">{{ m.margin }}%</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
                        <tr class="divide-x divide-slate-100">
                            <td class="px-6 py-3 text-slate-500">ИТОГО</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-600">{{ totals.count }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-700">{{ byManager.reduce((s, m) => s + m.won, 0) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-900">{{ money0(totals.budget) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-700">{{ money0(totals.paid) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-rose-600">−{{ money0(totals.material + (totals.delivery ?? 0) + (totals.purchase ?? 0) + (totals.assembly ?? 0) + totals.other) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-rose-600">−{{ money0(totals.tax) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-700">{{ money0(totals.bonus) }}</td>
                            <td v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-900">{{ money0(totals.company) }}</td>
                            <td v-if="isLeadership" class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-600">{{ totals.margin }}%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Легенда подсветки строк -->
        <div class="mt-6 flex flex-wrap items-center gap-4 px-1 text-[11px] text-slate-400">
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-gradient-to-r from-emerald-200 to-emerald-50"></span> Оплата успешно · Акт · ЭСФ</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-gradient-to-r from-amber-200 to-amber-50"></span> Логистика · {{ assemblyLabel() }}</span>
            <span class="flex items-center gap-1.5"><span class="h-3 w-6 rounded bg-white ring-1 ring-inset ring-slate-200"></span> В работе</span>
        </div>

        <!-- Таблица -->
        <div class="rise mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" style="animation-delay: 240ms">
            <div class="max-h-[70vh] overflow-auto">
                <table class="min-w-full whitespace-nowrap text-xs">
                    <thead class="text-left uppercase tracking-wide text-slate-400">
                        <tr class="divide-x divide-slate-100">
                            <th class="sticky top-0 z-20 border-b border-slate-100 bg-slate-50 px-6 py-2.5">Сделка</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5">Товар · кол-во</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5">Менеджер</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5">Этап · срок</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Сумма договора</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Оплачено</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Закуп (склад)</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">🚚 Доставка</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">📦 Закуп</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">🔧 {{ assemblyLabel() }}</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Прочие</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right" title="Доля партнёра: % от суммы договора">Партнёр</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Налог</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Остаток</th>
                            <th v-if="isLeadership" class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-center">Маржа</th>
                            <th class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Бонус</th>
                            <th v-if="isLeadership" class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-right">Доход</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in rows" :key="r.id" @click="openDeal(r.id)"
                            class="group cursor-pointer divide-x divide-slate-100 transition-colors" :class="rowClass(r)">
                            <!-- Сделка: организация + № / договор -->
                            <td class="max-w-64 px-6 py-2.5">
                                <div class="truncate text-[13px] font-semibold text-slate-800" :title="r.company_name">{{ r.company_name || '—' }}</div>
                                <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-400">
                                    <span class="font-medium text-slate-500">{{ r.number }}</span>
                                    <span v-if="r.bin">· {{ r.bin }}</span>
                                </div>
                                <div v-if="r.address" class="mt-0.5 max-w-60 truncate text-[11px] text-slate-400" :title="r.address">{{ r.address }}</div>
                            </td>
                            <td class="max-w-40 px-4 py-2.5">
                                <div class="truncate text-slate-700" :title="r.product">{{ r.product || '—' }}</div>
                                <div v-if="r.qty" class="mt-0.5 text-[11px] text-slate-400">{{ r.qty }}</div>
                            </td>
                            <td class="max-w-36 truncate px-4 py-2.5 text-slate-600">{{ r.manager || '—' }}</td>
                            <td class="px-4 py-2.5">
                                <span v-if="r.stage" class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.stage_color || '#94a3b8' }">{{ r.stage }}</span>
                                <!-- Сделка в цехе: этап цеха вторым бейджем — общая таблица, без дублей -->
                                <div v-if="r.workshop_stage" class="mt-1">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.workshop_color || '#f59e0b' }" :title="r.workshop_number">Цех · {{ r.workshop_stage }}</span>
                                </div>
                                <div class="mt-1 text-[11px]" :class="isOverdue(r) ? 'font-semibold text-rose-600' : 'text-slate-400'">
                                    {{ fmtDate(r.deadline) }}<span v-if="isOverdue(r)"> · просрочено</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ money(r.budget) }}</td>
                            <!-- Оплачено + мини-прогресс -->
                            <td class="px-4 py-2.5 text-right">
                                <div class="tabular-nums" :class="paidPct(r) >= 100 ? 'font-semibold text-emerald-600' : 'text-slate-700'">{{ money(r.paid) }}</div>
                                <div class="ml-auto mt-1 h-1 w-16 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-1 rounded-full" :class="paidPct(r) >= 100 ? 'bg-emerald-500' : 'bg-indigo-400'" :style="{ width: paidPct(r) + '%' }"></div>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-indigo-600">{{ money(r.material) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-sky-600">{{ money(r.delivery) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-amber-600">{{ money(r.purchase) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-teal-600">{{ money(r.assembly) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-600">{{ money(r.other) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-violet-600">{{ money(r.partner) }}<span v-if="r.partner_pct" class="ml-1 text-[10px] text-slate-400">{{ r.partner_pct }}%</span></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-rose-500">{{ money(r.tax) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums" :class="r.remainder < 0 ? 'font-semibold text-rose-600' : 'text-slate-700'">{{ money(r.remainder) }}</td>
                            <td v-if="isLeadership" class="px-4 py-2.5 text-center">
                                <span class="whitespace-nowrap rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="marginBadge(r.margin)" :title="r.margin < 0 ? 'Проект в убыток' : null">{{ r.margin < 0 ? '▼ ' : '' }}{{ r.margin }}%</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                            <td v-if="isLeadership" class="px-4 py-2.5 text-right font-semibold tabular-nums" :class="r.company < 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(r.company) }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="isLeadership ? 17 : 15" class="px-6 py-10 text-center text-sm text-slate-400">
                                <div>{{ filters.search || filters.from || filters.to ? 'Ничего не найдено' : 'Сделок пока нет' }}</div>
                                <div v-if="filters.search || filters.from || filters.to" class="mt-1 text-xs">Попробуйте изменить поиск или период</div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr class="sticky bottom-0 z-10 font-semibold text-white" style="background-color: #1A3B5C">
                            <td colspan="4" class="rounded-bl-2xl px-6 py-3 text-[11px] uppercase tracking-wide text-white/60">Итого · {{ totals.count }} сделок</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(totals.budget) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-300">{{ money(totals.paid) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-indigo-300">{{ money(totals.material) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-sky-300">{{ money(totals.delivery) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-amber-300">{{ money(totals.purchase) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-teal-300">{{ money(totals.assembly) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-300">{{ money(totals.other) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-violet-300">{{ money(totals.partner) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-300">{{ money(totals.tax) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(totals.remainder) }}</td>
                            <td v-if="isLeadership" class="px-4 py-3 text-center"><span class="rounded-md bg-white/10 px-1.5 py-0.5 text-[11px]">{{ totals.margin }}%</span></td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-300" :class="isLeadership ? '' : 'rounded-br-2xl'">{{ money(totals.bonus) }}</td>
                            <td v-if="isLeadership" class="rounded-br-2xl px-4 py-3 text-right tabular-nums">{{ money(totals.company) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        </PageLayout>
    </AppLayout>
</template>

<style scoped>
@keyframes rise {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.rise {
    opacity: 0;
    animation: rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
/* Линия-акцент слева: зелёная — won/Акт/ЭСФ, жёлтая — Логистика/Сборка. */
.row-won td:first-child {
    box-shadow: inset 3px 0 0 0 #10b981;
}
.row-logistics td:first-child {
    box-shadow: inset 3px 0 0 0 #f59e0b;
}
</style>
