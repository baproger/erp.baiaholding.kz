<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ stats: Object });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
</script>

<template>
    <Head title="Дашборд" />
    <AppLayout>
        <template #header>Дашборд</template>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <Link :href="route('deals.index')" class="rounded-lg bg-white p-5 shadow transition hover:shadow-md">
                <div class="text-xs uppercase text-gray-400">Сделки</div>
                <div class="mt-1 text-3xl font-bold text-gray-800">{{ stats.deals }}</div>
                <div class="mt-1 text-sm text-gray-500">Активных: {{ stats.deals_active }}</div>
            </Link>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs uppercase text-gray-400">Сумма в работе</div>
                <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(stats.deals_budget) }}</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-xs uppercase text-gray-400">Заработано (факт)</div>
                <div class="mt-1 text-2xl font-bold text-green-600">{{ money(stats.earned) }}</div>
                <div class="mt-1 text-sm text-gray-500">Успешных сделок: {{ stats.deals_won }}</div>
            </div>
            <Link :href="route('projects.index')" class="rounded-lg bg-white p-5 shadow transition hover:shadow-md">
                <div class="text-xs uppercase text-gray-400">Цех (заказы)</div>
                <div class="mt-1 text-3xl font-bold text-gray-800">{{ stats.projects }}</div>
            </Link>
            <Link :href="route('clients.index')" class="rounded-lg bg-white p-5 shadow transition hover:shadow-md">
                <div class="text-xs uppercase text-gray-400">Контрагенты</div>
                <div class="mt-1 text-3xl font-bold text-gray-800">{{ stats.clients }}</div>
                <div class="mt-1 text-sm text-gray-500">Открытых задач: {{ stats.tasks_open }}</div>
            </Link>
        </div>

        <div class="mt-8 rounded-lg bg-white p-6 shadow">
            <h3 class="mb-2 font-semibold text-gray-700">Добро пожаловать в BAIA ERP</h3>
            <p class="text-sm text-gray-500">
                Управляйте продажами, проектами и клиентами из единой системы.
                Используйте меню слева для перехода между модулями.
            </p>
        </div>
    </AppLayout>
</template>
