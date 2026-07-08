<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Avatar from '@/Components/Avatar.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatDate } from '@/utils/format';

const props = defineProps({ invoices: Object, expenses: Object, expenseTotals: Object, filters: Object, totals: Object, salaries: Array });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры раздела «Расходы»: вид (материалы/прочие), оплата (нал/банк), статус.
const expKind = ref(props.filters?.exp_kind ?? '');
const expMethod = ref(props.filters?.exp_method ?? '');
const expStatus = ref(props.filters?.exp_status ?? '');
const applyExpFilters = () => router.get(route('finance.index'), {
    ...props.filters,
    exp_kind: expKind.value || undefined,
    exp_method: expMethod.value || undefined,
    exp_status: expStatus.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

// Ссылка на сделку/заказ расхода (морф: deal | project).
const expLink = (e) => e.expenseable_type === 'project'
    ? route('projects.show', e.expenseable_id)
    : route('deals.show', e.expenseable_id);
</script>

<template>
    <Head title="Финансы" />
    <AppLayout>
        <template #header>{{ $t('page.finance', 'Финансы') }}</template>

        <!-- KPI — Сумма договоров − Налог − Расходы − ЗП = Чистая прибыль -->
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Сумма договоров</div>
                <div class="mt-1 text-xl font-bold text-slate-800">{{ money(totals.budget) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">оплачено {{ money(totals.paid) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Налог</div>
                <div class="mt-1 text-xl font-bold text-red-600">−{{ money(totals.tax) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Расходы компании</div>
                <div class="mt-1 text-xl font-bold text-red-600">−{{ money(totals.expenses) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">ЗП сотрудникам</div>
                <div class="mt-1 text-xl font-bold text-amber-600">−{{ money(totals.salaries) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Остаток</div>
                <div class="mt-1 text-xl font-bold text-slate-800">{{ money(totals.budget - totals.tax - totals.expenses) }}</div>
            </div>
            <div class="col-span-2 rounded-2xl bg-slate-900 p-5 shadow-sm lg:col-span-1">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Чистая прибыль</div>
                <div class="mt-1 text-xl font-bold" :class="totals.net >= 0 ? 'text-emerald-400' : 'text-red-400'">{{ money(totals.net) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Зарплаты сотрудников -->
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200 lg:col-span-1">
                <h3 class="mb-4 text-sm font-semibold text-slate-900">Зарплаты сотрудников</h3>
                <div class="space-y-2">
                    <div v-for="s in salaries" :key="s.user" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                        <div class="flex items-center gap-2.5">
                            <Avatar :name="s.user" :src="s.avatar" :size="34" />
                            <div>
                                <div class="text-sm font-medium text-slate-800">{{ s.user }}</div>
                                <div class="text-[11px] text-slate-400">маржа {{ s.margin }}%</div>
                            </div>
                        </div>
                        <div class="text-sm font-bold text-green-600">{{ money(s.bonus) }}</div>
                    </div>
                    <div v-if="!salaries.length" class="py-6 text-center text-sm text-slate-400">Нет данных</div>
                </div>
                <div v-if="salaries.length" class="mt-3 flex items-center justify-between border-t pt-3 text-sm">
                    <span class="font-medium text-slate-500">Итого ЗП</span>
                    <span class="font-bold text-amber-600">{{ money(totals.salaries) }}</span>
                </div>
            </div>

            <!-- Счета -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm border border-slate-200 lg:col-span-2">
                <div class="border-b px-6 py-4 text-sm font-semibold text-slate-900">Счета</div>
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Номер</th><th class="px-4 py-3">Клиент</th><th class="px-4 py-3">Сумма</th>
                            <th class="px-4 py-3">Оплачено</th><th class="px-4 py-3">Статус</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">{{ inv.number }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ inv.client?.name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ money(inv.amount) }}</td>
                            <td class="px-4 py-3 text-green-600">{{ money(inv.payments_sum_amount ?? 0) }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="inv.status" /></td>
                        </tr>
                        <tr v-if="!invoices.data.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">Счетов нет</td></tr>
                    </tbody>
                </table>
                <div class="p-4"><Pagination :links="invoices.links" /></div>
            </div>
        </div>

        <!-- ================= Расходы ================= -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                <h3 class="text-sm font-semibold text-slate-900">Расходы</h3>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <select v-model="expKind" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Все виды</option>
                        <option value="material">Материальные (склад)</option>
                        <option value="other">Прочие</option>
                    </select>
                    <select v-model="expMethod" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Любая оплата</option>
                        <option value="cash">Наличные</option>
                        <option value="bank">Банк (счёт)</option>
                    </select>
                    <select v-model="expStatus" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Все статусы</option>
                        <option value="pending">Ждёт бухгалтера</option>
                        <option value="confirmed">Подтверждён</option>
                    </select>
                </div>
            </div>

            <!-- Сводка по расходам -->
            <div class="grid grid-cols-2 gap-3 px-6 py-4 lg:grid-cols-5">
                <div class="rounded-xl bg-indigo-50 p-3"><div class="text-[11px] font-medium text-indigo-700">Материальные (склад)</div><div class="mt-0.5 text-base font-bold tabular-nums text-indigo-700">{{ money(expenseTotals.material) }}</div></div>
                <div class="rounded-xl bg-slate-100 p-3"><div class="text-[11px] font-medium text-slate-600">Прочие</div><div class="mt-0.5 text-base font-bold tabular-nums text-slate-700">{{ money(expenseTotals.other) }}</div></div>
                <div class="rounded-xl bg-emerald-50 p-3"><div class="text-[11px] font-medium text-emerald-700">Наличными</div><div class="mt-0.5 text-base font-bold tabular-nums text-emerald-700">{{ money(expenseTotals.cash) }}</div></div>
                <div class="rounded-xl bg-emerald-50 p-3"><div class="text-[11px] font-medium text-emerald-700">Банк (счёт)</div><div class="mt-0.5 text-base font-bold tabular-nums text-emerald-700">{{ money(expenseTotals.bank) }}</div></div>
                <div class="rounded-xl bg-amber-50 p-3"><div class="text-[11px] font-medium text-amber-700">Ждёт бухгалтера ({{ expenseTotals.pending_count }})</div><div class="mt-0.5 text-base font-bold tabular-nums text-amber-700">{{ money(expenseTotals.pending_sum) }}</div></div>
            </div>

            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Сумма</th>
                        <th class="px-4 py-3">Описание</th>
                        <th class="px-4 py-3">Сделка / заказ</th>
                        <th class="px-4 py-3">Вид</th>
                        <th class="px-4 py-3">Оплата</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3">Автор</th>
                        <th class="px-4 py-3">Дата</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="e in expenses.data" :key="e.id" class="transition-colors hover:bg-slate-50">
                        <td class="px-6 py-3 font-semibold tabular-nums text-slate-900">{{ money(e.amount) }}</td>
                        <td class="max-w-[220px] truncate px-4 py-3 text-slate-500">{{ e.description || '—' }}</td>
                        <td class="px-4 py-3"><Link :href="expLink(e)" class="font-medium text-indigo-600 hover:underline">{{ e.expenseable?.number ?? '—' }}</Link></td>
                        <td class="px-4 py-3">
                            <span v-if="e.material" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">склад</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">прочий</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.payment_method === 'cash' ? 'наличные' : (e.payment_method === 'bank' ? 'банк' : '—') }}</td>
                        <td class="px-4 py-3">
                            <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Подтверждён</span>
                            <span v-else-if="e.status === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Ждёт бухгалтера</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Черновик</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.responsible?.name ?? '—' }}<span v-if="e.confirmed_by?.name" class="block text-[10px] text-slate-400">подтв.: {{ e.confirmed_by.name }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-400">{{ formatDate(e.date) }}</td>
                    </tr>
                    <tr v-if="!expenses.data.length"><td colspan="8" class="px-6 py-10 text-center text-slate-400">Расходов нет</td></tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="expenses.links" /></div>
        </div>
    </AppLayout>
</template>
