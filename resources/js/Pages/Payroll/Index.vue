<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { money } from '@/utils/format';

const props = defineProps({ rows: Array, leadership: Boolean, taxRate: Number, totals: Object });
const me = props.rows[0] ?? null;

const open = ref(new Set());
const toggle = (uid) => { const s = new Set(open.value); s.has(uid) ? s.delete(uid) : s.add(uid); open.value = s; };
</script>

<template>
    <Head title="Зарплата" />
    <AppLayout>
        <template #header>{{ $t('page.payroll', 'Зарплата и бонусы') }}</template>

        <!-- Manager: only own earnings -->
        <div v-if="!leadership" class="max-w-2xl">
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
                <div class="text-xs uppercase text-slate-400">Ваша ЗП (оклад + бонус)</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.payout ?? me?.bonus ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Оклад</span><span class="font-medium tabular-nums">{{ money(me?.salary ?? 0) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Бонус по марже сделок</span><span class="font-medium tabular-nums text-emerald-600">{{ money(me?.bonus ?? 0) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Успешных сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                </div>
            </div>
            <div v-if="me?.dealsList?.length" class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
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
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">{{ money(d.bonus) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-7">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Сумма договоров</div><div class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ money(totals.budget) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Налог {{ taxRate }}%</div><div class="mt-1 text-xl font-semibold tabular-nums text-rose-600">− {{ money(totals.tax) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Расходы</div><div class="mt-1 text-xl font-semibold tabular-nums text-rose-600">− {{ money(totals.expense) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Бонусы (по марже)</div><div class="mt-1 text-xl font-semibold tabular-nums text-emerald-600">{{ money(totals.bonus) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Оклады</div><div class="mt-1 text-xl font-semibold tabular-nums text-slate-700">{{ money(totals.salary) }}</div></div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-emerald-600/70">Итого ЗП (оклад + бонус)</div><div class="mt-1 text-xl font-semibold tabular-nums text-emerald-700">{{ money(totals.payout) }}</div></div>
                <div class="col-span-2 rounded-xl p-5 text-white shadow-md lg:col-span-1" style="background-color: #1A3B5C"><div class="text-[11px] uppercase tracking-wide text-white/60">Чистая прибыль компании</div><div class="mt-1 text-xl font-semibold tabular-nums">{{ money(totals.company) }}</div></div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Сотрудник</th>
                            <th class="px-4 py-3">Сделок</th>
                            <th class="px-4 py-3">Успешных</th>
                            <th class="px-4 py-3 text-right">Сумма договоров</th>
                            <th class="px-4 py-3 text-right text-rose-600">Налог {{ taxRate }}%</th>
                            <th class="px-4 py-3 text-right text-rose-600">Расходы</th>
                            <th class="px-4 py-3 text-right text-emerald-600">Бонус</th>
                            <th class="px-4 py-3 text-right">Оклад</th>
                            <th class="px-4 py-3 text-right text-emerald-600">Итого ЗП</th>
                            <th class="px-4 py-3 text-right">Чистая прибыль</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="r in rows" :key="r.uid">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="toggle(r.uid)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open.has(r.uid) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                        <span class="font-medium text-slate-900">{{ r.user }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-500">{{ r.deals }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ r.closed }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ money(r.budget) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.tax) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.expense) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ money(r.salary) }}</td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums text-emerald-600">{{ money(r.payout) }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums" :class="r.company >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(r.company) }}</td>
                            </tr>
                            <tr v-if="open.has(r.uid)" class="bg-slate-50/60">
                                <td colspan="10" class="px-4 py-3">
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
                                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">{{ money(d.bonus) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="px-3 py-2 text-[11px] text-slate-400">🟢 «Оплата успешно» — в ЗП; 🟡 «Акт утверждение» — ожидает оплаты, ещё не в ЗП.</p>
                                    </div>
                                    <div v-else class="py-2 text-center text-xs text-slate-400">Нет сделок на «Оплата успешно» / «Акт утверждение»</div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="8" class="px-4 py-8 text-center text-slate-400">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-400">Остаток = сумма договора − налог {{ taxRate }}% − расходы. Бонус считается по марже каждой сделки (остаток/сумма): до 10% — нет; 11–15% — 5%; 16–20% — 7%; 21–25% — 10%; 26–40% — 13%; от 41% — 15% от остатка. Чистая прибыль компании = остаток − бонус.</p>
        </template>
    </AppLayout>
</template>
