<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money, formatDate, formatDateTime } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';
import { useStickyFilters } from '@/composables/useStickyFilters';

const props = defineProps({ rows: Array, leadership: Boolean, canManage: Boolean, month: String, normHours: Number, deptNorms: { type: [Object, Array], default: () => ({}) }, taxRate: Number, totals: Object, companies: { type: Array, default: () => [] }, departments: { type: Array, default: () => [] } });
// ВАЖНО: computed, а не разовый захват props. Inertia переиспользует компонент
// при переходе на ту же страницу (переключение фирмы, смена месяца) — обычная
// константа осталась бы от прежней фирмы, и данные обновлялись бы только по F5.
const me = computed(() => props.rows[0] ?? null);

// Шкала бонусов — коммерческая информация: видят только отдел продаж
// (менеджеры), финансисты и админ; цеху и прочим сотрудникам не показываем.
const myRoles = usePage().props.auth.user?.roles ?? [];
const seesBonusScale = ['manager', 'financist', 'admin'].some((r) => myRoles.includes(r));
const BONUS_TIERS = [
    { m: 'до 10%', b: 'бонуса нет', muted: true },
    { m: '11% – 15%', b: '5% от остатка' },
    { m: '16% – 20%', b: '7% от остатка' },
    { m: '21% – 30%', b: '10% от остатка' },
    { m: '31% – 40%', b: '13% от остатка' },
    { m: 'от 41%', b: '15% от остатка' },
];

const open = ref(new Set());
const toggle = (uid) => { const s = new Set(open.value); s.has(uid) ? s.delete(uid) : s.add(uid); open.value = s; };

const companyNames = computed(() => Object.fromEntries((props.companies ?? []).map((c) => [c.id, c.name])));

// Свой долг одной сводкой — для личной карточки сотрудника.
const myDebt = computed(() => {
    const list = me.value?.debts ?? [];
    const total = list.reduce((s, d) => s + (d.amount || 0), 0);
    const paid = list.reduce((s, d) => s + (d.paid || 0), 0);
    return {
        total,
        paid,
        remaining: list.reduce((s, d) => s + (d.remaining || 0), 0),
        monthly: list.reduce((s, d) => s + (d.closed ? 0 : d.monthly_amount || 0), 0),
        percent: total > 0 ? Math.min(100, Math.round((paid / total) * 100)) : 0,
    };
});

// Отдел фирмы по общему коду: «Отдел продаж» BAIA и ASU — разные отделы.
const deptByCompanyCode = computed(() => {
    const map = new Map();
    (props.departments ?? []).forEach((d) => map.set(`${d.company_id}|${d.code}`, d));
    return map;
});

// Ведомость — раздельно по фирмам, внутри фирмы — секциями по отделам
// (отделы с большей выплатой сверху, «Без отдела» — как обычная секция;
// внутри порядок строк серверный, по бонусу).
//
// Сотрудник, работающий в обеих фирмах, ВИДЕН в обеих секциях, но его деньги
// входят в итог только основной фирмы (primary_company_id) — в чужой секции
// строка помечена и в суммы не идёт, иначе холдинг «заплатил» бы дважды.
const companySections = computed(() => {
    const sections = (props.companies ?? []).map((c) => ({ id: c.id, name: c.name, map: new Map() }));
    const orphan = { id: 0, name: 'Без фирмы', map: new Map() };

    const put = (section, r) => {
        const own = r.department_code ? deptByCompanyCode.value.get(`${section.id}|${r.department_code}`) : null;
        const name = own?.name ?? r.department ?? 'Без отдела';
        if (!section.map.has(name)) section.map.set(name, { list: [], id: own?.id ?? r.department_id ?? null });
        section.map.get(name).list.push({ ...r, counted: (r.primary_company_id ?? 0) === section.id });
    };

    for (const r of props.rows) {
        const ids = r.company_ids ?? [];
        if (!ids.length) { put(orphan, r); continue; }
        sections.filter((s) => ids.includes(s.id)).forEach((s) => put(s, r));
    }

    return [...sections, orphan]
        .map((s) => {
            const groups = [...s.map.entries()]
                .map(([name, { list, id }]) => {
                    const own = id != null ? props.deptNorms?.[id] : null; // своя норма отдела
                    // Суммы отдела — по строкам, которые в этой фирме считаются
                    // (у «двойного» сотрудника деньги идут только в основную).
                    const counted = list.filter((r) => r.counted);
                    const sum = (f) => counted.reduce((n, r) => n + (r[f] || 0), 0);
                    return {
                        key: `${s.id}|${name}`, name, list, id,
                        norm: own ?? props.normHours,
                        override: own != null,
                        base: sum('base'),
                        bonus: sum('bonus'),
                        deductions: sum('deductions'),
                        additions: sum('additions'),
                        debt: sum('debt_charge'),
                        final: sum('final'),
                    };
                })
                .sort((a, b) => b.final - a.final || a.name.localeCompare(b.name, 'ru'));
            const counted = groups.flatMap((g) => g.list).filter((r) => r.counted);
            return {
                id: s.id, name: s.name, groups,
                people: groups.reduce((n, g) => n + g.list.length, 0),
                totals: {
                    base: counted.reduce((n, r) => n + (r.base || 0), 0),
                    bonus: counted.reduce((n, r) => n + (r.bonus || 0), 0),
                    deductions: counted.reduce((n, r) => n + (r.deductions || 0), 0),
                    additions: counted.reduce((n, r) => n + (r.additions || 0), 0),
                    debt: counted.reduce((n, r) => n + (r.debt_charge || 0), 0),
                    final: counted.reduce((n, r) => n + (r.final || 0), 0),
                },
            };
        })
        .filter((s) => s.people > 0);
});
// Селект сотрудника в модалке корректировки: «Фирма · отдел», каждый человек
// ровно один раз (в своей основной фирме) — корректировка одна на сотрудника.
const adjGroups = computed(() => companySections.value.flatMap((s) => s.groups
    .map((g) => ({ key: g.key, label: `${s.name} · ${g.name}`, list: g.list.filter((r) => r.counted) }))
    .filter((g) => g.list.length)));

