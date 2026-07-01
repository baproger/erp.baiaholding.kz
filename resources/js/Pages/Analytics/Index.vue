<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ funnel: Array, byStatus: Object, monthly: Array, topClients: Array, conversion: Object, totals: Object });

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';
const maxFunnel = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));
const maxMonthly = computed(() => Math.max(1, ...props.monthly.flatMap((m) => [m.income, m.expense])));
const maxClient = computed(() => Math.max(1, ...props.topClients.map((c) => c.total)));
const statusLabels = { draft: 'Черновик', active: 'Активные', closed: 'Закрыты', cancelled: 'Отменены' };
</script>

<template>
    <Head title="Аналитика" />
    <AppLayout>
        <template #header>Аналитика</template>

        <!-- KPI row -->
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Доход</div><div class="mt-1 text-2xl font-bold text-green-600">{{ money(totals.income) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Расходы</div><div class="mt-1 text-2xl font-bold text-red-600">{{ money(totals.expense) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Прибыль</div><div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(totals.income - totals.expense) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Конверсия</div><div class="mt-1 text-2xl font-bold text-gray-800">{{ conversion.rate }}%</div><div class="text-xs text-gray-400">{{ conversion.won }} из {{ conversion.total }}</div></div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Funnel -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-4 font-semibold text-gray-700">Воронка продаж</h3>
                <div class="space-y-3">
                    <div v-for="f in funnel" :key="f.name">
                        <div class="mb-1 flex justify-between text-sm"><span>{{ f.name }}</span><span class="text-gray-500">{{ f.count }} · {{ money(f.total) }}</span></div>
                        <div class="h-3 rounded bg-gray-100">
                            <div class="h-3 rounded" :style="{ width: (f.count / maxFunnel * 100) + '%', backgroundColor: f.color }"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly income/expense -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-4 font-semibold text-gray-700">Доходы и расходы по месяцам</h3>
                <div class="flex items-end justify-between gap-2" style="height: 180px">
                    <div v-for="m in monthly" :key="m.month" class="flex flex-1 flex-col items-center justify-end gap-1">
                        <div class="flex w-full items-end justify-center gap-0.5" style="height: 150px">
                            <div class="w-3 rounded-t bg-green-500" :style="{ height: (m.income / maxMonthly * 150) + 'px' }" :title="money(m.income)"></div>
                            <div class="w-3 rounded-t bg-red-400" :style="{ height: (m.expense / maxMonthly * 150) + 'px' }" :title="money(m.expense)"></div>
                        </div>
                        <span class="text-[10px] text-gray-400">{{ m.month.slice(5) }}</span>
                    </div>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-green-500"></span> Доход</span>
                    <span class="flex items-center gap-1"><span class="h-2 w-2 rounded bg-red-400"></span> Расход</span>
                </div>
            </div>

            <!-- Deals by status -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-4 font-semibold text-gray-700">Сделки по статусам</h3>
                <div class="space-y-2 text-sm">
                    <div v-for="(cnt, status) in byStatus" :key="status" class="flex justify-between border-b py-2">
                        <span>{{ statusLabels[status] ?? status }}</span><span class="font-medium">{{ cnt }}</span>
                    </div>
                    <div v-if="!Object.keys(byStatus).length" class="py-4 text-center text-gray-400">Нет данных</div>
                </div>
            </div>

            <!-- Top clients -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="mb-4 font-semibold text-gray-700">Топ клиентов</h3>
                <div class="space-y-3">
                    <div v-for="c in topClients" :key="c.name">
                        <div class="mb-1 flex justify-between text-sm"><span>{{ c.name }}</span><span class="text-gray-500">{{ money(c.total) }} · {{ c.deals }} сд.</span></div>
                        <div class="h-2.5 rounded bg-gray-100"><div class="h-2.5 rounded bg-indigo-500" :style="{ width: (c.total / maxClient * 100) + '%' }"></div></div>
                    </div>
                    <div v-if="!topClients.length" class="py-4 text-center text-gray-400">Нет данных</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
