<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TaskPanel from '@/Components/TaskPanel.vue';
import FinancePanel from '@/Components/FinancePanel.vue';
import DocumentPanel from '@/Components/DocumentPanel.vue';
import CommentPanel from '@/Components/CommentPanel.vue';
import HistoryPanel from '@/Components/HistoryPanel.vue';
import { formatDuration, formatDate, formatDateTime } from '@/utils/format';

const props = defineProps({ project: Object, stages: Array, users: Array, finance: Object, financeEntityType: String, financeEntityId: Number, financeInvoices: Array, financeExpenses: Array, canSeeMoney: Boolean, history: Array, stageLogs: { type: Array, default: () => [] } });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const tab = ref('info');
const lastStage = computed(() => props.stages[props.stages.length - 1]);
const isLast = computed(() => props.project.project_stage_id === lastStage.value?.id);

const moveStage = (id) => router.patch(route('projects.stage', props.project.id), { project_stage_id: id }, { preserveScroll: true });
const advance = () => router.patch(route('projects.advance', props.project.id), {}, { preserveScroll: true });
const sendToAct = () => router.post(route('projects.toAct', props.project.id), {}, { preserveScroll: true });
</script>

<template>
    <Head :title="project.number" />
    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="route('projects.index')" class="flex-shrink-0 text-slate-400 transition-colors duration-150 hover:text-slate-600">← {{ $t('page.workshop', 'Цех') }}</Link>
                <span class="min-w-0 truncate" :title="project.deal?.company_name || project.name">{{ project.deal?.company_name || project.name }}</span>
                <span class="flex-shrink-0 whitespace-nowrap rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ project.number }}</span>
            </div>
        </template>

        <PageLayout :title="project.number" :subtitle="project.deal?.company_name || project.name">
            <!-- Этапы заказа — секция (DESIGN.md §6): главное для цеха -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="px-6 py-4">
                    <div class="flex items-center gap-2 overflow-x-auto pb-1">
                        <template v-for="(stage, i) in stages" :key="stage.id">
                            <button @click="moveStage(stage.id)"
                                :class="stage.id === project.project_stage_id ? 'border-transparent text-white shadow-sm' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'"
                                :style="stage.id === project.project_stage_id ? { backgroundColor: stage.color } : {}"
                                class="whitespace-nowrap rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150">
                                {{ stage.name }}
                            </button>
                            <span v-if="i < stages.length - 1" class="text-slate-300">›</span>
                        </template>
                    </div>
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <button v-if="!isLast" @click="advance"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">
                            Далее — следующий этап →
                        </button>
                        <button v-else-if="project.status !== 'completed'" @click="sendToAct"
                            class="rounded-lg bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100">
                            🚚 Готово — отправить на «Логистику»
                        </button>
                        <span v-else class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>✓ Отправлено на «Логистику»</span>
                    </div>
                </div>
            </div>

            <!-- Тайминг этапов — секция (DESIGN.md §6) + таблица (DESIGN.md §7) -->
            <div v-if="stageLogs.length" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">⏱ Тайминг этапов</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">Этап</th>
                                <th class="px-4 py-2.5 text-right">Период</th>
                                <th class="px-4 py-2.5 text-right">Время</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="(l, i) in stageLogs" :key="i" class="transition-colors duration-150 hover:bg-slate-50/60" :class="l.open ? 'bg-indigo-50/40' : ''">
                                <td class="px-6 py-2.5">
                                    <span class="font-medium text-slate-800">{{ l.stage }}</span>
                                    <span v-if="l.open" class="ml-2 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">сейчас</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-[11px] tabular-nums text-slate-400">{{ formatDateTime(l.entered_at) }}<template v-if="l.left_at"> → {{ formatDateTime(l.left_at) }}</template></td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums" :class="l.open ? 'text-indigo-700' : 'text-slate-800'">{{ formatDuration(l.seconds) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-6">
                    <!-- Секция с вкладками (DESIGN.md §1, §6) -->
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 px-6 pt-3 pb-px">
                            <button :class="tab==='info' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='info'">Информация</button>
                            <button :class="tab==='tasks' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='tasks'">Задачи ({{ project.tasks.length }})</button>
                            <button v-if="canSeeMoney" :class="tab==='finance' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='finance'">Финансы</button>
                            <button :class="tab==='docs' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='docs'">Документы ({{ project.documents.length }})</button>
                            <button :class="tab==='comments' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='comments'">Комментарии ({{ project.comments.length }})</button>
                            <button :class="tab==='history' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'" class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150" @click="tab='history'">История</button>
                        </nav>

                        <div class="p-6">
                        <div v-if="tab==='info'" class="space-y-3 text-sm">
                            <div class="flex justify-between border-b border-slate-100 py-2"><span class="text-slate-500">Клиент</span><span class="font-medium text-slate-800">{{ project.client?.name ?? '—' }}</span></div>
                            <div class="flex justify-between border-b border-slate-100 py-2"><span class="text-slate-500">Ответственный</span><span class="font-medium text-slate-800">{{ project.responsible?.name ?? '—' }}</span></div>
                            <div v-if="project.deal && canSeeMoney" class="flex justify-between border-b border-slate-100 py-2">
                                <span class="text-slate-500">Из сделки</span>
                                <Link :href="route('deals.show', project.deal.id)" class="font-medium text-indigo-600 hover:underline">{{ project.deal.number }}</Link>
                            </div>
                            <div class="flex justify-between border-b border-slate-100 py-2"><span class="text-slate-500">Срок</span><span class="font-medium tabular-nums text-slate-800">{{ formatDate(project.deadline) }}</span></div>
                            <div class="py-2"><div class="mb-1 text-slate-500">Описание</div><p class="whitespace-pre-line text-sm text-slate-600">{{ project.description ?? '—' }}</p></div>
                        </div>

                        <TaskPanel v-else-if="tab==='tasks'" :tasks="project.tasks" taskable-type="project" :taskable-id="project.id" :users="users" />
                        <FinancePanel v-else-if="tab==='finance' && canSeeMoney" :entity-type="financeEntityType" :entity-id="financeEntityId" :client-id="project.client_id" :invoices="financeInvoices" :expenses="financeExpenses" :finance="finance" :balances="$page.props.balances" />
                        <DocumentPanel v-else-if="tab==='docs'" :documents="project.documents" entity-type="project" :entity-id="project.id" />
                        <CommentPanel v-else-if="tab==='comments'" :comments="project.comments" entity-type="project" :entity-id="project.id" />
                        <HistoryPanel v-else :history="history" />
                        </div>
                    </div>
                </div>

                <!-- Budget aside — only for privileged roles (DESIGN.md §5, §6) -->
                <div v-if="canSeeMoney && finance" class="self-start rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Бюджет (сумма)</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-indigo-600">{{ money(finance.budget) }}</div>
                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Статус</span><StatusBadge :status="project.status" /></div>
                        <div class="flex justify-between"><span class="text-slate-500">Расходы</span><span class="font-medium tabular-nums text-rose-600">{{ money(finance.expense) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">Прибыль</span><span class="font-medium tabular-nums" :class="finance.plannedProfit >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(finance.plannedProfit) }}</span></div>
                        <div class="flex justify-between border-t border-slate-100 pt-2"><span class="text-slate-500">Маржа</span><span class="font-bold tabular-nums text-slate-900">{{ finance.plannedMargin }}% · {{ money(finance.plannedProfit) }}</span></div>
                    </div>
                </div>
                <div v-else class="self-start rounded-2xl border border-indigo-100 bg-indigo-50 p-6 text-sm text-indigo-700">
                    Выполните свой этап и нажмите «Далее». Финансовые данные видны только руководству.
                </div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
