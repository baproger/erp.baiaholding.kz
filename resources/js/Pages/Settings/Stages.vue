<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    dealStages: Array, projectStages: Array,
    companies: Array, selectedCompanyId: Number,
    stageTypes: Object, gateRoles: Object, missingTypes: Object,
});

// Готовая палитра — админ выбирает цвет в один клик, без возни с пипеткой.
const PALETTE = ['#6366F1', '#3B82F6', '#0EA5E9', '#14B8A6', '#10B981', '#84CC16', '#F59E0B', '#F97316', '#EF4444', '#EC4899', '#8B5CF6', '#64748B'];

// Выбор воронки: компания (BAIA/ASU/…) × вид (сделки | цех).
const funnel = ref(props.selectedCompanyId);
const kindTab = ref('deal');
const isWorkshop = computed(() => kindTab.value === 'project');
const kind = computed(() => kindTab.value);
// Существующие цеха компании (BAIA: «Металл цех», «Ағаш цех») — подсказки в поле.
const workshopNames = computed(() => [...new Set((props.projectStages ?? []).map((s) => s.workshop).filter(Boolean))]);

// Подвкладки цехов: каждый цех настраивается ОТДЕЛЬНО (свой список этапов).
const workshopTabs = computed(() => {
    const tabs = workshopNames.value.map((w) => ({ key: w, label: w }));
    if (!tabs.length || (props.projectStages ?? []).some((s) => s.company_id && !s.workshop)) tabs.push({ key: '', label: 'Единый цех' });
    return tabs;
});
const workshopTab = ref(null);
const activeWs = computed(() => {
    const keys = workshopTabs.value.map((t) => t.key);
    return workshopTab.value !== null && keys.includes(workshopTab.value) ? workshopTab.value : (keys[0] ?? '');
});
const stages = computed(() => (isWorkshop.value
    ? (props.projectStages ?? []).filter((s) => (s.workshop ?? '') === activeWs.value)
    : props.dealStages));

const switchFunnel = (v) => {
    funnel.value = v;
    router.get(route('stages.index'), { company: v }, { preserveState: true, preserveScroll: true, replace: true });
};

// Добавление
const newForm = useForm({ kind: 'deal', name: '', color: '#6366F1', workshop: '' });
const adding = ref(false);
const startAdd = () => { adding.value = true; editing.value = null; newForm.reset(); newForm.kind = kind.value; newForm.color = '#6366F1'; newForm.workshop = isWorkshop.value ? activeWs.value : ''; };
const add = () => newForm
    .transform((d) => ({ ...d, kind: kind.value }))
    .post(route('stages.store', { company: funnel.value }), { preserveScroll: true, onSuccess: () => (adding.value = false) });

const move = (stage, direction) => router.patch(route('stages.move', [kind.value, stage.id]), { direction }, { preserveScroll: true });

// Редактор этапа: имя + цвет + (для сделок) тип и гейт / (для цеха) завершающий.
const editing = ref(null);
const editForm = useForm({ name: '', color: '#6366F1', stage_type: '', gate_task_title: '', gate_task_role: 'financist', gate_task_days: '', is_completed: false, workshop: '' });
const startEdit = (stage) => {
    editing.value = stage.id;
    adding.value = false;
    editForm.clearErrors();
    editForm.name = stage.name;
    editForm.color = stage.color || '#6366F1';
    editForm.stage_type = stage.stage_type ?? '';
    editForm.gate_task_title = stage.gate_task_title ?? '';
    editForm.gate_task_role = stage.gate_task_role ?? 'financist';
    editForm.gate_task_days = stage.gate_task_days ?? '';
    editForm.is_completed = !!stage.is_completed;
    editForm.workshop = stage.workshop ?? '';
};
const saveEdit = (stage) => editForm
    .transform((d) => isWorkshop.value
        ? { name: d.name, color: d.color, is_completed: d.is_completed, workshop: d.workshop || null }
        : {
            name: d.name, color: d.color,
            stage_type: d.stage_type || null,
            gate_task_title: d.gate_task_title || null,
            gate_task_role: d.gate_task_role || null,
            gate_task_days: d.gate_task_days || null,
        })
    .put(route('stages.update', [kind.value, stage.id]), { preserveScroll: true, onSuccess: () => (editing.value = null) });

