<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';
import { formatDateTime } from '@/utils/format';

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
const sourceLabel = (t) => t.taskable_type === 'deal' ? 'Сделка' : t.taskable_type === 'project' ? 'Цех' : 'Личная';
const sourceLink = (t) => t.taskable_type === 'deal' ? route('deals.show', t.taskable_id) : t.taskable_type === 'project' ? route('projects.show', t.taskable_id) : null;
const forInput = (v) => v ? new Date(v).toISOString().slice(0, 16) : '';

const show = ref(false);
const editing = ref(null);
const form = useForm({ title: '', description: '', note: '', assignee_id: '', priority: 'medium', status: 'new', due_date: '' });

const openCreate = () => { editing.value = null; form.reset(); show.value = true; };
const openEdit = (t) => {
    editing.value = t;
    Object.assign(form, { title: t.title, description: t.description ?? '', note: t.note ?? '', assignee_id: t.assignee_id ?? '', priority: t.priority, status: t.status, due_date: forInput(t.due_date) });
    show.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    if (editing.value) form.put(route('tasks.update', editing.value.id), opts);
    else form.post(route('tasks.store'), opts);
};
const destroy = () => { if (editing.value && confirm('Удалить задачу?')) router.delete(route('tasks.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => (show.value = false) }); };
</script>

<template>
    <Head title="Задачи" />
    <AppLayout>
        <template #header>Задачи</template>

        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-gray-500">Задачи из сделок и цеха — все здесь. Нажмите для редактирования.</p>
            <PrimaryButton @click="openCreate">+ Новая задача</PrimaryButton>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div v-for="col in columns" :key="col.key" class="rounded-xl bg-gray-100/80 p-2" @dragover.prevent @drop="onDrop(col)">
                <div class="mb-2 flex items-center gap-2 px-2 py-1">
                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: col.color }"></span>
                    <span class="text-sm font-semibold text-gray-700">{{ col.label }}</span>
                    <span class="text-xs text-gray-400">{{ byStatus(col.key).length }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="t in byStatus(col.key)" :key="t.id" draggable="true" @dragstart="dragId = t.id"
                        class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-100 transition-all hover:-translate-y-0.5 hover:shadow-md"
                        :class="isPastDue(t.due_date, t.status==='done') ? 'ring-2 ring-red-400' : ''">
                        <div class="flex cursor-pointer items-start justify-between" @click="openEdit(t)">
                            <div class="font-medium text-gray-800">{{ t.title }}</div>
                            <span v-if="isPastDue(t.due_date, t.status==='done')" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-600">ПРОСРОЧЕНА</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between text-xs">
                            <Link v-if="sourceLink(t)" :href="sourceLink(t)" class="text-indigo-500 hover:underline" @click.stop>{{ sourceLabel(t) }} {{ t.taskable?.number }}</Link>
                            <span v-else class="text-gray-400">{{ sourceLabel(t) }}</span>
                            <span class="flex items-center gap-1 text-gray-500">
                                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-indigo-500 text-[9px] font-bold text-white">{{ t.assignee?.name?.charAt(0) ?? '—' }}</span>
                                {{ t.assignee?.name ?? 'нет' }}
                            </span>
                        </div>
                        <div v-if="t.due_date" class="mt-1 text-[11px]" :class="deadlineClass(t.due_date, t.status==='done')">{{ isPastDue(t.due_date, t.status==='done') ? '⚠ ' : '⏰ ' }}{{ formatDateTime(t.due_date) }}</div>
                    </div>
                    <div v-if="!byStatus(col.key).length" class="py-4 text-center text-xs text-gray-400">Пусто</div>
                </div>
            </div>
        </div>

        <Modal :show="show" @close="show = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Редактировать задачу' : 'Новая задача' }}</h2>
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Название" />
                        <TextInput v-model="form.title" class="mt-1 w-full" />
                        <InputError :message="form.errors.title" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <InputLabel value="Ответственный" />
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
                        <div>
                            <InputLabel value="Статус" />
                            <select v-model="form.status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option value="new">Новая</option><option value="in_progress">В работе</option>
                                <option value="review">Проверка</option><option value="done">Готово</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Срок (дата и время)" />
                        <TextInput v-model="form.due_date" type="datetime-local" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Заметка (кратко)" />
                        <textarea v-model="form.note" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="Коротко по задаче"></textarea>
                    </div>
                    <div>
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-between">
                    <DangerButton v-if="editing" @click="destroy">Удалить</DangerButton>
                    <div class="ml-auto flex gap-2">
                        <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                        <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
