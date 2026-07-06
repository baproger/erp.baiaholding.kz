<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { money } from '@/utils/format';

const props = defineProps({ rows: Array, leadership: Boolean, rate: Number, taxRate: Number, totals: Object });
const me = props.rows[0] ?? null;
</script>

<template>
    <Head title="Зарплата" />
    <AppLayout>
        <template #header>{{ $t('page.payroll', 'Зарплата и бонусы') }}</template>

        <!-- Manager: only own earnings -->
        <div v-if="!leadership" class="max-w-md">
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
                <div class="text-xs uppercase text-slate-400">Ваша ЗП</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.bonus ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Успешных сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                </div>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Сумма сделок</div><div class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ money(totals.budget) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Налог {{ taxRate }}%</div><div class="mt-1 text-xl font-semibold tabular-nums text-rose-600">− {{ money(totals.tax) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Расходы</div><div class="mt-1 text-xl font-semibold tabular-nums text-rose-600">− {{ money(totals.expense) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"><div class="text-[11px] uppercase tracking-wide text-slate-400">Бонусы (ЗП, {{ rate }}%)</div><div class="mt-1 text-xl font-semibold tabular-nums text-emerald-600">{{ money(totals.bonus) }}</div></div>
                <div class="col-span-2 rounded-xl p-5 text-white shadow-md lg:col-span-1" style="background-color: #1A3B5C"><div class="text-[11px] uppercase tracking-wide text-white/60">Чистая прибыль компании</div><div class="mt-1 text-xl font-semibold tabular-nums">{{ money(totals.company) }}</div></div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Сотрудник</th>
                            <th class="px-4 py-3">Сделок</th>
                            <th class="px-4 py-3">Успешных</th>
                            <th class="px-4 py-3 text-right">Сумма сделок</th>
                            <th class="px-4 py-3 text-right text-rose-600">Налог {{ taxRate }}%</th>
                            <th class="px-4 py-3 text-right text-rose-600">Расходы</th>
                            <th class="px-4 py-3 text-right text-emerald-600">Бонус (ЗП)</th>
                            <th class="px-4 py-3 text-right">Чистая прибыль</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in rows" :key="r.user" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                    <span class="font-medium text-slate-900">{{ r.user }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ r.deals }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ r.closed }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ money(r.budget) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.tax) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.expense) }}</td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums" :class="r.company >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(r.company) }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-4 py-8 text-center text-slate-400">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-400">Остаток = сумма сделок − налог {{ taxRate }}% − расходы. Бонус сотрудника (ЗП) = {{ rate }}% от остатка; чистая прибыль компании = остаток − бонус.</p>
        </template>
    </AppLayout>
</template>
