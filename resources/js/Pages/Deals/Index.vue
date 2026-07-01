<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import { deadlineClass } from '@/utils/deadline';

const props = defineProps({
    deals: [Array, Object],
    stages: Array,
    view: String,
    filters: Object,
    users: Array,
    clients: Array,
    departments: Array,
    can: Object,
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';

// Kanban grouping
const list = computed(() => Array.isArray(props.deals) ? props.deals : props.deals.data);
const byStage = (stageId) => list.value.filter((d) => d.deal_stage_id === stageId);
const stageTotal = (stageId) => byStage(stageId).reduce((s, d) => s + Number(d.budget), 0);

// Drag & drop
const draggingId = ref(null);
const onDragStart = (deal) => (draggingId.value = deal.id);
const onDrop = (stage) => {
    const id = draggingId.value;
    draggingId.value = null;
    if (!id) return;
    const deal = list.value.find((d) => d.id === id);
    if (!deal || deal.deal_stage_id === stage.id) return;
    router.patch(route('deals.stage', id), { deal_stage_id: stage.id }, {
        preserveScroll: true,
        preserveState: false,
    });
};

const switchView = (v) => router.get(route('deals.index'), { ...props.filters, view: v }, { preserveState: true });
const advance = (deal) => router.patch(route('deals.advance', deal.id), {}, { preserveScroll: true, preserveState: false });

// Create modal
const showModal = ref(false);
const form = useForm({
    name: '', client_id: '', responsible_user_id: '', department_id: '',
    budget: 0, deadline: '', description: '',
});
const openCreate = () => { form.reset(); showModal.value = true; };
const submit = () => form.post(route('deals.store'), {
    preserveScroll: true,
    onSuccess: () => (showModal.value = false),
});
</script>

<template>
    <Head title="Сделки" />
    <AppLayout>
        <template #header>Сделки</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="inline-flex rounded-md bg-white shadow-sm ring-1 ring-gray-200">
                <button
                    :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-gray-600'"
                    class="rounded-l-md px-4 py-1.5 text-sm" @click="switchView('kanban')">Канбан</button>
                <button
                    :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-gray-600'"
                    class="rounded-r-md px-4 py-1.5 text-sm" @click="switchView('list')">Список</button>
            </div>
            <PrimaryButton v-if="can.create" @click="openCreate">+ Новая сделка</PrimaryButton>
        </div>

        <!-- KANBAN -->
        <div v-if="view === 'kanban'" class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="stage in stages"
                :key="stage.id"
                class="flex w-72 flex-shrink-0 flex-col rounded-lg bg-gray-200/60"
                @dragover.prevent
                @drop="onDrop(stage)"
            >
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-sm font-semibold text-gray-700">{{ stage.name }}</span>
                        <span class="text-xs text-gray-400">{{ byStage(stage.id).length }}</span>
                    </div>
                    <span class="text-xs font-medium text-gray-500">{{ money(stageTotal(stage.id)) }}</span>
                </div>
                <div class="flex-1 space-y-2 px-2 pb-2">
                    <Link
                        v-for="deal in byStage(stage.id)"
                        :key="deal.id"
                        :href="route('deals.show', deal.id)"
                        draggable="true"
                        @dragstart="onDragStart(deal)"
                        class="block cursor-move rounded-md bg-white p-3 shadow-sm ring-1 ring-gray-100 hover:ring-indigo-300"
                    >
                        <div class="text-xs text-gray-400">{{ deal.number }}</div>
                        <div class="font-medium text-gray-800">{{ deal.name }}</div>
                        <div class="mt-1 text-sm font-semibold text-indigo-600">{{ money(deal.budget) }}</div>
                        <div class="mt-1 flex items-center justify-between text-xs text-gray-400">
                            <span>{{ deal.client?.name ?? '—' }}</span>
                            <span>{{ deal.responsible?.name ?? '' }}</span>
                        </div>
                        <div v-if="deal.deadline" class="mt-1 text-xs" :class="deadlineClass(deal.deadline, deal.status==='closed')">⏰ {{ deal.deadline }}</div>
                        <button @click.prevent.stop="advance(deal)" class="mt-2 w-full rounded bg-gray-100 py-1 text-xs text-gray-600 hover:bg-indigo-100 hover:text-indigo-700">Далее →</button>
                    </Link>
                    <div v-if="!byStage(stage.id).length" class="py-6 text-center text-xs text-gray-400">Пусто</div>
                </div>
            </div>
        </div>

        <!-- LIST -->
        <div v-else class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Номер</th>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Этап</th>
                        <th class="px-4 py-3">Бюджет</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3">Ответственный</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="deal in deals.data" :key="deal.id" class="cursor-pointer hover:bg-gray-50" @click="router.get(route('deals.show', deal.id))">
                        <td class="px-4 py-3 text-gray-400">{{ deal.number }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ deal.name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ deal.client?.name ?? '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" /></td>
                        <td class="px-4 py-3">{{ money(deal.budget) }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="deal.status" /></td>
                        <td class="px-4 py-3 text-gray-500">{{ deal.responsible?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="deals.links" /></div>
        </div>

        <!-- CREATE MODAL -->
        <Modal :show="showModal" @close="showModal = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Новая сделка</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel value="Название" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Клиент" />
                        <select v-model="form.client_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Ответственный" />
                        <select v-model="form.responsible_user_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Отдел" />
                        <select v-model="form.department_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Бюджет" />
                        <TextInput v-model="form.budget" type="number" step="0.01" class="mt-1 w-full" />
                        <InputError :message="form.errors.budget" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Срок" />
                        <TextInput v-model="form.deadline" type="date" class="mt-1 w-full" />
                    </div>
                    <div class="col-span-2">
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Создать</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
