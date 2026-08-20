<script setup>
// Страница «Бонусы»: отдельно от оклада (правило от 20.08.2026).
// Итого бонус = бонус за месяц − авансы из бонуса − погашение долга.
import { ref, computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { money, formatDate } from '@/utils/format';
import { useStickyFilters } from '@/composables/useStickyFilters';

const props = defineProps({ rows: Array, leadership: Boolean, canManage: Boolean, month: String, totals: Object, companies: { type: Array, default: () => [] }, departments: { type: Array, default: () => [] }, normHours: Number, deptNorms: { type: [Object, Array], default: () => ({}) }, taxRate: Number });

const me = computed(() => props.rows[0] ?? null);
const monthLabel = computed(() => new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));
const monthShort = computed(() => new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long' }));

// Период — по умолчанию текущий месяц (серверный фильтр, как на «Зарплате»).
const monthSel = ref(props.month);
const setMonth = () => router.get(route('payroll.bonuses'), { month: monthSel.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('payroll-bonuses', { monthSel }, setMonth);

// Шкала бонусов — коммерческая информация отдела продаж.
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

const search = ref('');
// В ведомость бонусов попадают только те, у кого в месяце есть бонус,
// аванс из бонуса или удержание долга — остальные строки лишь шумят.
const list = computed(() => props.rows.filter((r) => {
    const q = search.value.trim().toLowerCase();
    if (q && !(r.user ?? '').toLowerCase().includes(q)) return false;
    return (r.bonus_month || 0) !== 0 || (r.adv_bonus || 0) !== 0 || (r.debt_charge || 0) !== 0;
}));
const sums = computed(() => ({
    bonus: list.value.reduce((n, r) => n + (r.bonus_month || 0), 0),
    adv: list.value.reduce((n, r) => n + (r.adv_bonus || 0), 0),
    debt: list.value.reduce((n, r) => n + (r.debt_charge || 0), 0),
    final: list.value.reduce((n, r) => n + (r.bonus_final || 0), 0),
}));

const open = ref(new Set());
const toggle = (uid) => { const s = new Set(open.value); s.has(uid) ? s.delete(uid) : s.add(uid); open.value = s; };
</script>

<template>
    <Head title="Бонусы" />
    <AppLayout>
        <template #header><span class="truncate">Бонусы</span></template>
        <FinanceLayout title="Бонусы" subtitle="бонус по марже сделок − авансы из бонуса − погашение долга = итого" active="payroll.bonuses" :wide="leadership">
            <template #actions>
                <label class="flex items-center gap-1 text-xs font-normal text-slate-400">месяц
                    <input v-model="monthSel" @change="setMonth" type="month" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <input v-if="leadership" v-model="search" type="search" placeholder="Поиск по сотруднику…"
                    class="w-44 rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
            </template>

            <!-- Личная карточка сотрудника -->
            <div v-if="!leadership" class="grid max-w-5xl grid-cols-1 items-start gap-4 lg:grid-cols-3">
                <div class="space-y-4" :class="seesBonusScale ? 'lg:col-span-2' : 'lg:col-span-3'">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 shadow-md">
                        <div class="px-6 py-5" style="background-color:#1A3B5C">
                            <div class="text-[11px] uppercase tracking-wide text-white/60">Итого бонус · {{ monthLabel }}</div>
                            <div class="mt-1 whitespace-nowrap text-3xl font-bold tabular-nums text-emerald-300">{{ money(me?.bonus_final ?? 0) }}</div>
                        </div>
                        <div class="space-y-2 bg-white p-6 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Бонус по марже за {{ monthShort }}</span><span class="font-medium tabular-nums text-emerald-600">{{ money(me?.bonus_month ?? 0) }}</span></div>
                            <div v-if="me?.adv_bonus" class="flex justify-between"><span class="text-slate-500">Аванс из бонуса</span><span class="font-medium tabular-nums text-rose-600">− {{ money(me.adv_bonus) }}</span></div>
                            <div v-if="me?.debt_charge > 0" class="flex justify-between"><span class="text-slate-500">Погашение долга</span><span class="font-medium tabular-nums text-amber-600">− {{ money(me.debt_charge) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Успешных сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                        </div>
                    </div>

                    <!-- Сделки, из которых сложился бонус -->
                    <div v-if="me?.dealsList?.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-slate-100 text-xs">
                            <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                                <tr><th class="px-3 py-2">Сделка</th><th class="px-3 py-2">Этап</th><th class="px-3 py-2 text-right">Сумма</th><th class="px-3 py-2 text-right">Оплачено</th><th class="px-3 py-2 text-right text-emerald-600">Бонус</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="d in me.dealsList" :key="d.id" class="hover:bg-slate-50">
                                    <td class="px-3 py-2"><Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link> <span class="text-slate-400">{{ d.number }}</span></td>
                                    <td class="px-3 py-2"><span :class="d.is_won ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span></td>
                                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">{{ money(d.bonus) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

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

            <!-- Руководство: плитки + ведомость -->
            <template v-else>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Бонусы за {{ monthShort }}</div>
                        <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ money(sums.bonus) }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Авансы из бонуса</div>
                        <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="sums.adv > 0 ? 'text-rose-600' : 'text-slate-300'">{{ sums.adv > 0 ? '−' + money(sums.adv) : '—' }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Погашение долгов</div>
                        <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="sums.debt > 0 ? 'text-amber-600' : 'text-slate-300'">{{ sums.debt > 0 ? '−' + money(sums.debt) : '—' }}</div>
                    </div>
                    <div class="rounded-xl p-4 shadow-md" style="background-color:#1A3B5C">
                        <div class="truncate text-[11px] uppercase tracking-wide text-white/60">Итого бонус · {{ monthLabel }}</div>
                        <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-300">{{ money(sums.final) }}</div>
                    </div>
                </div>

                <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="text-sm font-semibold text-slate-900">Бонусы · {{ monthLabel }}
                            <span class="ml-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ list.length }} сотр.</span>
                        </h3>
                        <span class="whitespace-nowrap rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-700">{{ money(sums.final) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr class="divide-x divide-slate-100">
                                    <th class="px-6 py-2.5">Сотрудник</th>
                                    <th class="px-4 py-2.5 text-right">Бонус за {{ monthShort }}</th>
                                    <th class="px-4 py-2.5 text-right">Аванс из бонуса</th>
                                    <th class="px-4 py-2.5 text-right" title="Погашение долга — только из бонуса">Долг за {{ monthShort }}</th>
                                    <th class="px-4 py-2.5 text-right">Итого бонус</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <template v-for="r in list" :key="r.uid">
                                    <tr class="group cursor-pointer divide-x divide-slate-100 transition-colors duration-150 hover:bg-slate-50/60" @click="toggle(r.uid)">
                                        <td class="px-6 py-2.5">
                                            <div class="flex items-center gap-2.5">
                                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200" :class="open.has(r.uid) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                                <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                                <div class="min-w-0 leading-tight">
                                                    <div class="truncate font-medium text-slate-900">{{ r.user }}</div>
                                                    <div v-if="r.deals > 0" class="text-[11px] text-slate-400">{{ r.deals }} сделок · {{ r.closed }} успешных</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="r.bonus_month > 0 ? 'font-medium text-emerald-600' : 'text-slate-300'">{{ r.bonus_month > 0 ? money(r.bonus_month) : '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="r.adv_bonus > 0 ? 'font-medium text-rose-600' : 'text-slate-300'">{{ r.adv_bonus > 0 ? '− ' + money(r.adv_bonus) : '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums" :class="r.debt_charge > 0 ? 'font-medium text-amber-600' : 'text-slate-300'"
                                            :title="r.debt_charge > 0 ? 'Останется долга: ' + money(r.debt_after) : ''">{{ r.debt_charge > 0 ? '− ' + money(r.debt_charge) : '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-bold tabular-nums" :class="r.bonus_final > 0 ? 'text-emerald-600' : 'text-slate-300'">{{ money(r.bonus_final) }}</td>
                                    </tr>
                                    <tr v-if="open.has(r.uid)" class="bg-slate-50/60">
                                        <td colspan="5" class="px-6 py-3">
                                            <div v-if="r.dealsList?.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                                <table class="min-w-full divide-y divide-slate-100 text-xs">
                                                    <thead class="text-left uppercase tracking-wide text-slate-400">
                                                        <tr><th class="px-3 py-2">Сделка</th><th class="px-3 py-2">Этап</th><th class="px-3 py-2 text-right">Сумма</th><th class="px-3 py-2 text-right">Оплачено</th><th class="px-3 py-2 text-right text-emerald-600">Бонус</th></tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-slate-50">
                                                        <tr v-for="d in r.dealsList" :key="d.id" class="hover:bg-slate-50">
                                                            <td class="px-3 py-2"><Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link> <span class="text-slate-400">{{ d.number }}</span></td>
                                                            <td class="px-3 py-2"><span :class="d.is_won ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span></td>
                                                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                                                            <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                                                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">{{ money(d.bonus) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div v-else class="py-1 text-center text-xs text-slate-400">Нет сделок за период</div>
                                            <!-- Аванс из бонуса и долг детально — в корректировках на «Зарплате» -->
                                            <div v-if="r.adjustments?.some((a) => a.type === 'advance' && a.source === 'bonus')" class="mt-2 text-[11px] text-slate-500">
                                                Авансы из бонуса:
                                                <span v-for="a in r.adjustments.filter((a) => a.type === 'advance' && a.source === 'bonus')" :key="a.id" class="ml-1 rounded-full bg-rose-50 px-2 py-0.5 font-medium tabular-nums text-rose-600">− {{ money(a.amount) }} · {{ formatDate(a.date) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
                                <tr class="divide-x divide-slate-100">
                                    <td class="px-6 py-3 text-slate-500">Итого</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-emerald-600">{{ money(sums.bonus) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums" :class="sums.adv > 0 ? 'text-rose-600' : 'text-slate-300'">{{ sums.adv > 0 ? '− ' + money(sums.adv) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums" :class="sums.debt > 0 ? 'text-amber-600' : 'text-slate-300'">{{ sums.debt > 0 ? '− ' + money(sums.debt) : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-emerald-700">{{ money(sums.final) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div v-if="!list.length" class="px-6 py-10 text-center text-sm text-slate-400">За {{ monthLabel }} бонусов, авансов из бонуса и удержаний долга нет</div>
                </div>
                <p class="mt-3 text-xs text-slate-400">Итого бонус = бонус по марже за месяц − авансы из бонуса − погашение долга. Аванс «из бонуса» заводится на «Зарплате» кнопкой «+ Корректировка» → Аванс → «Из бонуса». Долг гасится автоматически и только из бонуса.</p>
            </template>
        </FinanceLayout>
    </AppLayout>
</template>
