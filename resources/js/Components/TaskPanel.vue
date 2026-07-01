<script setup>
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';

const props = defineProps({
    tasks: { type: Array, default: () => [] },
    taskableType: String, // 'deal' | 'project'
    taskableId: Number,
    users: { type: Array, default: () => [] },
});

const adding = ref(false);
const form = useForm({
    title: '', assignee_id: '', priority: 'medium', due_date: '',
    taskable_type: props.taskableType, taskable_id: props.taskableId,
});

const add = () => form.post(route('tasks.store'), {
    preserveScroll: true,
    onSuccess: () => { form.reset('title', 'assignee_id', 'due_date'); adding.value = false; },
});
const cycle = { new: 'in_progress', in_progress: 'review', review: 'done', done: 'new' };
const advance = (t) => router.patch(route('tasks.status', t.id), { status: cycle[t.status] }, { preserveScroll: true });
const remove = (t) => { if (confirm('Удалить задачу?')) router.delete(route('tasks.destroy', t.id), { preserveScroll: true }); };
</script>

<template>
    <div class="space-y-2">
        <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between rounded-md px-3 py-2 text-sm" :class="isPastDue(t.due_date, t.status==='done') ? 'bg-red-50 ring-1 ring-red-200' : 'bg-gray-50'">
            <div class="min-w-0">
                <div class="font-medium text-gray-800">{{ t.title }}</div>
                <div class="text-xs text-gray-400">
                    {{ t.assignee?.name ?? 'Без исполнителя' }}<span v-if="t.due_date" :class="deadlineClass(t.due_date, t.status==='done')"> · до {{ t.due_date }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button @click="advance(t)" title="Сменить статус"><StatusBadge :status="t.status" /></button>
                <button class="text-red-500 hover:text-red-700" @click="remove(t)">✕</button>
            </div>
        </div>
        <div v-if="!tasks.length" class="py-4 text-center text-sm text-gray-400">Задач пока нет</div>

        <div v-if="adding" class="rounded-md border border-dashed p-3">
            <TextInput v-model="form.title" placeholder="Название задачи" class="mb-2 w-full" />
            <div class="mb-2 grid grid-cols-2 gap-2">
                <select v-model="form.assignee_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">Исполнитель…</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <select v-model="form.priority" class="rounded-md border-gray-300 text-sm">
                    <option value="low">Низкий</option><option value="medium">Средний</option><option value="high">Высокий</option>
                </select>
            </div>
            <TextInput v-model="form.due_date" type="datetime-local" class="mb-2 w-full" />
            <div class="flex gap-2">
                <PrimaryButton :disabled="form.processing || !form.title" @click="add">Добавить</PrimaryButton>
                <button class="text-sm text-gray-500" @click="adding = false">Отмена</button>
            </div>
        </div>
        <button v-else class="text-sm text-indigo-600 hover:underline" @click="adding = true">+ Добавить задачу</button>
    </div>
</template>
