<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

defineProps({ logs: Object, filters: Object, tables: Array });
const actionLabel = { created: 'Создание', updated: 'Изменение', deleted: 'Удаление' };
const actionColor = { created: 'text-green-600', updated: 'text-amber-600', deleted: 'text-red-600' };
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <Head title="Аудит" />
    <AppLayout>
        <template #header>{{ $t('page.audit', 'Журнал аудита') }}</template>
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Время</th><th class="px-4 py-3">Пользователь</th>
                        <th class="px-4 py-3">Таблица</th><th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">Действие</th><th class="px-4 py-3">Поле</th>
                        <th class="px-4 py-3">Было → Стало</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-400">{{ fmt(log.created_at) }}</td>
                        <td class="px-4 py-3">{{ log.user?.name ?? 'Система' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ log.table_name }}</td>
                        <td class="px-4 py-3 text-slate-400">#{{ log.record_id }}</td>
                        <td class="px-4 py-3 font-medium" :class="actionColor[log.action]">{{ actionLabel[log.action] ?? log.action }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ log.field_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500">
                            <span v-if="log.field_name"><span class="text-red-500">{{ log.old_value ?? '∅' }}</span> → <span class="text-green-600">{{ log.new_value ?? '∅' }}</span></span>
                            <span v-else>—</span>
                        </td>
                    </tr>
                    <tr v-if="!logs.data.length"><td colspan="7" class="px-4 py-8 text-center text-slate-400">Записей нет</td></tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="logs.links" /></div>
        </div>
    </AppLayout>
</template>
