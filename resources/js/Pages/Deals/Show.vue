<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({ deal: Object, stages: Array, can: Object });

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const tab = ref('info');

const moveStage = (stageId) => {
    router.patch(route('deals.stage', props.deal.id), { deal_stage_id: stageId }, { preserveScroll: true });
};
const destroy = () => {
    if (confirm('Удалить сделку?')) router.delete(route('deals.destroy', props.deal.id), {
        onSuccess: () => router.get(route('deals.index')),
    });
};
</script>

<template>
    <Head :title="deal.number" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('deals.index')" class="text-gray-400 hover:text-gray-600">← Сделки</Link>
                <span>{{ deal.name }}</span>
                <span class="text-sm text-gray-400">{{ deal.number }}</span>
            </div>
        </template>

        <!-- Stage pipeline -->
        <div class="mb-6 flex items-center gap-1 overflow-x-auto rounded-lg bg-white p-3 shadow">
            <button
                v-for="stage in stages"
                :key="stage.id"
                @click="moveStage(stage.id)"
                :disabled="!can.update"
                :class="stage.id === deal.deal_stage_id
                    ? 'text-white'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                :style="stage.id === deal.deal_stage_id ? { backgroundColor: stage.color } : {}"
                class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition disabled:cursor-not-allowed"
            >
                {{ stage.name }}
            </button>
        </div>

        <div class="grid grid-cols-3 gap-6">
            <!-- Main -->
            <div class="col-span-2 space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="mb-4 flex gap-4 border-b text-sm">
                        <button :class="tab==='info' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='info'">Информация</button>
                        <button :class="tab==='tasks' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='tasks'">Задачи ({{ deal.tasks.length }})</button>
                    </div>

                    <div v-if="tab==='info'" class="space-y-3 text-sm">
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Клиент</span><span class="font-medium">{{ deal.client?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Ответственный</span><span class="font-medium">{{ deal.responsible?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Отдел</span><span class="font-medium">{{ deal.department?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Срок</span><span class="font-medium">{{ deal.deadline ?? '—' }}</span></div>
                        <div class="py-2"><div class="mb-1 text-gray-500">Описание</div><p class="whitespace-pre-line text-gray-700">{{ deal.description ?? '—' }}</p></div>
                    </div>

                    <div v-else class="space-y-2">
                        <div v-for="t in deal.tasks" :key="t.id" class="flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                            <div>
                                <div class="font-medium text-gray-800">{{ t.title }}</div>
                                <div class="text-xs text-gray-400">{{ t.assignee?.name ?? 'Без исполнителя' }}</div>
                            </div>
                            <StatusBadge :status="t.status" />
                        </div>
                        <div v-if="!deal.tasks.length" class="py-6 text-center text-sm text-gray-400">Задач пока нет</div>
                    </div>
                </div>
            </div>

            <!-- Aside -->
            <div class="space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="text-xs uppercase text-gray-400">Бюджет</div>
                    <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(deal.budget) }}</div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Статус</span><StatusBadge :status="deal.status" /></div>
                        <div v-if="deal.project" class="flex justify-between">
                            <span class="text-gray-500">Проект</span>
                            <Link :href="route('projects.show', deal.project.id)" class="text-indigo-600 hover:underline">{{ deal.project.number }}</Link>
                        </div>
                    </div>
                </div>

                <div v-if="deal.project" class="rounded-lg bg-green-50 p-4 text-sm text-green-800 ring-1 ring-green-200">
                    ✓ Сделка выиграна — автоматически создан проект <strong>{{ deal.project.number }}</strong>.
                </div>

                <DangerButton v-if="can.delete" @click="destroy">Удалить сделку</DangerButton>
            </div>
        </div>
    </AppLayout>
</template>
