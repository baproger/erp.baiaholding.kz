<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ projects: [Array, Object], stages: Array, view: String, filters: Object });

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const list = computed(() => Array.isArray(props.projects) ? props.projects : props.projects.data);
const byStage = (id) => list.value.filter((p) => p.project_stage_id === id);

const draggingId = ref(null);
const onDrop = (stage) => {
    const id = draggingId.value; draggingId.value = null;
    if (!id) return;
    const p = list.value.find((x) => x.id === id);
    if (!p || p.project_stage_id === stage.id) return;
    router.patch(route('projects.stage', id), { project_stage_id: stage.id }, { preserveScroll: true, preserveState: false });
};
const switchView = (v) => router.get(route('projects.index'), { ...props.filters, view: v }, { preserveState: true });
</script>

<template>
    <Head title="Проекты" />
    <AppLayout>
        <template #header>Проекты</template>

        <div class="mb-4 inline-flex rounded-md bg-white shadow-sm ring-1 ring-gray-200">
            <button :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-gray-600'" class="rounded-l-md px-4 py-1.5 text-sm" @click="switchView('kanban')">Канбан</button>
            <button :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-gray-600'" class="rounded-r-md px-4 py-1.5 text-sm" @click="switchView('list')">Список</button>
        </div>

        <div v-if="view === 'kanban'" class="flex gap-4 overflow-x-auto pb-4">
            <div v-for="stage in stages" :key="stage.id" class="flex w-72 flex-shrink-0 flex-col rounded-lg bg-gray-200/60" @dragover.prevent @drop="onDrop(stage)">
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-sm font-semibold text-gray-700">{{ stage.name }}</span>
                        <span class="text-xs text-gray-400">{{ byStage(stage.id).length }}</span>
                    </div>
                </div>
                <div class="flex-1 space-y-2 px-2 pb-2">
                    <Link v-for="p in byStage(stage.id)" :key="p.id" :href="route('projects.show', p.id)" draggable="true" @dragstart="draggingId = p.id"
                        class="block cursor-move rounded-md bg-white p-3 shadow-sm ring-1 ring-gray-100 hover:ring-indigo-300">
                        <div class="text-xs text-gray-400">{{ p.number }}</div>
                        <div class="font-medium text-gray-800">{{ p.name }}</div>
                        <div class="mt-1 text-sm font-semibold text-indigo-600">{{ money(p.budget) }}</div>
                        <div class="mt-1 text-xs text-gray-400">{{ p.client?.name ?? '—' }}</div>
                    </Link>
                    <div v-if="!byStage(stage.id).length" class="py-6 text-center text-xs text-gray-400">Пусто</div>
                </div>
            </div>
        </div>

        <div v-else class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Номер</th><th class="px-4 py-3">Название</th><th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Этап</th><th class="px-4 py-3">Бюджет</th><th class="px-4 py-3">Статус</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="p in projects.data" :key="p.id" class="cursor-pointer hover:bg-gray-50" @click="router.get(route('projects.show', p.id))">
                        <td class="px-4 py-3 text-gray-400">{{ p.number }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ p.name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ p.client?.name ?? '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="p.stage?.name" :color="p.stage?.color" /></td>
                        <td class="px-4 py-3">{{ money(p.budget) }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="p.status" /></td>
                    </tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="projects.links" /></div>
        </div>
    </AppLayout>
</template>
