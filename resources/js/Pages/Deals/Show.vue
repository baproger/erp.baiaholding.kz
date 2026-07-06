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
import InputError from '@/Components/InputError.vue';
import TaskPanel from '@/Components/TaskPanel.vue';
import FinancePanel from '@/Components/FinancePanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import CustomFieldsPanel from '@/Components/CustomFieldsPanel.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import DealChat from '@/Components/DealChat.vue';
import { deadlineClass, isPastDue } from '@/utils/deadline';
import { formatDate, money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ deal: Object, stages: Array, users: Array, finance: Object, profit: Object, customFields: Array, history: Array, chatId: Number, can: Object });

const tab = ref('finance');
const visibleFields = computed(() => (props.customFields ?? []).filter((f) => f.is_visible && f.value));
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLastStage = computed(() => props.deal.deal_stage_id === lastStage.value?.id);
// "Отправить в цех" is triggered on the 3rd-from-last stage (Закуп ЛДСП,МДФ).
const workshopStage = computed(() => props.stages[props.stages.length - 3]);
const isWorkshopStage = computed(() => props.deal.deal_stage_id === workshopStage.value?.id);
const returnStage = computed(() => props.stages[props.stages.length - 2]);
// «Акт утверждение» reachable only via Цех; «Оплата» only from «Акт утверждение».
const stageLocked = (stage) => {
    if (!stage) return false;
    if (stage.id === returnStage.value?.id) return true;
    if (stage.id === lastStage.value?.id && props.deal.deal_stage_id !== returnStage.value?.id) return true;
    return false;
};
const overdue = computed(() => isPastDue(props.deal.deadline, props.deal.status === 'closed'));
// Remaining amount to be paid = deal sum − paid income. Must be 0 to reach «Оплата успешно».
const remainder = computed(() => Math.max(0, Number(props.deal.budget || 0) - Number(props.finance?.income || 0)));

const wonStageId = computed(() => props.stages.find((s) => s.is_won)?.id);
const moveStage = async (id) => {
    if (id === props.deal.deal_stage_id) return;
    // «Оплата успешно» is the final successful stage — confirm before leaving it.
    if (props.deal.deal_stage_id === wonStageId.value
        && ! (await confirmDialog({ title: 'Сделка уже успешна', message: 'Сделка на этапе «Оплата успешно». Точно перевести её на другой этап?', confirmText: 'Перевести', danger: true }))) return;
    router.patch(route('deals.stage', props.deal.id), { deal_stage_id: id }, { preserveScroll: true });
};
const advance = () => router.patch(route('deals.advance', props.deal.id), {}, { preserveScroll: true });
const sendToWorkshop = () => router.post(route('deals.toWorkshop', props.deal.id), {}, { preserveScroll: true });
const setResponsible = (e) => router.patch(route('deals.responsible', props.deal.id), { responsible_user_id: e.target.value || null }, { preserveScroll: true });
const destroy = async () => {
    if (await confirmDialog({ title: 'Удалить сделку', message: 'Сделка будет удалена. Это действие необратимо.', confirmText: 'Удалить', danger: true })) {
        router.delete(route('deals.destroy', props.deal.id), { onSuccess: () => router.get(route('deals.index')) });
    }
};

const showEdit = ref(false);
const editForm = useForm({
    name: props.deal.name, company_name: props.deal.company_name ?? '', bin: props.deal.bin ?? '', client_name: props.deal.client_name ?? '',
    lot_number: props.deal.lot_number ?? '', budget: props.deal.budget, deadline: props.deal.deadline ?? '',
    description: props.deal.description ?? '', note: props.deal.note ?? '',
});
const openEdit = () => {
    Object.assign(editForm, {
        name: props.deal.name, company_name: props.deal.company_name ?? '', bin: props.deal.bin ?? '', client_name: props.deal.client_name ?? '',
        lot_number: props.deal.lot_number ?? '', budget: props.deal.budget, deadline: props.deal.deadline ?? '',
        description: props.deal.description ?? '', note: props.deal.note ?? '',
    });
    showEdit.value = true;
};
const saveEdit = () => editForm.put(route('deals.update', props.deal.id), { preserveScroll: true, onSuccess: () => (showEdit.value = false) });
</script>