// Своя норма часов отдела: правка в заголовке секции; пусто — сброс на общую.
const editingDeptNorm = ref(null);
const deptNormVal = ref('');
const editDeptNorm = (g) => { editingDeptNorm.value = g.key; deptNormVal.value = g.override ? g.norm : ''; };
const saveDeptNorm = (g) => router.patch(route('payroll.norm'), {
    month: props.month, department_id: g.id,
    norm: deptNormVal.value === '' ? null : Number(deptNormVal.value),
}, { preserveScroll: true, onSuccess: () => (editingDeptNorm.value = null) });
// Отделы СВЁРНУТЫ по умолчанию: страница открывается сводкой по фирмам и
// отделам, а не стеной из всех сотрудников — раскрываем только нужный.
const expanded = ref(new Set());
const toggleDept = (key) => { const s = new Set(expanded.value); s.has(key) ? s.delete(key) : s.add(key); expanded.value = s; };
const allKeys = computed(() => companySections.value.flatMap((s) => s.groups.map((g) => g.key)));
const allExpanded = computed(() => allKeys.value.length > 0 && allKeys.value.every((k) => expanded.value.has(k)));
const toggleAll = () => { expanded.value = allExpanded.value ? new Set() : new Set(allKeys.value); };

// Месяц корректировок (отгулы/больничные/штрафы) — серверный фильтр.
const monthSel = ref(props.month);
const setMonth = () => router.get(route('payroll.index'), { month: monthSel.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
// Выбранный месяц ведомости запоминается за страницей.
useStickyFilters('payroll', { monthSel }, setMonth);

const typeLabels = { absence: 'Отгул', sick: 'Больничный', fine: 'Штраф', advance: 'Аванс', bonus: 'Премия' };
// Аванс и долг — РАЗНЫЕ вещи, обе заводятся модалкой:
// аванс — разовая выдача, каждый месяц своя сумма, удерживается целиком в этом
// же месяце; долг — переходящий остаток, гасится фиксированной суммой в месяц
// и только из бонуса. Аванс остаётся типом корректировки.
const newAdjTypes = { absence: 'Отгул', sick: 'Больничный', fine: 'Штраф', advance: 'Аванс', bonus: 'Премия' };
// «2026-07» → «июль 2026» для заголовков (computed — месяц меняется без перезагрузки).
const monthLabel = computed(() => new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));
// Короткий вариант для заголовка колонки — «август 2026 г.» её распирает.
const monthShort = computed(() => new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long' }));
const typeClass = (t) => t === 'bonus' ? 'bg-emerald-100 text-emerald-700' : t === 'fine' ? 'bg-rose-100 text-rose-700' : t === 'advance' ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700';

// Оклад: инлайн-правка (бухгалтер/админ).
const editingSalary = ref(null);
const salaryVal = ref('');
const editSalary = (r) => { editingSalary.value = r.uid; salaryVal.value = Number(r.salary) || ''; };
const saveSalary = (r) => router.patch(route('payroll.salary', r.uid), { salary: Number(salaryVal.value) || 0 }, {
    preserveScroll: true, onSuccess: () => (editingSalary.value = null),
});

// Почасовой оклад: норма часов месяца (одна на всех) + отработанные часы сотрудника.
// Ставка/час = оклад ÷ норма; начислено = часы × ставка; без часов — полный оклад.
const normVal = ref(props.normHours || '');
const editingNorm = ref(false);
const editNorm = () => { normVal.value = props.normHours || ''; editingNorm.value = true; };
const saveNorm = () => {
    const n = Number(normVal.value);
    if (!(n > 0)) return;
    if (n === props.normHours) { editingNorm.value = false; return; }
    router.patch(route('payroll.norm'), { month: props.month, norm: n }, { preserveScroll: true, onSuccess: () => (editingNorm.value = false) });
};
const editingHours = ref(null);
const hoursVal = ref('');
const editHours = (r) => { editingHours.value = r.uid; hoursVal.value = r.hours ?? ''; };
const saveHours = (r) => router.patch(route('payroll.hours', r.uid), {
    month: props.month, hours: hoursVal.value === '' ? null : Number(hoursVal.value),
}, { preserveScroll: true, onSuccess: () => (editingHours.value = null) });

// Корректировка: отгул/больничный — днями (сумма авто = оклад/22 × дни) или суммой.
const showAdj = ref(false);
const adjForm = useForm({ user_id: '', type: 'absence', days: '', amount: '', date: new Date().toISOString().slice(0, 10), note: '', payment_method: 'cash' });
const openAdj = (uid = '') => { adjForm.reset(); adjForm.user_id = uid; adjForm.date = new Date().toISOString().slice(0, 10); showAdj.value = true; };
const submitAdj = () => adjForm.post(route('payroll.adjustments.store'), { preserveScroll: true, onSuccess: () => (showAdj.value = false) });

// Долг сотрудника: выдаётся реальными деньгами (расход компании), дальше
// гасится сам — фиксированной суммой в месяц и только из бонуса.
const showDebt = ref(false);
const debtForm = useForm({ user_id: '', amount: '', monthly_amount: '', date: new Date().toISOString().slice(0, 10), payment_method: 'cash', note: '' });
const openDebt = (uid = '') => { debtForm.reset(); debtForm.user_id = uid; debtForm.date = new Date().toISOString().slice(0, 10); showDebt.value = true; };
// Из «Корректировки ЗП» → в форму долга, сохранив выбранного сотрудника.
const switchToDebt = () => { const uid = adjForm.user_id; showAdj.value = false; openDebt(uid); };
const submitDebt = () => debtForm.post(route('payroll.debts.store'), { preserveScroll: true, onSuccess: () => (showDebt.value = false) });
const delDebt = async (d) => {
    if (await confirmDialog({
        title: 'Удалить долг',
        message: `Долг ${money(d.amount)} будет удалён вместе с расходом на Финансах (деньги вернутся в кассу).`,
        confirmText: 'Удалить', danger: true,
    })) {
        router.delete(route('payroll.debts.destroy', d.id), { preserveScroll: true });
    }
};
const delAdj = async (a) => {
    if (await confirmDialog({ title: 'Удалить корректировку', message: `«${typeLabels[a.type]} ${money(a.amount)}» будет удалена.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('payroll.adjustments.destroy', a.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Зарплата" />
    <AppLayout>
        <template #header>
            <!-- На узком экране заголовок и управление идут в две строки:
                 в одну они наезжали на колокольчик и обрезали название. -->
            <div class="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-3">
                <span class="truncate">{{ $t('page.payroll', 'Зарплата и бонусы') }}</span>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">месяц
                        <input v-model="monthSel" @change="setMonth" type="month" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <button v-if="canManage" @click="openAdj()"
                        class="whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">+ Корректировка</button>
                </div>
            </div>
        </template>

        <!-- Manager: слева выплата/корректировки/сделки, справа — шкала бонусов -->
        <div v-if="!leadership" class="grid max-w-5xl grid-cols-1 items-start gap-4 lg:grid-cols-3">
            <div class="space-y-4" :class="seesBonusScale ? 'lg:col-span-2' : 'lg:col-span-3'">
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
                <div class="text-xs uppercase text-slate-400">К выплате · {{ monthLabel }}</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.final ?? me?.payout ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Оклад<template v-if="me?.hours != null"> · {{ me.hours }} ч × {{ money(me.hourly_rate) }}/ч</template></span>
                        <span class="font-medium tabular-nums">{{ money(me?.base ?? me?.salary ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between"><span class="text-slate-500">Бонус по марже сделок</span><span class="font-medium tabular-nums text-emerald-600">{{ money(me?.bonus ?? 0) }}</span></div>
                    <div v-if="me?.deductions" class="flex justify-between"><span class="text-slate-500">Удержания (отгул/больничный/штраф/аванс)</span><span class="font-medium tabular-nums text-rose-600">− {{ money(me.deductions) }}</span></div>
                    <div v-if="me?.additions" class="flex justify-between"><span class="text-slate-500">Премии</span><span class="font-medium tabular-nums text-emerald-600">+ {{ money(me.additions) }}</span></div>
                    <!-- Погашение долга вычитается из «К выплате» — без этой
                         строки сумма сверху не сходилась бы с разбивкой. -->
                    <div v-if="me?.debt_charge > 0" class="flex justify-between">
                        <span class="text-slate-500">Погашение долга <span class="text-slate-400">(из бонуса)</span></span>
                        <span class="font-medium tabular-nums text-amber-600">− {{ money(me.debt_charge) }}</span>
                    </div>
                    <div class="flex justify-between"><span class="text-slate-500">Успешных сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                </div>
            </div>

            <!-- Свой долг: сотруднику важно «сколько осталось и когда закрою»,
                 а не бухгалтерская таблица — поэтому крупная сумма, полоса
                 погашения и одна понятная строка про этот месяц. -->
            <div v-if="me?.debts?.length" class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm">
                <div class="bg-gradient-to-r from-amber-50 to-white px-5 pt-5 pb-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Долг перед компанией</div>
                            <div class="mt-1 text-3xl font-bold tabular-nums text-amber-800">{{ money(myDebt.remaining) }}</div>
                            <div class="mt-0.5 text-xs text-slate-400">осталось погасить</div>
                        </div>
                        <div class="shrink-0 rounded-xl bg-white px-3 py-2 text-right shadow-sm ring-1 ring-amber-100">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">по плану</div>
                            <div class="text-sm font-semibold tabular-nums text-slate-700">{{ money(myDebt.monthly) }}<span class="text-xs font-normal text-slate-400">/мес</span></div>
                        </div>
                    </div>

                    <!-- Полоса погашения: видно, сколько пути пройдено -->
                    <div class="mt-4">
                        <div class="h-2 overflow-hidden rounded-full bg-amber-100">
                            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-500 transition-all duration-500"
                                :style="{ width: myDebt.percent + '%' }"></div>
                        </div>
                        <div class="mt-1.5 flex justify-between text-[11px] text-slate-400">
                            <span>погашено <b class="tabular-nums text-emerald-600">{{ money(myDebt.paid) }}</b></span>
                            <span class="tabular-nums">{{ myDebt.percent }}% из {{ money(myDebt.total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="border-t border-amber-100 px-5 py-3 text-sm">
                    <template v-if="me.debt_charge > 0">
                        <!-- «За август» вместо «В август»: падеж не ломается ни на одном месяце. -->
                        <span class="text-slate-600">{{ me.debt_planned > 0 ? 'Удержим за ' + monthLabel : 'Удержано за ' + monthLabel }}</span>
                        <b class="ml-1 tabular-nums text-amber-700">{{ money(me.debt_charge) }}</b>
                        <span class="text-slate-400"> — останется {{ money(me.debt_after) }}</span>
                    </template>
                    <template v-else>
                        <span class="text-slate-500">За {{ monthLabel }} бонуса нет — удержания не будет.</span>
                    </template>
                    <div class="mt-1 text-xs text-slate-400">Долг гасится только из бонуса. Оклад не удерживается.</div>
                </div>
            </div>

            <!-- Корректировки за месяц -->
            <div v-if="me?.adjustments?.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Корректировки · {{ monthLabel }}</div>
                <div class="divide-y divide-slate-50 text-sm">
                    <div v-for="a in me.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-2">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                            <span class="text-xs text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} дн.</template><template v-if="a.note"> · {{ a.note }}</template> · внесено {{ formatDateTime(a.created_at) }}</span>
                        </div>
                        <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="me?.dealsList?.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                        <tr><th class="px-3 py-2">Сделка</th><th class="px-3 py-2">Этап</th><th class="px-3 py-2 text-right">Сумма</th><th class="px-3 py-2 text-right">Оплачено</th><th class="px-3 py-2 text-right text-emerald-600">Бонус</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="d in me.dealsList" :key="d.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2"><Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link> <span class="text-slate-400">{{ d.number }}</span></td>
                            <td class="px-3 py-2"><span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                {{ money(d.bonus) }}
                                <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="'Ручной % финансиста: ' + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>

            <!-- Правая колонка: система бонусов — только отдел продаж/финансист/админ -->
            <div v-if="seesBonusScale" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:sticky lg:top-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Система бонусов — по марже сделки</div>
                <div class="mt-3 space-y-1.5 text-sm">
                    <div v-for="t in BONUS_TIERS" :key="t.m" class="flex items-center justify-between rounded-lg px-3 py-1.5"
                        :class="t.muted ? 'bg-slate-50 text-slate-400' : 'bg-emerald-50/50'">
                        <span :class="t.muted ? '' : 'text-slate-600'">маржа {{ t.m }}</span>
                        <span class="font-semibold tabular-nums" :class="t.muted ? '' : 'text-emerald-700'">{{ t.b }}</span>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-slate-400">Маржа = (сумма договора − расходы) / сумма договора. Остаток = сумма − налог − расходы.</p>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <!-- Норма часов месяца — на виду у финансиста: ставка/час = оклад ÷ норма -->
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-white px-5 py-4 shadow-sm">
                <div class="flex items-center gap-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </span>
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Норма часов · {{ monthLabel }}</div>
                        <div class="mt-0.5 flex items-baseline gap-2">
                            <template v-if="editingNorm">
                                <input v-model="normVal" type="number" min="1" max="744" class="w-24 rounded-lg border-indigo-300 py-1 text-lg font-bold tabular-nums text-slate-900 focus:border-indigo-500 focus:ring-indigo-500"
                                    @keydown.enter="saveNorm" @keydown.escape="editingNorm = false" />
                                <button class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-bold text-white transition hover:bg-indigo-700" @click="saveNorm">✓</button>
                                <button class="rounded-lg px-2 py-1.5 text-xs font-medium text-slate-400 hover:text-slate-600" @click="editingNorm = false">отмена</button>
                            </template>
                            <button v-else-if="canManage" class="group flex items-baseline gap-1.5" title="Изменить норму часов месяца" @click="editNorm">
                                <span class="text-2xl font-bold tabular-nums leading-none text-slate-900">{{ normHours }}</span>
                                <span class="text-sm text-slate-400">ч / мес</span>
                                <svg class="h-3.5 w-3.5 self-center text-slate-300 transition-colors group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <span v-else class="text-2xl font-bold tabular-nums leading-none text-slate-900">{{ normHours }} <span class="text-sm font-normal text-slate-400">ч / мес</span></span>
                        </div>
                    </div>
                </div>
                <!-- Пояснение — справка, а не данные: на телефоне прячем,
                     иначе оно занимает треть экрана и ломается на три строки. -->
                <div class="hidden text-right text-[11px] leading-relaxed text-slate-400 sm:block">
                    <div>ставка за час = оклад ÷ <span class="font-semibold text-slate-600">{{ normHours }} ч</span> · начислено = часы × ставка</div>
                    <div>часы не введены — полный оклад · у отдела своя норма — в заголовке его секции</div>
                </div>
            </div>

            <!-- Ведомость — только про ЗП: 4 плитки. Деньги сделок — на Финансах и в Сводном
                 отчёте; здесь по сотруднику они видны при раскрытии строки. -->
            <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Оклады (начислено)</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-slate-800 xl:text-xl">{{ money(totals.base) }}</div>
                    <div v-if="totals.base !== totals.salary" class="truncate text-[10px] text-slate-400">по карточкам {{ money(totals.salary) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Бонусы (по марже)</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-600 xl:text-xl">{{ money(totals.bonus) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Удержания / премии</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums xl:text-xl" :class="totals.deductions > 0 ? 'text-rose-600' : 'text-slate-300'">
                        <template v-if="totals.deductions > 0">−{{ money(totals.deductions) }}</template>
                        <template v-else>—</template>
                        <span v-if="totals.additions > 0" class="text-sm text-emerald-600"> +{{ money(totals.additions) }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-emerald-600/70">К выплате · {{ monthLabel }}</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-700 xl:text-xl">{{ money(totals.final) }}</div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">
                                <span class="flex items-center gap-2">
                                    Сотрудник
                                    <!-- Отделы свёрнуты по умолчанию — даём быстрый способ
                                         раскрыть всё, когда нужен полный список. -->
                                    <button type="button" @click="toggleAll"
                                        class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-slate-500 transition hover:border-slate-300 hover:text-slate-700">
                                        {{ allExpanded ? 'свернуть всё' : 'развернуть всё' }}
                                    </button>
                                </span>
                            </th>
                            <th class="hidden sm:table-cell px-4 py-3 text-right" title="Отработанные часы за месяц. Пусто — полный оклад.">Часы</th>
                            <th class="hidden sm:table-cell px-4 py-3 text-right">Оклад (начислено)</th>
                            <th class="hidden sm:table-cell px-4 py-3 text-right">Бонус</th>
                            <th class="hidden sm:table-cell px-4 py-3 text-right">Удержания / премии</th>
                            <!-- Долг отдельной колонкой: он не удержание и не премия,
                                 гасится только из бонуса. -->
                            <th class="hidden sm:table-cell px-4 py-3 text-right" title="Погашение долга за месяц — только из бонуса, оклад не трогается">Долг за {{ monthShort }}</th>
                            <th class="px-4 py-3 text-right">К выплате</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="s in companySections" :key="s.id">
                        <!-- Секция ФИРМЫ: ведомость BAIA и ASU считаются раздельно.
                             Суммы разложены по тем же колонкам, что и строки
                             сотрудников — свёрнутая ведомость читается как сводка. -->
                        <tr class="bg-slate-800 text-white">
                            <td class="px-4 py-2.5">
                                <span class="flex items-center gap-2 text-sm font-extrabold uppercase tracking-wide">
                                    {{ s.name }}
                                    <span class="rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold normal-case tracking-normal text-white/70">{{ s.people }} сотр.</span>
                                </span>
                            </td>
                            <td class="hidden sm:table-cell"></td>
                            <td class="hidden sm:table-cell px-4 py-2.5 text-right text-sm font-semibold tabular-nums text-white/90">{{ money(s.totals.base) }}</td>
                            <td class="hidden sm:table-cell px-4 py-2.5 text-right text-sm font-semibold tabular-nums text-emerald-300">{{ money(s.totals.bonus) }}</td>
                            <td class="hidden sm:table-cell px-4 py-2.5 text-right text-sm font-semibold tabular-nums" :class="s.totals.deductions > 0 ? 'text-rose-300' : 'text-white/30'">
                                {{ s.totals.deductions > 0 ? '− ' + money(s.totals.deductions) : '—' }}
                            </td>
                            <td class="hidden sm:table-cell px-4 py-2.5 text-right text-sm font-semibold tabular-nums" :class="s.totals.debt > 0 ? 'text-amber-300' : 'text-white/30'">
                                {{ s.totals.debt > 0 ? '− ' + money(s.totals.debt) : '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-base font-bold tabular-nums text-emerald-300">{{ money(s.totals.final) }}</td>
                        </tr>
                        <template v-for="g in s.groups" :key="g.key">
                        <!-- Секция отдела: название, число сотрудников, Σ к выплате; клик — свернуть/развернуть -->
                        <tr class="cursor-pointer select-none bg-slate-100/80 hover:bg-slate-200/70" @click="toggleDept(g.key)">
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-600">
                                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform" :class="expanded.has(g.key) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        <span class="truncate">⌂ {{ g.name }}</span>
                                        <span class="whitespace-nowrap font-medium normal-case tracking-normal text-slate-400">{{ g.list.length }}<span class="hidden sm:inline"> сотр.</span></span>
                                        <!-- Своя норма часов отдела; пусто при правке — сброс на общую.
                                             На телефоне скрыта: разрывала строку отдела на три. -->
                                        <span v-if="g.id != null" class="hidden normal-case tracking-normal sm:inline" @click.stop>
                                            <span v-if="editingDeptNorm === g.key" class="flex items-center gap-1">
                                                <input v-model="deptNormVal" type="number" min="1" max="744" :placeholder="normHours"
                                                    class="w-16 rounded-md border-indigo-300 py-0.5 text-right text-xs font-semibold tabular-nums"
                                                    @keydown.enter="saveDeptNorm(g)" @keydown.escape="editingDeptNorm = null" />
                                                <button class="rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white" @click="saveDeptNorm(g)">✓</button>
                                            </span>
                                            <button v-else-if="canManage" class="group/norm inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums transition-colors"
                                                :class="g.override ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200' : 'bg-slate-200/70 text-slate-500 hover:bg-slate-300/70'"
                                                :title="g.override ? 'Своя норма отдела (пусто — сброс на общую ' + normHours + ' ч)' : 'Общая норма ' + normHours + ' ч — нажмите, чтобы задать свою для отдела'"
                                                @click="editDeptNorm(g)">
                                                норма {{ g.norm }} ч
                                                <svg class="h-2.5 w-2.5 opacity-40 group-hover/norm:opacity-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                            </button>
                                            <span v-else class="rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums" :class="g.override ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-200/70 text-slate-500'">норма {{ g.norm }} ч</span>
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td class="hidden sm:table-cell"></td>
                            <td class="hidden sm:table-cell px-4 py-2 text-right text-xs font-semibold tabular-nums text-slate-600">{{ money(g.base) }}</td>
                            <td class="hidden sm:table-cell px-4 py-2 text-right text-xs font-semibold tabular-nums text-emerald-600">{{ money(g.bonus) }}</td>
                            <td class="hidden sm:table-cell px-4 py-2 text-right text-xs font-semibold tabular-nums" :class="g.deductions > 0 ? 'text-rose-600' : 'text-slate-300'">
                                {{ g.deductions > 0 ? '− ' + money(g.deductions) : (g.additions > 0 ? '' : '—') }}
                                <span v-if="g.additions > 0" class="text-emerald-600"> + {{ money(g.additions) }}</span>
                            </td>
                            <td class="hidden sm:table-cell px-4 py-2 text-right text-xs font-semibold tabular-nums" :class="g.debt > 0 ? 'text-amber-600' : 'text-slate-300'">
                                {{ g.debt > 0 ? '− ' + money(g.debt) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-sm font-bold tabular-nums text-emerald-700">{{ money(g.final) }}</td>
                        </tr>
                        <template v-if="expanded.has(g.key)">
                        <template v-for="r in g.list" :key="r.uid">
                            <!-- Строка чужой фирмы (ЗП учтена в основной) — приглушена:
                                 суммы показаны для справки и в итог секции не входят. -->
                            <tr class="cursor-pointer hover:bg-slate-50" :class="r.counted ? '' : 'bg-slate-50/60 opacity-50'" @click="toggle(r.uid)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open.has(r.uid) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                        <div class="min-w-0 leading-tight">
                                            <div class="flex items-center gap-1.5">
                                                <span class="truncate font-medium text-slate-900">{{ r.user }}</span>
                                                <!-- Работает в обеих фирмах: показан в обеих секциях, но
                                                     в суммы входит только у основной фирмы. -->
                                                <span v-if="(r.company_ids?.length ?? 0) > 1" class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold"
                                                    :class="r.counted ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-slate-100 text-slate-400'"
                                                    :title="r.counted ? 'Работает в обеих фирмах — ЗП учтена здесь' : 'Работает в обеих фирмах — ЗП учтена в ' + (companyNames[r.primary_company_id] ?? 'другой фирме')">
                                                    {{ r.counted ? 'обе фирмы' : 'учтено в ' + (companyNames[r.primary_company_id] ?? '—') }}
                                                </span>
                                            </div>
                                            <div v-if="r.deals > 0" class="text-[11px] text-slate-400">{{ r.deals }} сделок · {{ r.closed }} успешных</div>
                                        </div>
                                    </div>
                                </td>
                                <!-- Часы за месяц: инлайн-правка бухгалтером/админом; пусто — полный оклад -->
                                <td class="hidden sm:table-cell px-4 py-3 text-right tabular-nums" @click.stop>
                                    <div v-if="editingHours === r.uid" class="flex items-center justify-end gap-1">
                                        <input v-model="hoursVal" type="number" min="0" step="0.5" class="w-20 rounded-md border-slate-300 py-1 text-right text-xs"
                                            @keydown.enter="saveHours(r)" @keydown.escape="editingHours = null" />
                                        <button class="rounded bg-emerald-600 px-1.5 py-1 text-[10px] font-bold text-white" @click="saveHours(r)">✓</button>
                                    </div>
                                    <button v-else-if="canManage" class="group inline-flex items-center gap-1 hover:text-indigo-600"
                                        :title="'Отработанные часы за ' + monthLabel + ' (пусто — полный оклад). Ставка: ' + money(r.hourly_rate ?? 0) + '/ч'" @click="editHours(r)">
                                        <span :class="r.hours != null ? 'font-medium text-slate-700' : 'text-slate-300'">{{ r.hours != null ? r.hours + ' ч' : '—' }}</span>
                                        <svg class="h-3 w-3 text-slate-300 group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </button>
                                    <span v-else :class="r.hours != null ? 'font-medium text-slate-700' : 'text-slate-300'">{{ r.hours != null ? r.hours + ' ч' : '—' }}</span>
                                </td>
                                <!-- Оклад: крупно — начислено; подписью — оклад по карточке и формула часов -->
                                <td class="hidden sm:table-cell px-4 py-3 text-right tabular-nums" @click.stop>
                                    <div v-if="editingSalary === r.uid" class="flex items-center justify-end gap-1">
                                        <input v-model="salaryVal" type="number" min="0" class="w-28 rounded-md border-slate-300 py-1 text-right text-xs"
                                            @keydown.enter="saveSalary(r)" @keydown.escape="editingSalary = null" />
                                        <button class="rounded bg-emerald-600 px-1.5 py-1 text-[10px] font-bold text-white" @click="saveSalary(r)">✓</button>
                                    </div>
                                    <template v-else>
                                        <button v-if="canManage" class="group inline-flex items-center gap-1 hover:text-indigo-600" title="Изменить оклад" @click="editSalary(r)">
                                            <span :class="r.base > 0 ? 'font-medium text-slate-800' : 'text-slate-300'">{{ r.base > 0 ? money(r.base) : '—' }}</span>
                                            <svg class="h-3 w-3 text-slate-300 group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                        </button>
                                        <span v-else :class="r.base > 0 ? 'font-medium text-slate-800' : 'text-slate-300'">{{ r.base > 0 ? money(r.base) : '—' }}</span>
                                        <div v-if="r.hours != null" class="text-[10px] text-slate-400">оклад {{ money(r.salary) }} · {{ r.hours }} ч × {{ money(r.hourly_rate ?? 0) }}</div>
                                    </template>
                                </td>
                                <td class="hidden sm:table-cell px-4 py-3 text-right tabular-nums" :class="r.bonus > 0 ? 'font-medium text-emerald-600' : 'text-slate-300'">{{ r.bonus > 0 ? money(r.bonus) : '—' }}</td>
                                <td class="hidden sm:table-cell px-4 py-3 text-right tabular-nums" :class="r.deductions > 0 ? 'text-rose-600 font-medium' : 'text-slate-300'">
                                    <template v-if="r.deductions > 0">− {{ money(r.deductions) }}</template>
                                    <template v-else>—</template>
                                    <span v-if="r.additions > 0" class="text-emerald-600"> +{{ money(r.additions) }}</span>
                                </td>
                                <!-- Долг: удержание месяца, гасится только из бонуса.
                                     Подсказкой — сколько останется после него. -->
                                <td class="hidden sm:table-cell px-4 py-3 text-right tabular-nums" :class="r.debt_charge > 0 ? 'font-medium text-amber-600' : 'text-slate-300'"
                                    :title="r.debt_charge > 0 ? 'Останется долга: ' + money(r.debt_after) : ''">
                                    {{ r.debt_charge > 0 ? '− ' + money(r.debt_charge) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums" :class="r.final > 0 ? 'text-emerald-600' : 'text-slate-300'">{{ r.final > 0 ? money(r.final) : '—' }}</td>
                            </tr>
                            <tr v-if="open.has(r.uid)" class="bg-slate-50/60">
                                <td colspan="7" class="px-4 py-3">
                                    <!-- Финансы сделок сотрудника (из колонок убраны — здесь по требованию) -->
                                    <div v-if="r.budget > 0" class="mb-3 flex flex-wrap gap-2 text-[11px]">
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Сумма договоров <span class="font-semibold tabular-nums text-slate-700">{{ money(r.budget) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Налог {{ taxRate }}% <span class="font-semibold tabular-nums text-rose-600">− {{ money(r.tax) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Расходы <span class="font-semibold tabular-nums text-rose-600">− {{ money(r.expense) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Остаток <span class="font-semibold tabular-nums text-slate-700">{{ money(r.remainder) }}</span></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Чистая прибыль <span class="font-semibold tabular-nums" :class="r.company >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(r.company) }}</span></span>
                                    </div>
                                    <!-- Долги сотрудника: гасятся сами, фиксированной суммой
                                         в месяц и ТОЛЬКО из бонуса — оклад не трогается. -->
                                    <div class="mb-3">
                                        <div class="mb-1.5 flex items-center justify-between">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                                                Долг перед компанией
                                                <span v-if="r.debt_remaining > 0" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold normal-case tracking-normal text-amber-800">
                                                    {{ money(r.debt_remaining) }}
                                                </span>
                                            </span>
                                            <button v-if="canManage" class="text-xs font-medium text-amber-700 hover:text-amber-800" @click="openDebt(r.uid)">+ выдать долг</button>
                                        </div>
                                        <template v-if="r.debts?.length">
                                            <div class="overflow-x-auto rounded-lg border border-amber-200 bg-white">
                                                <table class="min-w-full divide-y divide-amber-100 text-xs">
                                                    <thead class="text-left uppercase tracking-wide text-amber-700/70">
                                                        <tr class="bg-amber-50/60">
                                                            <!-- Паспорт долга (сумма, когда и за что выдан) — одной
                                                                 колонкой: он не меняется, а живые цифры справа
                                                                 не должны из-за него сжиматься. -->
                                                            <th class="px-3 py-2 font-semibold">Долг</th>
                                                            <th class="px-3 py-2 text-right font-semibold">В месяц</th>
                                                            <th class="px-3 py-2 text-right font-semibold">За {{ monthLabel }}</th>
                                                            <th class="px-3 py-2 text-right font-semibold">Погашено</th>
                                                            <th class="px-3 py-2 text-right font-semibold">Осталось</th>
                                                            <th v-if="canManage" class="px-3 py-2"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-amber-50">
                                                        <tr v-for="d in r.debts" :key="d.id" :class="d.closed ? 'opacity-60' : ''">
                                                            <td class="px-3 py-2">
                                                                <div class="flex items-center gap-1.5">
                                                                    <span class="font-semibold tabular-nums text-slate-800">{{ money(d.amount) }}</span>
                                                                    <span v-if="d.closed" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">закрыт</span>
                                                                </div>
                                                                <div class="mt-0.5 text-[11px] text-slate-400">
                                                                    {{ formatDate(d.date) }}<template v-if="d.note"> · {{ d.note }}</template>
                                                                </div>
                                                            </td>
                                                            <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ money(d.monthly_amount) }}</td>
                                                            <td class="px-3 py-2 text-right tabular-nums" :class="d.paid_this_month > 0 ? 'font-semibold text-rose-600' : 'text-slate-300'">
                                                                {{ d.paid_this_month > 0 ? '− ' + money(d.paid_this_month) : '—' }}
                                                            </td>
                                                            <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ money(d.paid) }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="d.remaining > 0 ? 'text-amber-700' : 'text-slate-300'">{{ money(d.remaining) }}</td>
                                                            <td v-if="canManage" class="px-3 py-2 text-right">
                                                                <button class="text-slate-300 transition-colors hover:text-rose-600" title="Удалить долг" @click="delDebt(d)">✕</button>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot class="border-t-2 border-amber-200 bg-amber-50/80">
                                                        <!-- Итог выровнен по колонкам: удержание встаёт под
                                                             «За месяц», остаток — под «Осталось». -->
                                                        <tr v-if="r.debt_charge > 0">
                                                            <td colspan="2" class="px-3 py-2 font-semibold text-amber-800">
                                                                {{ r.debt_planned > 0 ? 'Удержим' : 'Удержано' }} из бонуса за {{ monthLabel }}
                                                            </td>
                                                            <td class="px-3 py-2 text-right font-bold tabular-nums text-rose-600">− {{ money(r.debt_charge) }}</td>
                                                            <td class="px-3 py-2 text-right text-[11px] uppercase tracking-wide text-slate-400">останется</td>
                                                            <td :colspan="canManage ? 2 : 1" class="px-3 py-2 text-right font-bold tabular-nums text-amber-800">{{ money(r.debt_after) }}</td>
                                                        </tr>
                                                        <tr v-else>
                                                            <td :colspan="canManage ? 6 : 5" class="px-3 py-2 text-slate-500">
                                                                За {{ monthLabel }} бонуса нет — удержания не будет,
                                                                долг <b class="tabular-nums text-amber-800">{{ money(r.debt_remaining) }}</b> переходит на следующий месяц.
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </template>
                                        <p v-else class="rounded-lg border border-dashed border-amber-200 bg-amber-50/30 px-3 py-2 text-xs text-slate-400">Долгов нет</p>
                                    </div>
                                    <!-- Корректировки сотрудника за месяц -->
                                    <div class="mb-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Корректировки · {{ monthLabel }}</span>
                                            <button v-if="canManage" class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="openAdj(r.uid)">+ добавить</button>
                                        </div>
                                        <div v-if="r.adjustments?.length" class="divide-y divide-slate-50 text-xs">
                                            <div v-for="a in r.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-1.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                                                    <span class="text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} дн.</template><template v-if="a.note"> · {{ a.note }}</template><template v-if="a.creator"> · {{ a.creator }}</template> · внесено {{ formatDateTime(a.created_at) }}</span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                                                    <button v-if="canManage" class="text-slate-300 hover:text-rose-600" title="Удалить" @click="delAdj(a)">✕</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="py-1 text-xs text-slate-300">Нет корректировок</div>
                                    </div>
                                    <div v-if="r.dealsList && r.dealsList.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                        <table class="min-w-full divide-y divide-slate-100 text-xs">
                                            <thead class="text-left uppercase tracking-wide text-slate-400">
                                                <tr>
                                                    <th class="px-3 py-2">Сделка</th>
                                                    <th class="px-3 py-2">Этап</th>
                                                    <th class="px-3 py-2 text-right">Сумма</th>
                                                    <th class="px-3 py-2 text-right">Оплачено</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">Расходы</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">Налог</th>
                                                    <th class="px-3 py-2 text-right text-emerald-600">Бонус (ЗП)</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-50">
                                                <tr v-for="d in r.dealsList" :key="d.id" class="hover:bg-slate-50">
                                                    <td class="px-3 py-2">
                                                        <Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link>
                                                        <span class="ml-1 text-slate-400">{{ d.number }}</span>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.expense) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.tax) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                                        {{ money(d.bonus) }}
                                                        <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="'Ручной % финансиста: ' + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="px-3 py-2 text-[11px] text-slate-400">🟢 «Оплата успешно» — в ЗП; 🟡 «Акт утверждение» — ожидает оплаты, ещё не в ЗП.</p>
                                    </div>
                                    <div v-else class="py-2 text-center text-xs text-slate-400">Нет сделок на «Оплата успешно» / «Акт утверждение»</div>
                                </td>
                            </tr>
                        </template>
                        </template>
                        </template>
                        </template>
                        <tr v-if="!rows.length"><td colspan="7" class="px-4 py-8 text-center text-slate-400">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Шкала бонусов — только отдел продаж/финансист/админ -->
            <p v-if="seesBonusScale" class="mt-3 text-xs text-slate-400">К выплате = оклад + бонус − удержания (отгул/больничный/штраф/аванс) + премии за выбранный месяц. Почасовой оклад: если сотруднику введены отработанные часы за месяц, оклад начисляется как часы × ставка за час (ставка = оклад ÷ норма часов месяца, норма — в шапке страницы); часы не введены — полный оклад. Отгул/больничный днями: удержание = оклад / 22 × дни. Остаток = сумма договора − налог {{ taxRate }}% − расходы. Бонус по марже сделки (остаток/сумма), выплачивается пропорционально оплаченной доле (оплачено/сумма): до 10% — нет; 11–15% — 5%; 16–20% — 7%; 21–30% — 10%; 31–40% — 13%; от 41% — 15% от остатка. Чистая прибыль компании = остаток − бонус.</p>
        </template>

        <!-- Модалка корректировки -->
        <Modal :show="showAdj" @close="showAdj = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Корректировка ЗП</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сотрудник *</label>
                        <select v-model="adjForm.user_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">— выберите —</option>
                            <!-- Сотрудник обеих фирм в списке один раз — в основной фирме. -->
                            <optgroup v-for="g in adjGroups" :key="g.key" :label="g.label">
                                <option v-for="r in g.list" :key="r.uid" :value="r.uid">{{ r.user }}</option>
                            </optgroup>
                        </select>
                        <div v-if="adjForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ adjForm.errors.user_id }}</div>
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                        <button v-for="(label, t) in newAdjTypes" :key="t" type="button" @click="adjForm.type = t"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="adjForm.type === t ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">{{ label }}</button>
                        <!-- Долг — не корректировка, а своя сущность (гасится из бонусов
                             месяцами), поэтому кнопка переключает на форму долга.
                             Цветом отделена от типов корректировки. -->
                        <button type="button" @click="switchToDebt"
                            class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 transition-all hover:border-amber-400 hover:bg-amber-100"
                            title="Долг гасится частями из бонусов — откроется отдельная форма">
                            Долг →
                        </button>
                    </div>
                    <div v-if="adjForm.type === 'absence' || adjForm.type === 'sick'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дней (сумма = оклад / 22 × дни)</label>
                        <input v-model="adjForm.days" type="number" min="0.5" step="0.5" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.days" class="mt-1 text-xs text-red-600">{{ adjForm.errors.days }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сумма, ₸ {{ adjForm.type === 'absence' || adjForm.type === 'sick' ? '(или авто по дням)' : '*' }}</label>
                        <input v-model="adjForm.amount" type="number" min="0" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.amount" class="mt-1 text-xs text-red-600">{{ adjForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дата *</label>
                        <input v-model="adjForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.date" class="mt-1 text-xs text-red-600">{{ adjForm.errors.date }}</div>
                    </div>
                    <!-- Аванс — реальные деньги: откуда выданы (уйдёт в Расходы на Финансах) -->
                    <div v-if="adjForm.type === 'advance'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Откуда выданы деньги *</label>
                        <div class="flex gap-2">
                            <button type="button" @click="adjForm.payment_method = 'cash'"
                                :class="adjForm.payment_method === 'cash' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">💵 Наличные</button>
                            <button type="button" @click="adjForm.payment_method = 'bank'"
                                :class="adjForm.payment_method === 'bank' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">🏦 Банк</button>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-400">Аванс автоматически попадёт в Расходы на Финансах (категория «Расходы по сотрудникам»)</p>
                        <p class="mt-1 rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-700">
                            Аванс — <b>разовый</b>: удерживается целиком в этом месяце, сумма каждый месяц своя.
                            Если деньги выданы <b>в долг</b> и должны гаситься частями из бонусов — закройте это окно
                            и нажмите «+ выдать долг» у сотрудника.
                        </p>
                    </div>
                    <div :class="adjForm.type === 'absence' || adjForm.type === 'sick' || adjForm.type === 'advance' ? '' : 'sm:col-span-2'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Комментарий</label>
                        <input v-model="adjForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="Причина…" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showAdj = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="adjForm.processing || !adjForm.user_id" @click="submitAdj">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Модалка выдачи долга -->
        <Modal :show="showDebt" @close="showDebt = false" max-width="lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Выдать долг сотруднику</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Долг гасится автоматически — фиксированной суммой каждый месяц и <b>только из бонуса</b>.
                    Оклад не удерживается: нет бонуса в месяце — нет и удержания, остаток переходит дальше.
                </p>
                <p class="mt-2 rounded bg-slate-50 px-2 py-1 text-[11px] text-slate-500">
                    Это <b>не аванс</b>. Разовую выдачу, которая удерживается целиком в этом же месяце,
                    заводите через «Корректировка ЗП» → «Аванс».
                </p>
                <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сотрудник *</label>
                        <select v-model="debtForm.user_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">— выберите —</option>
                            <optgroup v-for="g in adjGroups" :key="g.key" :label="g.label">
                                <option v-for="r in g.list" :key="r.uid" :value="r.uid">{{ r.user }}</option>
                            </optgroup>
                        </select>
                        <div v-if="debtForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ debtForm.errors.user_id }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сумма долга, ₸ *</label>
                        <input v-model="debtForm.amount" type="number" min="1" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="debtForm.errors.amount" class="mt-1 text-xs text-red-600">{{ debtForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Удерживать в месяц, ₸ *</label>
                        <input v-model="debtForm.monthly_amount" type="number" min="1" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="debtForm.errors.monthly_amount" class="mt-1 text-xs text-red-600">{{ debtForm.errors.monthly_amount }}</div>
                        <p class="mt-1 text-[11px] text-slate-400">Если бонус месяца меньше — удержим сколько есть.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дата выдачи *</label>
                        <input v-model="debtForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="debtForm.errors.date" class="mt-1 text-xs text-red-600">{{ debtForm.errors.date }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Откуда выданы деньги *</label>
                        <div class="flex gap-2">
                            <button type="button" @click="debtForm.payment_method = 'cash'"
                                :class="debtForm.payment_method === 'cash' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">💵 Наличные</button>
                            <button type="button" @click="debtForm.payment_method = 'bank'"
                                :class="debtForm.payment_method === 'bank' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium">🏦 Банк</button>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Комментарий</label>
                        <input v-model="debtForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="За что выдан…" />
                    </div>
                </div>
                <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                    Деньги реальные: автоматически создастся подтверждённый расход компании
                    (категория «Расходы по сотрудникам») и уменьшит кассу/банк.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showDebt = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="debtForm.processing || !debtForm.user_id" @click="submitDebt">Выдать долг</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
