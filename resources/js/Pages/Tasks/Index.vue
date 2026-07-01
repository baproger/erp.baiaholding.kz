<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ tasks: Array, users: Array });

const columns = [
    { key: 'new', label: 'Новые', color: '#6B7280' },
    { key: 'in_progress', label: 'В работе', color: '#F59E0B' },
    { key: 'review', label: 'На проверке', color: '#8B5CF6' },
    { key: 'done', label: 'Готово', color: '#10B981' },
];
const byStatus = (s) => props.tasks.filter((t) => t.status === s);

let dragId = null;
const onDrop = (col) => {
    const id = dragId; dragId = null;
    if (!id) return;
    const t = props.tasks.find((x) => x.id === id);
    if (!t || t.status === col.key) return;
    router.patch(route('tasks.status', id), { status: col.key }, { preserveScroll: true, preserveState: false });
};
const label = (t) => t.taskable_type === 'deal' ? 'Сделка' : t.taskable_type === 'project' ? 'Проект' : 'Личная';
</script>

<template>
    <Head title="Мои задачи" />
    <AppLayout>
        <template #header>Мои задачи</template>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div v-for="col in columns" :key="col.key" class="rounded-lg bg-gray-200/60 p-2" @dragover.prevent @drop="onDrop(col)">
                <div class="mb-2 flex items-center gap-2 px-2 py-1">
                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: col.color }"></span>
                    <span class="text-sm font-semibold text-gray-700">{{ col.label }}</span>
                    <span class="text-xs text-gray-400">{{ byStatus(col.key).length }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="t in byStatus(col.key)" :key="t.id" draggable="true" @dragstart="dragId = t.id"
                        class="cursor-move rounded-md bg-white p-3 shadow-sm ring-1 ring-gray-100">
                        <div class="font-medium text-gray-800">{{ t.title }}</div>
                        <div class="mt-1 text-xs text-gray-400">{{ label(t) }}<span v-if="t.due_date"> · {{ t.due_date }}</span></div>
                    </div>
                    <div v-if="!byStatus(col.key).length" class="py-4 text-center text-xs text-gray-400">Пусто</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
