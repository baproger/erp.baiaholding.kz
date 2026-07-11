<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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

// Выбор воронки: компания (BAIA/ASU/…) × вид (сделки | цех). Цех у каждой
// компании СВОЙ: BAIA — мебельный, ASU — швейный.
const funnel = ref(props.selectedCompanyId); // company id
const kindTab = ref('deal'); // deal | project
const isWorkshop = computed(() => kindTab.value === 'project');
const kind = computed(() => kindTab.value);
const stages = computed(() => (isWorkshop.value ? props.projectStages : props.dealStages));
const switchFunnel = (v) => {
    funnel.value = v;
    router.get(route('stages.index'), { company: v }, { preserveState: true, preserveScroll: true, replace: true });
};

const newForm = useForm({ kind: 'deal', name: '', color: '#6B7280' });
const adding = ref(false);
const startAdd = () => { adding.value = true; newForm.reset(); newForm.kind = kind.value; newForm.color = '#6B7280'; };
const add = () => newForm
    .transform((d) => ({ ...d, kind: kind.value }))
    .post(route('stages.store', { company: funnel.value }), { preserveScroll: true, onSuccess: () => (adding.value = false) });

const move = (stage, direction) => router.patch(route('stages.move', [kind.value, stage.id]), { direction }, { preserveScroll: true });
const recolor = (stage, e) => router.put(route('stages.update', [kind.value, stage.id]), { color: e.target.value }, { preserveScroll: true });