<template>
    <Head :title="deal.number" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('deals.index')" class="text-slate-400 hover:text-slate-600">← {{ $t('page.deals', 'Сделки') }}</Link>
                <span>{{ deal.name }}</span>
                <span class="text-sm text-slate-400">{{ deal.number }}</span>
                <span v-if="overdue" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">⚠ {{ $t('deal.overdue_badge', 'Просрочено') }}</span>
            </div>
        </template>

        <div class="mb-6 rounded-2xl bg-white p-4 border border-slate-200 shadow-sm">
            <div class="flex items-center gap-1 overflow-x-auto">
                <button v-for="stage in stages" :key="stage.id" @click="moveStage(stage.id)" :disabled="!can.update || stageLocked(stage)"
                    :title="stageLocked(stage) ? 'Доступно только через цех (кнопка «АКТ»)' : ''"
                    :class="stage.id === deal.deal_stage_id ? 'text-white shadow' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                    :style="stage.id === deal.deal_stage_id ? { backgroundColor: stage.color } : {}"
                    class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-all disabled:cursor-not-allowed disabled:opacity-40">
                    {{ stage.name }}
                </button>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 border-t pt-3">
                <PrimaryButton v-if="can.update && !isWorkshopStage && !isLastStage" @click="advance">Далее →</PrimaryButton>
                <button v-if="can.update && isWorkshopStage && (!deal.project || deal.project.status === 'completed')" @click="sendToWorkshop" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition-transform hover:scale-[1.02] hover:bg-emerald-700">📦 Отправить в цех</button>
                <Link v-if="deal.project && deal.project.status !== 'completed'" :href="route('projects.show', deal.project.id)" class="rounded-xl bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">В цеху: {{ deal.project.number }} →</Link>
                <button v-if="can.update" @click="openEdit" class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-200">✎ Изменить</button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2 space-y-6">
                <!-- Информация — компактная сетка -->
                <div class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Компания</div>
                            <div class="mt-1 font-semibold text-slate-900">{{ deal.company_name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">БИН</div>
                            <div class="mt-1 font-medium text-slate-700">{{ deal.bin || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Клиент</div>
                            <div class="mt-1 font-medium text-slate-700">{{ deal.client_name || deal.client?.name || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Номер лота</div>
                            <div class="mt-1 font-medium text-slate-700">{{ deal.lot_number || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Срок</div>
                            <div class="mt-1 font-medium" :class="deadlineClass(deal.deadline, deal.status==='closed')">{{ formatDate(deal.deadline) }}<span v-if="overdue"> · просрочено</span></div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Ответственный</div>
                            <select :value="deal.responsible_user_id ?? ''" @change="setResponsible" class="mt-1 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                                <option value="">— не назначен —</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <div v-for="f in visibleFields" :key="f.id">
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ f.name }}</div>
                            <div class="mt-1 font-medium text-slate-700">{{ f.value }}</div>
                        </div>
                    </div>
                    <div v-if="deal.note" class="mt-5 rounded-xl bg-amber-50 px-4 py-3">
                        <div class="mb-1 text-[11px] font-semibold uppercase text-amber-700">📌 Заметка</div>
                        <p class="whitespace-pre-line text-sm text-slate-700">{{ deal.note }}</p>
                    </div>
                    <div v-if="deal.description" class="mt-4">
                        <div class="text-[11px] uppercase tracking-wide text-slate-400">Описание</div>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ deal.description }}</p>
                    </div>
                </div>

                <!-- Задачи -->
                <div class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">✓ Задачи <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ deal.tasks.length }}</span></h3>
                    <TaskPanel :tasks="deal.tasks" taskable-type="deal" :taskable-id="deal.id" :users="users" />
                </div>

                <!-- Документы (только если прикреплены) -->
                <div v-if="deal.documents.length" class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">📎 Документы <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ deal.documents.length }}</span></h3>
                    <DocumentPanel :documents="deal.documents" entity-type="deal" :entity-id="deal.id" />
                </div>

                <!-- Комментарии -->
                <div class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">💬 Комментарии <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ deal.comments.length }}</span></h3>
                    <CommentPanel :comments="deal.comments" entity-type="deal" :entity-id="deal.id" />
                </div>

                <!-- Второстепенное: Финансы / Документы (управление) / Доп. поля / История -->
                <div class="rounded-2xl bg-white p-6 border border-slate-200 shadow-sm">
                    <div class="mb-4 flex flex-wrap gap-4 border-b text-sm">
                        <button :class="tab==='finance' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='finance'">Финансы</button>
                        <button :class="tab==='docs' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='docs'">Документы</button>
                        <button :class="tab==='custom' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='custom'">Доп. поля</button>
                        <button :class="tab==='history' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500'" class="pb-2 transition-colors" @click="tab='history'">История</button>
                    </div>
                    <FinancePanel v-if="tab==='finance'" :entity-type="'deal'" :entity-id="deal.id" :client-id="deal.client_id" :invoices="deal.invoices" :expenses="deal.expenses" :finance="finance" />
                    <DocumentPanel v-else-if="tab==='docs'" :documents="deal.documents" entity-type="deal" :entity-id="deal.id" />
                    <CustomFieldsPanel v-else-if="tab==='custom'" :fields="customFields" entity-type="deal" :entity-id="deal.id" />
                    <HistoryPanel v-else :history="history" />
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-xs uppercase text-slate-400">Сумма сделки</div>
                    <div class="mt-1 text-2xl font-bold text-indigo-600">{{ money(deal.budget) }}</div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Статус</span><StatusBadge :status="deal.status" /></div>
                        <div class="flex justify-between"><span class="text-slate-500">Доход (оплачено)</span><span class="font-medium text-green-600">{{ money(finance.income) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Остаток к оплате</span><span class="font-bold" :class="remainder > 0 ? 'text-red-600' : 'text-green-600'">{{ remainder > 0 ? money(remainder) : 'оплачено ✓' }}</span></div>
                        <div class="mt-2 border-t pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Расчёт прибыли</div>
                        <div class="flex justify-between"><span class="text-slate-500">Налог {{ profit.taxRate }}%</span><span class="font-medium tabular-nums text-rose-600">− {{ money(profit.tax) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Прочие расходы</span><span class="font-medium tabular-nums text-rose-600">− {{ money(profit.expense) }}</span></div>
                        <div class="flex justify-between border-t pt-2"><span class="text-slate-500">Остаток</span><span class="font-semibold tabular-nums text-slate-800">{{ money(profit.remainder) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">ЗП сотрудника {{ profit.bonusRate }}%</span><span class="font-medium tabular-nums text-emerald-600">− {{ money(profit.bonus) }}</span></div>
                        <div class="flex justify-between rounded-lg px-2 py-1.5 text-white" style="background-color: #1A3B5C"><span class="font-semibold">Чистая прибыль компании</span><span class="font-bold tabular-nums">{{ money(profit.company) }}</span></div>
                    </div>
                </div>

                <!-- Чат сделки — сразу на виду -->
                <div class="rounded-2xl bg-white p-4 border border-slate-200 shadow-sm">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900">💬 Чат сделки</h3>
                    <DealChat :chat-id="chatId" />
                </div>

                <DangerButton v-if="can.delete" @click="destroy">Удалить сделку</DangerButton>
            </div>
        </div>

        <Modal :show="showEdit" @close="showEdit = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">Изменить сделку</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2"><InputLabel value="Название сделки" /><TextInput v-model="editForm.name" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Название компании *" /><TextInput v-model="editForm.company_name" class="mt-1 w-full" /><InputError :message="editForm.errors.company_name" class="mt-1" /></div>
                    <div><InputLabel value="БИН" /><TextInput v-model="editForm.bin" class="mt-1 w-full" /><InputError :message="editForm.errors.bin" class="mt-1" /></div>
                    <div><InputLabel value="Имя клиента *" /><TextInput v-model="editForm.client_name" class="mt-1 w-full" /><InputError :message="editForm.errors.client_name" class="mt-1" /></div>
                    <div><InputLabel value="Номер лота" /><TextInput v-model="editForm.lot_number" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Сумма *" /><TextInput v-model="editForm.budget" type="number" step="0.01" class="mt-1 w-full" /><InputError :message="editForm.errors.budget" class="mt-1" /></div>
                    <div><InputLabel value="Срок" /><TextInput v-model="editForm.deadline" type="date" class="mt-1 w-full" /></div>
                    <div class="col-span-2"><InputLabel value="Описание" /><textarea v-model="editForm.description" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm"></textarea></div>
                    <div class="col-span-2"><InputLabel value="Заметка (кратко)" /><textarea v-model="editForm.note" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showEdit = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
