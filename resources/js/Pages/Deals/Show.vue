<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TaskPanel from '@/Components/TaskPanel.vue';
import FinancePanel from '@/Components/FinancePanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import CustomFieldsPanel from '@/Components/CustomFieldsPanel.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';
import { formatDate } from '@/utils/format';

const props = defineProps({ deal: Object, stages: Array, users: Array, finance: Object, customFields: Array, history: Array, can: Object });

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const tab = ref('info');
const visibleFields = computed(() => (props.customFields ?? []).filter((f) => f.is_visible && f.value));
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLastStage = computed(() => props.deal.deal_stage_id === lastStage.value?.id);
const overdue = computed(() => isPastDue(props.deal.deadline, props.deal.status === 'closed'));

const moveStage = (id) => router.patch(route('deals.stage', props.deal.id), { deal_stage_id: id }, { preserveScroll: true });
const advance = () => router.patch(route('deals.advance', props.deal.id), {}, { preserveScroll: true });
const sendToWorkshop = () => router.post(route('deals.toWorkshop', props.deal.id), {}, { preserveScroll: true });
const setResponsible = (e) => router.patch(route('deals.responsible', props.deal.id), { responsible_user_id: e.target.value || null }, { preserveScroll: true });
const destroy = () => { if (confirm('Удалить сделку?')) router.delete(route('deals.destroy', props.deal.id), { onSuccess: () => router.get(route('deals.index')) }); };

// Edit modal
const showEdit = ref(false);
const editForm = useForm({
    name: props.deal.name, client_name: props.deal.client_name ?? '',
    budget: props.deal.budget, deadline: props.deal.deadline ?? '', description: props.deal.description ?? '',
});
const openEdit = () => {
    Object.assign(editForm, { name: props.deal.name, client_name: props.deal.client_name ?? '', budget: props.deal.budget, deadline: props.deal.deadline ?? '', description: props.deal.description ?? '' });
    showEdit.value = true;
};
const saveEdit = () => editForm.put(route('deals.update', props.deal.id), { preserveScroll: true, onSuccess: () => (showEdit.value = false) });
</script>

