<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';

defineProps({ invoices: Object, filters: Object, totals: Object });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
</script>

<template>
    <Head title="Финансы" />
    <AppLayout>
        <template #header>Финансы</template>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Выставлено</div><div class="mt-1 text-2xl font-bold text-gray-800">{{ money(totals.invoiced) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Оплачено</div><div class="mt-1 text-2xl font-bold text-green-600">{{ money(totals.paid) }}</div></div>
            <div class="rounded-lg bg-white p-5 shadow"><div class="text-xs uppercase text-gray-400">Расходы</div><div class="mt-1 text-2xl font-bold text-red-600">{{ money(totals.expenses) }}</div></div>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Номер</th><th class="px-4 py-3">Клиент</th><th class="px-4 py-3">Сумма</th>
                        <th class="px-4 py-3">Оплачено</th><th class="px-4 py-3">Статус</th><th class="px-4 py-3">Срок</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ inv.number }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ inv.client?.name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ money(inv.amount) }}</td>
                        <td class="px-4 py-3 text-green-600">{{ money(inv.payments_sum_amount ?? 0) }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="inv.status" /></td>
                        <td class="px-4 py-3 text-gray-500">{{ inv.due_date ?? '—' }}</td>
                    </tr>
                    <tr v-if="!invoices.data.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Счетов нет</td></tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="invoices.links" /></div>
        </div>
    </AppLayout>
</template>
