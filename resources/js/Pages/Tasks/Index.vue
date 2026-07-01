<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';

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
const label = (t) => t.taskable_type === 'deal' ? 'Сделка' : t.taskable_type === 'project' ? 'Цех' : 'Личная';
const fmt = (v) => v ? new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';

// Create task
const show = ref(false);
const form = useForm({ title: '', description: '', assignee_id: '', priority: 'medium', status: 'new', due_date: '' });
const openCreate = () => { form.reset(); show.value = true; };
const submit = () => form.post(route('tasks.store'), { preserveScroll: true, onSuccess: () => (show.value = false) });
</script>

<template>
    <Head title="Задачи" />
    <AppLayout>
        <template #header>Задачи</template>

        <div class="mb-4 flex justify-end">
            <PrimaryButton @click="openCreate">+ Новая задача</PrimaryButton>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div v-for="col in columns" :key="col.key" class="rounded-lg bg-gray-200/60 p-2" @dragover.prevent @drop="onDrop(col)">
                <div class="mb-2 flex items-center gap-2 px-2 py-1">
                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: col.color }"></span>
                    <span class="text-sm font-semibold text-gray-700">{{ col.label }}</span>
                    <span class="text-xs text-gray-400">{{ byStatus(col.key).length }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="t in byStatus(col.key)" :key="t.id" draggable="true" @dragstart="dragId = t.id"
                        class="cursor-move rounded-md bg-white p-3 shadow-sm ring-1 ring-gray-100"
                        :class="isPastDue(t.due_date, t.status==='done') ? 'ring-2 ring-red-400' : ''">
                        <div class="font-medium text-gray-800">{{ t.title }}</div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-400">
                            <span>{{ label(t) }}</span>
                            <span v-if="t.due_date" :class="deadlineClass(t.due_date, t.status==='done')">{{ isPastDue(t.due_date, t.status==='done') ? '⚠ Просрочено ' : '⏰ ' }}{{ fmt(t.due_date) }}</span>
                        </div>
                    </div>
                    <div v-if="!byStatus(col.key).length" class="py-4 text-center text-xs text-gray-400">Пусто</div>
                </div>
            </div>
        </div>

        <Modal :show="show" @close="show = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Новая задача</h2>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Название" />
                        <TextInput v-model="form.title" class="mt-1 w-full" />
                        <InputError :message="form.errors.title" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Исполнитель" />
                            <select v-model="form.assignee_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option value="">— я —</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Приоритет" />
                            <select v-model="form.priority" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option value="low">Низкий</option><option value="medium">Средний</option><option value="high">Высокий</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Срок (дата и время)" />
                        <TextInput v-model="form.due_date" type="datetime-local" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Создать</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
