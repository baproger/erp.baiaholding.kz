<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TaskPanel from '@/Components/TaskPanel.vue';

const props = defineProps({ project: Object, stages: Array, users: Array });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const moveStage = (id) => router.patch(route('projects.stage', props.project.id), { project_stage_id: id }, { preserveScroll: true });
</script>

<template>
    <Head :title="project.number" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('projects.index')" class="text-gray-400 hover:text-gray-600">← Проекты</Link>
                <span>{{ project.name }}</span>
                <span class="text-sm text-gray-400">{{ project.number }}</span>
            </div>
        </template>

        <div class="mb-6 flex items-center gap-1 overflow-x-auto rounded-lg bg-white p-3 shadow">
            <button v-for="stage in stages" :key="stage.id" @click="moveStage(stage.id)"
                :class="stage.id === project.project_stage_id ? 'text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                :style="stage.id === project.project_stage_id ? { backgroundColor: stage.color } : {}"
                class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition">
                {{ stage.name }}
            </button>
        </div>

        <div class="grid grid-cols-3 gap-6">
            <div class="col-span-2 space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <h3 class="mb-4 font-semibold text-gray-700">Информация</h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Клиент</span><span class="font-medium">{{ project.client?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Ответственный</span><span class="font-medium">{{ project.responsible?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Отдел</span><span class="font-medium">{{ project.department?.name ?? '—' }}</span></div>
                        <div v-if="project.deal" class="flex justify-between border-b py-2">
                            <span class="text-gray-500">Из сделки</span>
                            <Link :href="route('deals.show', project.deal.id)" class="text-indigo-600 hover:underline">{{ project.deal.number }}</Link>
                        </div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Срок</span><span class="font-medium">{{ project.deadline ?? '—' }}</span></div>
                        <div class="py-2"><div class="mb-1 text-gray-500">Описание</div><p class="whitespace-pre-line text-gray-700">{{ project.description ?? '—' }}</p></div>
                    </div>
                </div>
                <div class="rounded-lg bg-white p-6 shadow">
                    <h3 class="mb-4 font-semibold text-gray-700">Задачи ({{ project.tasks.length }})</h3>
                    <TaskPanel :tasks="project.tasks" taskable-type="project" :taskable-id="project.id" :users="users" />
                </div>
            </div>
            <div class="rounded-lg bg-white p-6 shadow self-start">
                <div class="text-xs uppercase text-gray-400">Бюджет</div>
                <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(project.budget) }}</div>
                <div class="mt-4 flex justify-between text-sm"><span class="text-gray-500">Статус</span><StatusBadge :status="project.status" /></div>
            </div>
        </div>
    </AppLayout>
</template>