// Удаление: если на этапе есть активные сделки/заказы — выбор этапа для переноса.
const removing = ref(null);
const transferTo = ref('');
const removeErr = ref('');
const occupants = (s) => (isWorkshop.value ? (s.projects_count ?? 0) : (s.active_deals_count ?? 0));
const startRemove = async (stage) => {
    removeErr.value = '';
    if (!occupants(stage)) {
        if (await confirmDialog({ title: 'Удалить этап', message: `Этап «${stage.name}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
            router.delete(route('stages.destroy', [kind.value, stage.id]), { preserveScroll: true });
        }
        return;
    }
    removing.value = stage.id;
    transferTo.value = '';
};
const confirmRemove = (stage) => router.delete(route('stages.destroy', [kind.value, stage.id]), {
    data: { transfer_to: transferTo.value },
    preserveScroll: true,
    onSuccess: () => (removing.value = null),
    onError: (e) => (removeErr.value = e.transfer_to ?? ''),
});

const typeBadge = (s) => s.stage_type ? (props.stageTypes[s.stage_type] ?? s.stage_type) : null;
const companyName = computed(() => props.companies.find((c) => c.id === funnel.value)?.name ?? '');
</script>

<template>
    <Head title="Этапы" />
    <AppLayout>
        <template #header>{{ $t('page.settings_stages', 'Настройки · Этапы') }}</template>

        <PageLayout title="Этапы" subtitle="воронки сделок и этапы цехов">
            <template #tabs>
                <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
                    <Link :href="route('settings.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Общие</Link>
                    <Link :href="route('stages.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150">Этапы</Link>
                    <Link :href="route('screens.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Экраны</Link>
                    <Link :href="route('custom-fields.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Доп. поля</Link>
                    <Link :href="route('translations.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Переводы</Link>
                </nav>
            </template>

            <template #actions>
                <PrimaryButton @click="startAdd">+ Добавить этап</PrimaryButton>
            </template>

            <!-- Выбор воронки: компания + (сделки | цех) -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                    <button v-for="c in companies" :key="c.id" type="button" @click="switchFunnel(c.id)"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="funnel === c.id ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                        {{ c.name }}
                    </button>
                </div>
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                    <button type="button" @click="kindTab = 'deal'"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="!isWorkshop ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                        Воронка сделок
                    </button>
                    <button type="button" @click="kindTab = 'project'"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="isWorkshop ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                        Цех
                    </button>
                </div>
                <!-- Выбор цеха: у BAIA два — настраиваются отдельно -->
                <div v-if="isWorkshop && workshopTabs.length > 1" class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                    <button v-for="t in workshopTabs" :key="t.key" type="button" @click="workshopTab = t.key"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="activeWs === t.key ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">
                        {{ t.label }}
                    </button>
                </div>
            </div>

            <!-- Предупреждение о незаданных обязательных типах -->
            <div v-if="!isWorkshop && Object.keys(missingTypes).length" class="mb-4 flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                <span class="text-lg leading-none">⚠️</span>
                <div class="text-amber-800">
                    <b>Не назначены системные типы:</b> {{ Object.values(missingTypes).join(' · ') }}.
                    <div class="mt-1 text-xs text-amber-700">Без «Оплата успешно» сделки не считаются успешными (деньги/ЗП/аналитика); без «Закуп/цех» и «Логистика» не работает отправка в цех и возврат. Назначьте тип через «Изменить».</div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">{{ isWorkshop ? 'Этапы — ' + (activeWs || 'единый цех') : 'Воронка сделок' }}</h3>
                        <p class="mt-0.5 text-[11px] text-slate-400">{{ companyName }} · перетаскивать порядок стрелками ↑↓ слева</p>
                    </div>
                </div>

                <!-- Форма добавления -->
                <div v-if="adding" class="border-b border-slate-100 bg-indigo-50/40 px-6 py-4">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[200px] flex-1">
                            <InputLabel value="Название этапа" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="newForm.name" placeholder="Например: Замер" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" @keyup.enter="add" />
                        </div>
                        <div>
                            <InputLabel value="Цвет" class="mb-1 block text-xs font-medium text-slate-500" />
                            <div class="mt-1 flex items-center gap-1.5">
                                <button v-for="c in PALETTE" :key="c" type="button" @click="newForm.color = c"
                                    class="h-6 w-6 rounded-full ring-offset-1 transition-transform hover:scale-110"
                                    :class="newForm.color === c ? 'ring-2 ring-slate-800' : ''" :style="{ backgroundColor: c }"></button>
                                <input type="color" v-model="newForm.color" class="h-7 w-7 cursor-pointer rounded border-0 bg-transparent p-0" title="Свой цвет" />
                            </div>
                        </div>
                        <div v-if="isWorkshop" class="min-w-[160px]">
                            <InputLabel value="Цех (для BAIA: Металл / Ағаш)" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="newForm.workshop" list="workshop-names" placeholder="Пусто = единый цех" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                            <datalist id="workshop-names"><option v-for="w in workshopNames" :key="w" :value="w" /></datalist>
                        </div>
                        <PrimaryButton :disabled="newForm.processing || !newForm.name" @click="add">Добавить</PrimaryButton>
                        <SecondaryButton @click="adding = false">Отмена</SecondaryButton>
                    </div>
                </div>

                <!-- Список этапов -->
                <div class="divide-y divide-slate-50">
                    <div v-for="(stage, idx) in stages" :key="stage.id" class="group">
                        <div class="flex items-center gap-3 px-6 py-3 transition-colors duration-150 hover:bg-slate-50/60">
                            <!-- Реордер -->
                            <div class="flex flex-col text-slate-300">
                                <button class="leading-none transition-colors duration-150 hover:text-indigo-600 disabled:opacity-25" :disabled="idx === 0" @click="move(stage, 'up')" title="Выше">▲</button>
                                <button class="leading-none transition-colors duration-150 hover:text-indigo-600 disabled:opacity-25" :disabled="idx === stages.length - 1" @click="move(stage, 'down')" title="Ниже">▼</button>
                            </div>
                            <!-- Номер -->
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold tabular-nums text-slate-500">{{ idx + 1 }}</span>
                            <!-- Цвет -->
                            <span class="h-4 w-4 shrink-0 rounded-full shadow ring-2 ring-white" :style="{ backgroundColor: stage.color || '#94a3b8' }"></span>
                            <!-- Название + бейджи -->
                            <div class="flex flex-1 flex-wrap items-center gap-1.5">
                                <span class="text-sm font-medium text-slate-800">{{ stage.name }}</span>
                                <span v-if="typeBadge(stage)" class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">{{ typeBadge(stage) }}</span>
                                <span v-if="stage.gate_task_title" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700" :title="`Задача: ${stage.gate_task_title} · ${gateRoles[stage.gate_task_role] ?? stage.gate_task_role} · ${stage.gate_task_days} дн.`">🔒 гейт</span>
                                <span v-if="stage.is_completed" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700" title="Заказ готов → сделка на Логистику">🏁 завершающий</span>
                                <span v-if="stage.workshop" class="rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700">{{ stage.workshop }}</span>
                                <span v-if="occupants(stage)" class="text-[11px] tabular-nums text-slate-400">· {{ occupants(stage) }} {{ isWorkshop ? 'заказ.' : 'сдел.' }}</span>
                            </div>
                            <!-- Действия -->
                            <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                                <button class="rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-indigo-50 hover:text-indigo-700" @click="startEdit(stage)">Изменить</button>
                                <button class="rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-400 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-600" @click="startRemove(stage)">Удалить</button>
                            </div>
                        </div>

                        <!-- Редактор -->
                        <div v-if="editing === stage.id" class="border-l-2 border-indigo-400 bg-indigo-50/40 px-6 py-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Название" class="mb-1 block text-xs font-medium text-slate-500" />
                                    <TextInput v-model="editForm.name" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                                </div>
                                <div>
                                    <InputLabel value="Цвет" class="mb-1 block text-xs font-medium text-slate-500" />
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <button v-for="c in PALETTE" :key="c" type="button" @click="editForm.color = c"
                                            class="h-6 w-6 rounded-full ring-offset-1 transition-transform hover:scale-110"
                                            :class="editForm.color?.toUpperCase() === c ? 'ring-2 ring-slate-800' : ''" :style="{ backgroundColor: c }"></button>
                                        <input type="color" v-model="editForm.color" class="h-7 w-7 cursor-pointer rounded border-0 bg-transparent p-0" title="Свой цвет" />
                                    </div>
                                </div>
                                <div v-if="!isWorkshop">
                                    <InputLabel value="Системный тип (логика этапа)" class="mb-1 block text-xs font-medium text-slate-500" />
                                    <select v-model="editForm.stage_type" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                        <option value="">— обычный этап —</option>
                                        <option v-for="(label, t) in stageTypes" :key="t" :value="t">{{ label }}</option>
                                    </select>
                                    <div v-if="editForm.errors.stage_type" class="mt-1 text-xs text-red-600">{{ editForm.errors.stage_type }}</div>
                                </div>
                            </div>

                            <div v-if="isWorkshop" class="mt-3">
                                <InputLabel value="Цех этапа (у BAIA два: Металл цех / Ағаш цех)" class="mb-1 block text-xs font-medium text-slate-500" />
                                <TextInput v-model="editForm.workshop" list="workshop-names" placeholder="Пусто = единый цех компании" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20 sm:w-72" />
                            </div>

                            <!-- Цех: завершающий этап -->
                            <label v-if="isWorkshop" class="mt-3 flex items-center gap-2 rounded-lg bg-white/60 px-3 py-2 text-sm text-slate-700">
                                <input type="checkbox" v-model="editForm.is_completed" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                🏁 Завершающий этап — заказ считается готовым, сделка возвращается на «Логистику»
                            </label>

                            <!-- Сделки: гейт-задача -->
                            <template v-if="!isWorkshop">
                                <div class="mt-3 text-xs font-semibold text-slate-500">🔒 Гейт: задача при входе на этап (пока не закрыта — сделка дальше не идёт). Пусто = без гейта.</div>
                                <div class="mt-1.5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div>
                                        <InputLabel value="Текст задачи" class="mb-1 block text-xs font-medium text-slate-500" />
                                        <TextInput v-model="editForm.gate_task_title" placeholder="Выставить акт" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                                    </div>
                                    <div>
                                        <InputLabel value="Кому (роль)" class="mb-1 block text-xs font-medium text-slate-500" />
                                        <select v-model="editForm.gate_task_role" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                            <option v-for="(label, r) in gateRoles" :key="r" :value="r">{{ label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel value="Срок, дней" class="mb-1 block text-xs font-medium text-slate-500" />
                                        <TextInput v-model="editForm.gate_task_days" type="number" min="1" max="365" class="mt-1 w-full rounded-md border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                                    </div>
                                </div>
                            </template>

                            <div class="mt-4 flex gap-2">
                                <PrimaryButton :disabled="editForm.processing || !editForm.name" @click="saveEdit(stage)">Сохранить</PrimaryButton>
                                <SecondaryButton @click="editing = null">Отмена</SecondaryButton>
                            </div>
                        </div>

                        <!-- Удаление с переносом -->
                        <div v-if="removing === stage.id" class="border-l-2 border-rose-400 bg-rose-50/50 px-6 py-4">
                            <div class="text-sm text-rose-700">На этапе «{{ stage.name }}» — {{ occupants(stage) }} {{ isWorkshop ? 'заказ(ов)' : 'активных сделок' }}. Куда их перенести перед удалением?</div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <select v-model="transferTo" class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                    <option value="">— выберите этап —</option>
                                    <option v-for="s in stages.filter((x) => x.id !== stage.id)" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <PrimaryButton :disabled="!transferTo" @click="confirmRemove(stage)">Перенести и удалить</PrimaryButton>
                                <SecondaryButton @click="removing = null">Отмена</SecondaryButton>
                            </div>
                            <div v-if="removeErr" class="mt-1 text-xs text-red-600">{{ removeErr }}</div>
                        </div>
                    </div>

                    <div v-if="!stages.length" class="px-6 py-10 text-center text-sm text-slate-400">
                        Этапов нет — нажмите «+ Добавить этап»
                    </div>
                </div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
