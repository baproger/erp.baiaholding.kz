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
import { formatDate, money } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ deals: [Array, Object], stages: Array, view: String, filters: Object, users: Array, can: Object, isLeadership: Boolean });

const list = computed(() => Array.isArray(props.deals) ? props.deals : props.deals.data);
const byStage = (id) => list.value.filter((d) => d.deal_stage_id === id);
const stageTotal = (id) => byStage(id).reduce((s, d) => s + Number(d.budget), 0);
const lastStageId = computed(() => props.stages[props.stages.length - 1]?.id);
const firstStageId = computed(() => props.stages[0]?.id); // «Заключение договора»
// "В цех" is triggered on the 3rd-from-last stage (Закуп ЛДСП,МДФ);
// last two stages (Акт утверждение, Оплата) come after the workshop.
const workshopStageId = computed(() => props.stages[props.stages.length - 3]?.id);
const returnStageId = computed(() => props.stages[props.stages.length - 2]?.id);

const draggingId = ref(null);
const onDrop = async (stage) => {
    const id = draggingId.value; draggingId.value = null;
    if (!id) return;
    const deal = list.value.find((d) => d.id === id);
    if (!deal || deal.deal_stage_id === stage.id) return;
    // «Акт утверждение» — only via Цех; «Оплата» — only from «Акт утверждение».
    if (stage.id === returnStageId.value) return;
    if (stage.id === lastStageId.value && deal.deal_stage_id !== returnStageId.value) return;
    // Leaving the final «Оплата успешно» stage needs confirmation.
    if (deal.deal_stage_id === lastStageId.value
        && ! (await confirmDialog({ title: 'Сделка уже успешна', message: 'Сделка на этапе «Оплата успешно». Точно перевести её на другой этап?', confirmText: 'Перевести', danger: true }))) return;
    router.patch(route('deals.stage', id), { deal_stage_id: stage.id }, { preserveScroll: true, preserveState: false });
};
const advance = (deal) => router.patch(route('deals.advance', deal.id), {}, { preserveScroll: true, preserveState: false });
const toWorkshop = (deal) => router.post(route('deals.toWorkshop', deal.id), {}, { preserveScroll: true, preserveState: false });
const switchView = (v) => router.get(route('deals.index'), { ...props.filters, view: v }, { preserveState: true });

const search = ref(props.filters?.search ?? '');
let searchTimer = null;
const onSearch = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('deals.index'), { ...props.filters, search: search.value, view: props.view }, { preserveState: true, preserveScroll: true, replace: true });
    }, 350);
};