// Редактор этапа: имя + (для сделок) тип и гейт-задача.
const editing = ref(null);
const editForm = useForm({ name: '', stage_type: '', gate_task_title: '', gate_task_role: 'financist', gate_task_days: '' });
const startEdit = (stage) => {
    editing.value = stage.id;
    editForm.clearErrors();
    editForm.name = stage.name;
    editForm.stage_type = stage.stage_type ?? '';
    editForm.gate_task_title = stage.gate_task_title ?? '';
    editForm.gate_task_role = stage.gate_task_role ?? 'financist';
    editForm.gate_task_days = stage.gate_task_days ?? '';
};
const saveEdit = (stage) => editForm
    .transform((d) => isWorkshop.value ? { name: d.name } : {
        ...d,
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
</script>

<template>
    <Head title="Этапы" />
    <AppLayout>
        <template #header>{{ $t('page.settings_stages', 'Настройки · Этапы') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Общие</Link>
            <Link :href="route('stages.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Этапы</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Доп. поля</Link>
        </div>

        <!-- Выбор воронки: компания × (сделки | цех) -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button v-for="c in companies" :key="c.id" type="button" @click="switchFunnel(c.id)"
                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                :class="funnel === c.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                {{ c.name }}
            </button>
            <span class="mx-1 h-6 w-px bg-slate-200"></span>
            <button type="button" @click="kindTab = 'deal'"
                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                :class="!isWorkshop ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                Воронка сделок
            </button>
            <button type="button" @click="kindTab = 'project'"
                class="rounded-lg border px-4 py-2 text-sm font-semibold transition-all"
                :class="isWorkshop ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                Цех
            </button>
        </div>

        <!-- Обязательные типы не назначены — предупреждение (для воронки компании) -->
        <div v-if="!isWorkshop && Object.keys(missingTypes).length" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <b>⚠️ В этой воронке не назначены системные типы:</b>
            <span class="ml-1">{{ Object.values(missingTypes).join(' · ') }}.</span>
            <div class="mt-1 text-xs text-amber-700">Без «Оплата успешно (won)» сделки не будут считаться успешными и не попадут в деньги/ЗП/аналитику; без «Закуп/цех» и «Логистика» не работает отправка в цех и возврат из него. Назначьте типы через «Изменить» у этапа.</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="font-semibold text-slate-700">{{ (isWorkshop ? 'Цех · ' : 'Воронка сделок · ') + (companies.find((c) => c.id === funnel)?.name ?? '') }}</h3>
                <button class="text-sm text-indigo-600 hover:underline" @click="startAdd">+ Добавить этап</button>
            </div>

            <div v-if="adding" class="mb-3 flex items-center gap-2 rounded-md border border-dashed p-2">
                <input type="color" v-model="newForm.color" class="h-8 w-8 rounded border-0" />
                <TextInput v-model="newForm.name" placeholder="Название этапа" class="flex-1" @keyup.enter="add" />
                <PrimaryButton :disabled="newForm.processing || !newForm.name" @click="add">ОК</PrimaryButton>
                <button class="text-sm text-slate-500" @click="adding = false">✕</button>
            </div>

            <div class="space-y-2">
                <div v-for="(stage, idx) in stages" :key="stage.id" class="rounded-md bg-slate-50 px-3 py-2">
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <button class="text-[10px] leading-3 text-slate-400 hover:text-indigo-600 disabled:opacity-30" :disabled="idx === 0" @click="move(stage, 'up')" title="Выше">▲</button>
                            <button class="text-[10px] leading-3 text-slate-400 hover:text-indigo-600 disabled:opacity-30" :disabled="idx === stages.length - 1" @click="move(stage, 'down')" title="Ниже">▼</button>
                        </div>
                        <input type="color" :value="stage.color" class="h-6 w-6 rounded border-0" @change="recolor(stage, $event)" />
                        <span class="flex-1 text-sm font-medium text-slate-800">
                            {{ stage.order }}. {{ stage.name }}
                            <span v-if="typeBadge(stage)" class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">{{ typeBadge(stage) }}</span>
                            <span v-if="stage.gate_task_title" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700" :title="`Задача: ${stage.gate_task_title} · ${gateRoles[stage.gate_task_role] ?? stage.gate_task_role} · ${stage.gate_task_days} дн.`">🔒 гейт</span>
                            <span v-if="occupants(stage)" class="ml-1 text-[10px] text-slate-400">{{ occupants(stage) }} {{ isWorkshop ? 'заказ.' : 'сдел.' }}</span>
                        </span>
                        <button class="text-xs text-indigo-600 hover:underline" @click="startEdit(stage)">Изменить</button>
                        <button class="text-xs text-red-500 hover:underline" @click="startRemove(stage)">Удалить</button>
                    </div>

                    <!-- Редактор: имя + тип + гейт (тип/гейт только у воронок компаний) -->
                    <div v-if="editing === stage.id" class="mt-2 rounded-lg border border-dashed border-indigo-300 bg-indigo-50/40 p-3">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Название" />
                                <TextInput v-model="editForm.name" class="mt-1 w-full" />
                            </div>
                            <div v-if="!isWorkshop">
                                <InputLabel value="Системный тип (логика этапа)" />
                                <select v-model="editForm.stage_type" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    <option value="">— обычный этап —</option>
                                    <option v-for="(label, t) in stageTypes" :key="t" :value="t">{{ label }}</option>
                                </select>
                                <div v-if="editForm.errors.stage_type" class="mt-1 text-xs text-red-600">{{ editForm.errors.stage_type }}</div>
                            </div>
                        </div>
                        <template v-if="!isWorkshop">
                            <div class="mt-2 text-xs font-semibold text-slate-500">Гейт: задача при входе на этап (пока не закрыта — сделка дальше не идёт). Оставьте текст пустым, чтобы отключить.</div>
                            <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <div>
                                    <InputLabel value="Текст задачи" />
                                    <TextInput v-model="editForm.gate_task_title" placeholder="Выставить акт" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Кому (роль)" />
                                    <select v-model="editForm.gate_task_role" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                        <option v-for="(label, r) in gateRoles" :key="r" :value="r">{{ label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel value="Срок, дней" />
                                    <TextInput v-model="editForm.gate_task_days" type="number" min="1" max="365" class="mt-1 w-full" />
                                </div>
                            </div>
                        </template>
                        <div class="mt-2 flex gap-2">
                            <PrimaryButton :disabled="editForm.processing || !editForm.name" @click="saveEdit(stage)">Сохранить</PrimaryButton>
                            <SecondaryButton @click="editing = null">Отмена</SecondaryButton>
                        </div>
                    </div>

                    <!-- Удаление с переносом -->
                    <div v-if="removing === stage.id" class="mt-2 rounded-lg border border-dashed border-rose-300 bg-rose-50/50 p-3">
                        <div class="text-sm text-rose-700">На этапе «{{ stage.name }}» — {{ occupants(stage) }} {{ isWorkshop ? 'заказ(ов)' : 'активных сделок' }}. Куда их перенести?</div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <select v-model="transferTo" class="rounded-md border-slate-300 text-sm shadow-sm">
                                <option value="">— выберите этап —</option>
                                <option v-for="s in stages.filter((x) => x.id !== stage.id)" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <PrimaryButton :disabled="!transferTo" @click="confirmRemove(stage)">Перенести и удалить</PrimaryButton>
                            <SecondaryButton @click="removing = null">Отмена</SecondaryButton>
                        </div>
                        <div v-if="removeErr" class="mt-1 text-xs text-red-600">{{ removeErr }}</div>
                    </div>
                </div>
                <div v-if="!stages.length" class="py-4 text-center text-sm text-slate-400">Этапов нет</div>
            </div>
        </div>
    </AppLayout>
</template>
