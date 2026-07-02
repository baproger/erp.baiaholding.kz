<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money } from '@/utils/format';

const props = defineProps({ rows: Array, leadership: Boolean, rate: Number, totals: Object });
const me = props.rows[0] ?? null;
</script>

<template>
    <Head title="Зарплата" />
    <AppLayout>
        <template #header>Зарплата и бонусы</template>

        <!-- Manager: only own earnings -->
        <div v-if="!leadership" class="max-w-md">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-100">
                <div class="text-xs uppercase text-gray-400">Ваш бонус ({{ rate }}% от чистой прибыли)</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.bonus ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">Закрыто сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">Ваша чистая прибыль</span><span class="font-medium">{{ money(me?.net ?? 0) }}</span></div>
                </div>
                <p class="mt-4 text-xs text-gray-400">Бонус = {{ rate }}% от чистой прибыли по вашим оплаченным сделкам (доход − расходы).</p>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100"><div class="text-xs uppercase text-gray-400">Чистая прибыль (всего)</div><div class="mt-1 text-2xl font-bold text-gray-800">{{ money(totals.net) }}</div></div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100"><div class="text-xs uppercase text-gray-400">Бонусы сотрудникам ({{ rate }}%)</div><div class="mt-1 text-2xl font-bold text-green-600">{{ money(totals.bonus) }}</div></div>
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-100"><div class="text-xs uppercase text-gray-400">Остаётся компании</div><div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(totals.company) }}</div></div>
            </div>

            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Сотрудник</th>
                            <th class="px-4 py-3">Сделок</th>
                            <th class="px-4 py-3">Закрыто</th>
                            <th class="px-4 py-3">Доход</th>
                            <th class="px-4 py-3">Расходы</th>
                            <th class="px-4 py-3">Чистая прибыль</th>
                            <th class="px-4 py-3 text-green-600">Бонус ({{ rate }}%)</th>
                            <th class="px-4 py-3 text-indigo-600">Компании</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in rows" :key="r.user" class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ r.user }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ r.deals }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ r.closed }}</td>
                            <td class="px-4 py-3 text-green-600">{{ money(r.income) }}</td>
                            <td class="px-4 py-3 text-red-600">{{ money(r.expense) }}</td>
                            <td class="px-4 py-3 font-medium" :class="r.net >= 0 ? 'text-gray-800' : 'text-red-600'">{{ money(r.net) }}</td>
                            <td class="px-4 py-3 font-bold text-green-600">{{ money(r.bonus) }}</td>
                            <td class="px-4 py-3 text-indigo-600">{{ money(r.company) }}</td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="px-4 py-8 text-center text-gray-400">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-400">Чистая прибыль = доход (оплачено) − подтверждённые расходы. Бонус сотрудника = {{ rate }}% чистой прибыли, остальное — компании.</p>
        </template>
    </AppLayout>
</template>