// Filters: manager + deadline range.
const fResponsible = ref(props.filters?.responsible ?? '');
const fFrom = ref(props.filters?.date_from ?? '');
const fTo = ref(props.filters?.date_to ?? '');
const applyFilters = () => router.get(route('deals.index'), {
    ...props.filters, view: props.view, search: search.value,
    responsible: fResponsible.value || undefined, date_from: fFrom.value || undefined, date_to: fTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetFilters = () => { fResponsible.value = ''; fFrom.value = ''; fTo.value = ''; applyFilters(); };

const showModal = ref(false);
const form = useForm({ name: '', company_name: '', address: '', bin: '', client_name: '', lot_number: '', responsible_user_id: '', budget: 0, deadline: '', description: '', note: '' });
const openCreate = () => { form.reset(); binMatch.value = null; showBinModal.value = false; showModal.value = true; };
const submit = () => form.post(route('deals.store'), { preserveScroll: true, onSuccess: () => (showModal.value = false) });

// БИН lookup: if the entered БИН already exists, offer to copy its company data.
const binMatch = ref(null);
const binHistory = ref([]);
const showBinModal = ref(false);
const showBinHistory = ref(false);
const checkBin = async () => {
    const bin = String(form.bin || '').trim();
    if (!bin) return;
    try {
        const res = await fetch(`${route('deals.binLookup')}?bin=${encodeURIComponent(bin)}`, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        binHistory.value = data.history || [];
        showBinHistory.value = false;
        if (data.match) { binMatch.value = data.match; showBinModal.value = true; }
    } catch (e) { /* ignore lookup errors */ }
};
const applyBinMatch = () => {
    if (binMatch.value) {
        form.company_name = binMatch.value.company_name;
        form.bin = binMatch.value.bin;
        if (binMatch.value.address) form.address = binMatch.value.address;
    }
    showBinModal.value = false;
};
</script>

<template>
    <Head title="Сделки" />
    <AppLayout>
        <template #header>{{ $t('page.deals', 'Сделки') }}</template>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-xl bg-white shadow-sm border border-slate-200">
                <button :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="rounded-l-lg px-4 py-1.5 text-sm transition-colors" @click="switchView('kanban')">Канбан</button>
                <button :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-600'" class="rounded-r-lg px-4 py-1.5 text-sm transition-colors" @click="switchView('list')">Список</button>
            </div>
            <div class="relative order-last w-full sm:order-none sm:w-auto sm:flex-1 sm:max-w-sm">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">🔍</span>
                <input v-model="search" @input="onSearch" type="text" placeholder="Поиск: компания, №, лот, БИН…"
                    class="w-full rounded-lg border-slate-200 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
            </div>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow transition-transform hover:scale-[1.02] hover:bg-indigo-700 active:scale-95" @click="openCreate">+ Новая сделка</button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div v-if="isLeadership">
                <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">Менеджер</label>
                <select v-model="fResponsible" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400">
                    <option value="">Все</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">Срок с</label>
                <input v-model="fFrom" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">Срок по</label>
                <input v-model="fTo" @change="applyFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
            </div>
            <button v-if="fResponsible || fFrom || fTo" type="button" @click="resetFilters" class="ml-auto rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Сбросить фильтры</button>
        </div>

        <!-- KANBAN -->
        <div v-if="view === 'kanban'" class="flex gap-3 overflow-x-auto pb-4">
            <div v-for="stage in stages" :key="stage.id" class="flex w-64 flex-shrink-0 flex-col rounded-xl bg-slate-100/80" @dragover.prevent @drop="onDrop(stage)">
                <div class="flex items-center justify-between px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-sm font-semibold text-slate-700">{{ stage.name }}</span>
                        <span class="text-xs text-slate-400">{{ byStage(stage.id).length }}</span>
                    </div>
                    <span class="text-[11px] font-medium text-slate-400">{{ money(stageTotal(stage.id)) }}</span>
                </div>
                <div class="flex-1 space-y-2 px-2 pb-2">
                    <div v-for="deal in byStage(stage.id)" :key="deal.id" draggable="true" @dragstart="draggingId = deal.id"
                        class="cursor-move rounded-lg bg-white p-2.5 border border-slate-200 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md hover:ring-indigo-200">
                        <Link :href="route('deals.show', deal.id)" class="block">
                            <div class="flex items-start justify-between">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-bold text-slate-900">{{ deal.company_name || deal.name }}</div>
                                    <div class="text-base font-bold leading-tight text-indigo-600">{{ money(deal.budget) }}</div>
                                </div>
                                <span v-if="deal.overdue_count" class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-600">ПРОСРОЧЕНА</span>
                                <span v-else-if="deal.tasks_count" class="flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500">{{ deal.tasks_count }}</span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-1.5">
                                <span class="flex h-5 w-5 items-center justify-center overflow-hidden rounded-full bg-indigo-500 text-[10px] font-bold text-white">
                                    <img v-if="deal.responsible?.avatar" :src="deal.responsible.avatar" class="h-full w-full object-cover" />
                                    <template v-else>{{ deal.responsible?.name?.charAt(0) ?? '—' }}</template>
                                </span>
                                <span class="truncate text-xs text-slate-600">{{ deal.responsible?.name ?? 'не назначен' }}</span>
                            </div>
                            <div v-if="deal.deadline" class="mt-1 text-[11px]" :class="deadlineClass(deal.deadline, deal.status==='closed') || 'text-slate-400'">⏰ {{ formatDate(deal.deadline) }}</div>
                            <div class="mt-0.5 truncate text-[11px] text-slate-400">👤 {{ deal.client_name || '—' }} <span class="text-slate-300">· {{ deal.number }}</span></div>
                        </Link>
                        <div class="mt-2 flex items-center justify-between border-t pt-1.5">
                            <Link :href="route('deals.show', deal.id)" class="text-[11px] text-slate-400 hover:text-indigo-600">+ Дело</Link>
                            <button v-if="deal.deal_stage_id === workshopStageId" @click="toWorkshop(deal)" class="rounded bg-emerald-600 px-2.5 py-1 text-[11px] font-semibold text-white transition-colors hover:bg-emerald-700">📦 В цех</button>
                            <button v-else-if="deal.deal_stage_id !== lastStageId" @click="advance(deal)" class="rounded bg-slate-100 px-2.5 py-1 text-[11px] text-slate-600 transition-colors hover:bg-indigo-100 hover:text-indigo-700">Далее →</button>
                        </div>
                    </div>
                    <div v-if="!byStage(stage.id).length" class="py-5 text-center text-[11px] text-slate-400">Пусто</div>
                    <button v-if="stage.id === firstStageId && can.create" @click="openCreate"
                        class="mt-1 flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-indigo-300 py-2 text-xs font-medium text-indigo-600 transition-colors hover:border-indigo-400 hover:bg-indigo-50">
                        + Новая сделка
                    </button>
                </div>
            </div>
        </div>

        <!-- LIST -->
        <div v-else class="overflow-hidden rounded-xl bg-white border border-slate-200 shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th class="px-4 py-3">Номер</th><th class="px-4 py-3">Название</th><th class="px-4 py-3">Клиент</th><th class="px-4 py-3">Этап</th><th class="px-4 py-3">Сумма</th><th class="px-4 py-3">Завершение</th><th class="px-4 py-3">Ответственный</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="deal in deals.data" :key="deal.id" class="cursor-pointer transition-colors hover:bg-slate-50" @click="router.get(route('deals.show', deal.id))">
                        <td class="px-4 py-3 text-slate-400">{{ deal.number }}</td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ deal.name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.client_name || deal.client?.name || '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" /></td>
                        <td class="px-4 py-3">{{ money(deal.budget) }}</td>
                        <td class="px-4 py-3" :class="deadlineClass(deal.deadline, deal.status==='closed')">{{ formatDate(deal.deadline) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.responsible?.name ?? '—' }}</td>
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
                    <div class="col-span-2"><InputLabel value="Название сделки" /><TextInput v-model="form.name" class="mt-1 w-full" /><InputError :message="form.errors.name" class="mt-1" /></div>
                    <div><InputLabel value="Название компании *" /><TextInput v-model="form.company_name" class="mt-1 w-full" /><InputError :message="form.errors.company_name" class="mt-1" /></div>
                    <div><InputLabel value="БИН" /><TextInput v-model="form.bin" class="mt-1 w-full" placeholder="12 цифр" @blur="checkBin" /><InputError :message="form.errors.bin" class="mt-1" /></div>
                    <div class="col-span-2"><InputLabel value="Адрес *" /><TextInput v-model="form.address" class="mt-1 w-full" placeholder="Город, улица, дом" /><InputError :message="form.errors.address" class="mt-1" /></div>
                    <div><InputLabel value="Имя клиента *" /><TextInput v-model="form.client_name" class="mt-1 w-full" /><InputError :message="form.errors.client_name" class="mt-1" /></div>
                    <div><InputLabel value="Номер лота" /><TextInput v-model="form.lot_number" class="mt-1 w-full" /></div>
                    <div v-if="isLeadership">
                        <InputLabel value="Ответственный" />
                        <select v-model="form.responsible_user_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div><InputLabel value="Общая сумма (бюджет)" /><TextInput v-model="form.budget" type="number" step="0.01" class="mt-1 w-full" /><InputError :message="form.errors.budget" class="mt-1" /></div>
                    <div><InputLabel value="Срок" /><TextInput v-model="form.deadline" type="date" class="mt-1 w-full" /></div>
                    <div class="col-span-2"><InputLabel value="Описание" /><textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm"></textarea></div>
                    <div class="col-span-2"><InputLabel value="Заметка (кратко)" /><textarea v-model="form.note" rows="2" class="mt-1 w-full rounded-md border-slate-300 shadow-sm" placeholder="Коротко и чётко по сделке"></textarea></div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Создать</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- BIN EXISTS MODAL -->
        <Modal :show="showBinModal" @close="showBinModal = false" max-width="lg">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Контрагент с этим БИН уже есть</h2>
                <p class="mt-1 text-sm text-slate-500">Можно подставить его данные в новую сделку.</p>
                <div class="mt-4 rounded-lg bg-slate-50 p-4 border border-slate-200">
                    <div class="text-xs uppercase tracking-wide text-slate-400">Компания</div>
                    <div class="text-base font-semibold text-slate-900">{{ binMatch?.company_name }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-1 text-xs text-slate-500">
                        <div>БИН: <span class="font-medium text-slate-700">{{ binMatch?.bin }}</span></div>
                        <div v-if="binMatch?.phone">Тел: <span class="font-medium text-slate-700">{{ binMatch.phone }}</span></div>
                        <div v-if="binMatch?.address" class="col-span-2">Адрес: <span class="font-medium text-slate-700">{{ binMatch.address }}</span></div>
                    </div>
                </div>

                <button v-if="binHistory.length" type="button" @click="showBinHistory = !showBinHistory"
                    class="mt-4 text-sm font-medium text-indigo-600 hover:text-indigo-700">
                    {{ showBinHistory ? '▾' : '▸' }} История сделок по этому БИН ({{ binHistory.length }})
                </button>
                <div v-if="showBinHistory" class="mt-2 max-h-56 space-y-1.5 overflow-y-auto pr-1">
                    <div v-for="h in binHistory" :key="h.id" class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2 text-xs">
                        <div class="min-w-0">
                            <div class="truncate font-medium text-slate-800">{{ h.company || h.client || h.number }}</div>
                            <div class="text-slate-400">{{ h.number }} · {{ h.created }}<span v-if="h.stage"> · {{ h.stage }}</span></div>
                        </div>
                        <div class="tabular-nums font-semibold text-slate-700">{{ money(h.budget) }}</div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showBinModal = false">Отмена</SecondaryButton>
                    <PrimaryButton @click="applyBinMatch">Подставить данные</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