<template>
    <Head :title="deal.number" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('deals.index')" class="text-gray-400 hover:text-gray-600">← Сделки</Link>
                <span>{{ deal.name }}</span>
                <span class="text-sm text-gray-400">{{ deal.number }}</span>
                <span v-if="overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">⚠ Просрочено</span>
            </div>
        </template>

        <div class="mb-6 rounded-lg bg-white p-3 shadow">
            <div class="flex items-center gap-1 overflow-x-auto">
                <button v-for="stage in stages" :key="stage.id" @click="moveStage(stage.id)" :disabled="!can.update"
                    :class="stage.id === deal.deal_stage_id ? 'text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                    :style="stage.id === deal.deal_stage_id ? { backgroundColor: stage.color } : {}"
                    class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition disabled:cursor-not-allowed">
                    {{ stage.name }}
                </button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 border-t pt-3">
                <PrimaryButton v-if="can.update && !isLastStage" @click="advance">Далее →</PrimaryButton>
                <button v-if="can.update && isLastStage && !deal.project" @click="sendToWorkshop"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">📦 Отправить в цех</button>
                <Link v-if="deal.project" :href="route('projects.show', deal.project.id)"
                    class="rounded-md bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">В цеху: {{ deal.project.number }} →</Link>
                <button v-if="can.update" @click="openEdit" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">✎ Изменить</button>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-6">
            <div class="col-span-2 space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="mb-4 flex flex-wrap gap-4 border-b text-sm">
                        <button :class="tab==='info' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='info'">Информация</button>
                        <button :class="tab==='tasks' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='tasks'">Задачи ({{ deal.tasks.length }})</button>
                        <button :class="tab==='finance' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='finance'">Финансы</button>
                        <button :class="tab==='docs' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='docs'">Документы ({{ deal.documents.length }})</button>
                        <button :class="tab==='comments' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='comments'">Комментарии ({{ deal.comments.length }})</button>
                        <button :class="tab==='custom' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='custom'">Доп. поля</button>
                        <button :class="tab==='history' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500'" class="pb-2" @click="tab='history'">История</button>
                    </div>

                    <div v-if="tab==='info'" class="space-y-3 text-sm">
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Клиент</span><span class="font-medium">{{ deal.client_name || deal.client?.name || '—' }}</span></div>
                        <div class="flex items-center justify-between border-b py-2">
                            <span class="text-gray-500">Ответственный</span>
                            <select :value="deal.responsible_user_id ?? ''" @change="setResponsible" class="rounded-md border-gray-300 py-1 text-sm shadow-sm">
                                <option value="">— не назначен —</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div class="flex justify-between border-b py-2"><span class="text-gray-500">Отдел</span><span class="font-medium">{{ deal.department?.name ?? '—' }}</span></div>
                        <div class="flex justify-between border-b py-2">
                            <span class="text-gray-500">Срок</span>
                            <span class="font-medium" :class="deadlineClass(deal.deadline, deal.status==='closed')">
                                {{ formatDate(deal.deadline) }}<span v-if="overdue"> · просрочено</span>
                            </span>
                        </div>
                        <div v-for="f in visibleFields" :key="f.id" class="flex justify-between border-b py-2"><span class="text-gray-500">{{ f.name }}</span><span class="font-medium">{{ f.value }}</span></div>
                        <div class="py-2"><div class="mb-1 text-gray-500">Описание</div><p class="whitespace-pre-line text-gray-700">{{ deal.description ?? '—' }}</p></div>
                    </div>

                    <TaskPanel v-else-if="tab==='tasks'" :tasks="deal.tasks" taskable-type="deal" :taskable-id="deal.id" :users="users" />
                    <FinancePanel v-else-if="tab==='finance'" :entity-type="'deal'" :entity-id="deal.id" :client-id="deal.client_id" :invoices="deal.invoices" :expenses="deal.expenses" :finance="finance" />
                    <DocumentPanel v-else-if="tab==='docs'" :documents="deal.documents" entity-type="deal" :entity-id="deal.id" />
                    <CommentPanel v-else-if="tab==='comments'" :comments="deal.comments" entity-type="deal" :entity-id="deal.id" />
                    <CustomFieldsPanel v-else-if="tab==='custom'" :fields="customFields" entity-type="deal" :entity-id="deal.id" />
                    <HistoryPanel v-else :history="history" />
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="text-xs uppercase text-gray-400">Бюджет (сумма сделки)</div>
                    <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(deal.budget) }}</div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Статус</span><StatusBadge :status="deal.status" /></div>
                        <div class="flex justify-between"><span class="text-gray-500">Доход</span><span class="font-medium text-green-600">{{ money(finance.income) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Расходы</span><span class="font-medium text-red-600">{{ money(finance.expense) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Прибыль</span><span class="font-medium">{{ money(finance.profit) }}</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Маржа</span><span class="font-medium">{{ finance.margin }}%</span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Выгода (план)</span><span class="font-medium" :class="finance.plannedProfit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(finance.plannedProfit) }}</span></div>
                    </div>
                </div>
                <DangerButton v-if="can.delete" @click="destroy">Удалить сделку</DangerButton>
            </div>
        </div>

        <!-- Edit modal -->
        <Modal :show="showEdit" @close="showEdit = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Изменить сделку</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2"><InputLabel value="Название" /><TextInput v-model="editForm.name" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Клиент" /><TextInput v-model="editForm.client_name" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Общая сумма" /><TextInput v-model="editForm.budget" type="number" step="0.01" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Срок" /><TextInput v-model="editForm.deadline" type="date" class="mt-1 w-full" /></div>
                    <div class="col-span-2"><InputLabel value="Описание" /><textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showEdit = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
