<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Avatar from '@/Components/Avatar.vue';
import Pagination from '@/Components/Pagination.vue';

defineProps({ invoices: Object, filters: Object, totals: Object, salaries: Array });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
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
    </AppLayout>
</template>
