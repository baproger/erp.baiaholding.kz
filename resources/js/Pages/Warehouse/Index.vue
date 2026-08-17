<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { formatDate, formatDateTime } from '@/utils/format';
import { useStickyFilters, clearStickyFilters } from '@/composables/useStickyFilters';

const props = defineProps({
    materials: Array, writeoffs: Object, receipts: Array, units: Array,
    canManage: Boolean, allMode: Boolean, companyName: String, filters: Object,
});

// Детали списания: клик по колонке «Списание» — на какие сделки/заказы ушло.
const writeoffFor = ref(null); // материал, чьи списания открыты
const writeoffRows = computed(() => writeoffFor.value ? (props.writeoffs?.[writeoffFor.value.id] ?? []) : []);
const writeoffLink = (w) => w.type === 'deal' ? route('deals.show', w.target_id) : route('projects.show', w.target_id);

const qty = (v) => new Intl.NumberFormat('ru-RU').format(Number(v ?? 0));
const money = (v) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(v ?? 0)) + ' ₸';

// Приход: существующий материал или новая позиция.
const showModal = ref(false);
const mode = ref('existing'); // existing | new
const form = useForm({ material_id: '', name: '', unit: 'штук', quantity: '', price: '', date: '', note: '', payment_method: '' });
const openReceipt = () => {
    form.reset(); form.unit = 'штук';
    mode.value = props.materials.length ? 'existing' : 'new';
    showModal.value = true;
};
const submit = () => {
    const payload = mode.value === 'existing'
        ? { material_id: form.material_id, name: '', unit: '' }
        : { material_id: '', name: form.name, unit: form.unit };
    form.transform((d) => ({ ...d, ...payload }))
        .post(route('warehouse.receipt'), { preserveScroll: true, onSuccess: () => (showModal.value = false) });
};
const removeMaterial = async (m) => {
    if (await confirmDialog({ title: 'Удалить позицию', message: `«${m.name}» и вся история прихода будут удалены.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('warehouse.materials.destroy', m.id), { preserveScroll: true });
    }
};

// Правка/удаление прихода (бухгалтер/админ) — остаток пересчитывается на сервере.
const editingReceipt = ref(null);
const receiptForm = useForm({ quantity: '', price: '', date: '', note: '' });
const openEditReceipt = (r) => {
    editingReceipt.value = r.id;
    receiptForm.quantity = Number(r.quantity);
    receiptForm.price = r.price != null ? Number(r.price) : '';
    receiptForm.date = (r.date ?? '').slice(0, 10);
    receiptForm.note = r.note ?? '';
    receiptForm.clearErrors();
};
const saveReceipt = (r) => receiptForm.put(route('warehouse.receipts.update', r.id), {
    preserveScroll: true, onSuccess: () => (editingReceipt.value = null),
});
const removeReceipt = async (r) => {
    if (await confirmDialog({ title: 'Удалить приход', message: `Приход «+${r.quantity} ${r.material?.unit ?? ''} ${r.material?.name ?? ''}» будет удалён, остаток уменьшится.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('warehouse.receipts.destroy', r.id), { preserveScroll: true });
    }
};

// Фильтры: поиск / ед.изм / остаток — клиентские (данные уже загружены);
// период поступления — серверный (суммы считаются в контроллере).
const search = ref('');
const fUnit = ref('');
const fStock = ref(''); // '' все | 'in' в наличии | 'zero' на нуле
const fFrom = ref(props.filters?.from ?? '');
const fTo = ref(props.filters?.to ?? '');
const applyPeriod = () => router.get(route('warehouse.index'), {
    from: fFrom.value || undefined, to: fTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const hasFilters = computed(() => search.value || fUnit.value || fStock.value || fFrom.value || fTo.value);
const resetFilters = () => {
    search.value = ''; fUnit.value = ''; fStock.value = ''; fFrom.value = ''; fTo.value = '';
    clearStickyFilters('warehouse');
    applyPeriod();
};
// Фильтр страницы запоминается: вернулся на склад — тот же период и отбор.
useStickyFilters('warehouse', { search, fUnit, fStock, fFrom, fTo }, applyPeriod);
// Ед. изм. в фильтре — только реально имеющиеся на складе.
const unitOptions = computed(() => [...new Set(props.materials.map((m) => m.unit).filter(Boolean))]);
const filtered = computed(() => {
    const s = search.value.trim().toLowerCase();
    return props.materials
        .filter((m) => !s || m.name.toLowerCase().includes(s))
        .filter((m) => !fUnit.value || m.unit === fUnit.value)
        .filter((m) => !fStock.value || (fStock.value === 'zero' ? Number(m.quantity) <= 0 : Number(m.quantity) > 0));
});
const lowStock = (m) => Number(m.quantity) <= 0;
</script>

<template>
    <Head title="Склад" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <span>{{ $t('page.warehouse', 'Склад') }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">{{ companyName }}</span>
            </div>
        </template>

        <PageLayout :title="$t('page.warehouse', 'Склад')" subtitle="материалы: приход, списание, остатки">
            <template #actions>
                <button v-if="canManage" type="button" @click="openReceipt"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">+ Приход товара</button>
            </template>

            <!-- Фильтры: поиск, ед.изм, остаток, период поступления -->
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="relative w-full sm:w-56">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="search" type="text" placeholder="Поиск материала…"
                        class="w-full rounded-lg border-slate-200 py-1.5 pl-9 pr-3 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </div>
                <select v-model="fUnit" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20 sm:w-auto">
                    <option value="">Все ед. изм.</option>
                    <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
                </select>
                <select v-model="fStock" class="w-full rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20 sm:w-auto">
                    <option value="">Все остатки</option>
                    <option value="in">В наличии</option>
                    <option value="zero">На нуле</option>
                </select>
                <label class="flex items-center gap-1 text-[11px] text-slate-400">поступление с
                    <input v-model="fFrom" @change="applyPeriod" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <label class="flex items-center gap-1 text-[11px] text-slate-400">по
                    <input v-model="fTo" @change="applyPeriod" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <button v-if="hasFilters" type="button" @click="resetFilters"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-400 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-600">Сбросить ✕</button>
                <span class="ml-auto hidden text-[11px] tabular-nums text-slate-300 lg:block">найдено: {{ filtered.length }}</span>
            </div>

            <!-- Остатки — таблица (DESIGN.md §7) -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">Материал</th>
                                <th v-if="allMode" class="px-4 py-2.5">Компания</th>
                                <th class="px-4 py-2.5">Ед. изм.</th>
                                <th class="px-4 py-2.5 text-right">Цена за ед.</th>
                                <th class="px-4 py-2.5 text-right">Поступление</th>
                                <th class="px-4 py-2.5 text-right">Сумма</th>
                                <th class="px-4 py-2.5 text-right">Списание</th>
                                <th class="px-4 py-2.5 text-right">Остаток</th>
                                <th v-if="canManage" class="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="m in filtered" :key="m.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 font-medium text-slate-900">{{ m.name }}<span v-if="m.note" class="ml-2 text-[11px] text-slate-400">{{ m.note }}</span></td>
                                <td v-if="allMode" class="px-4 py-2.5 text-slate-500">{{ m.company?.name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ m.unit }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-slate-600">
                                    <template v-if="Number(m.price) > 0">{{ money(m.price) }}</template>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-emerald-600">
                                    <template v-if="Number(m.received_qty) > 0">+ {{ qty(m.received_qty) }}</template>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">
                                    <template v-if="Number(m.received_sum) > 0">{{ money(m.received_sum) }}</template>
                                    <span v-else class="font-normal text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    <button v-if="Number(m.written_off_qty) > 0" type="button" @click="writeoffFor = m"
                                        class="font-medium text-rose-600 underline decoration-rose-200 decoration-dashed underline-offset-4 transition-colors duration-150 hover:text-rose-700"
                                        title="Показать, на какие сделки списано">− {{ qty(m.written_off_qty) }}</button>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums"
                                        :class="lowStock(m) ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-700'">
                                        {{ qty(m.quantity) }}
                                    </span>
                                </td>
                                <td v-if="canManage" class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Удалить позицию" @click="removeMaterial(m)">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                    </button>
                                </td>
                            </tr>
                            <!-- Пустое состояние (DESIGN.md §12) -->
                            <tr v-if="!filtered.length">
                                <td :colspan="canManage ? (allMode ? 10 : 9) : (allMode ? 9 : 8)" class="px-6 py-10 text-center text-sm text-slate-400">
                                    <p>Склад пуст — оформите первый приход товара</p>
                                    <button v-if="canManage" type="button" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="openReceipt">+ Приход товара</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- История прихода — секция (DESIGN.md §6) -->
            <div v-if="receipts.length" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Последние приходы</h3>
                </div>
                <div class="divide-y divide-slate-50 px-6 py-2">
                    <div v-for="r in receipts" :key="r.id" class="py-2.5 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium tabular-nums text-emerald-700">+ {{ qty(r.quantity) }} {{ r.material?.unit }}</span>
                            <span class="font-medium text-slate-800">{{ r.material?.name }}</span>
                            <span v-if="Number(r.price) > 0" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">{{ money(r.price) }}/{{ r.material?.unit }}</span>
                            <span v-if="r.note" class="text-[11px] text-slate-400">{{ r.note }}</span>
                            <span class="ml-auto text-[11px] text-slate-400">{{ r.user?.name ?? '—' }} · {{ formatDate(r.date) }} · внесено {{ formatDateTime(r.created_at) }}</span>
                            <template v-if="canManage">
                                <button class="rounded p-1 text-slate-300 transition-colors hover:text-indigo-600" title="Редактировать приход" @click="openEditReceipt(r)">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                </button>
                                <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Удалить приход" @click="removeReceipt(r)">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                            </template>
                        </div>
                        <!-- Инлайн-правка прихода -->
                        <div v-if="editingReceipt === r.id" class="mt-2 rounded-lg border border-dashed border-indigo-300 bg-indigo-50/40 p-3">
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div>
                                    <InputLabel value="Количество" />
                                    <TextInput v-model="receiptForm.quantity" type="number" min="0.01" step="any" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Цена за ед., ₸" />
                                    <TextInput v-model="receiptForm.price" type="number" min="0" step="0.01" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Дата" />
                                    <TextInput v-model="receiptForm.date" type="date" class="mt-1 w-full" />
                                </div>
                                <div>
                                    <InputLabel value="Заметка" />
                                    <TextInput v-model="receiptForm.note" class="mt-1 w-full" />
                                </div>
                            </div>
                            <InputError :message="receiptForm.errors.quantity || receiptForm.errors.price" class="mt-1" />
                            <div class="mt-2 flex gap-2">
                                <PrimaryButton :disabled="receiptForm.processing" @click="saveReceipt(r)">Сохранить</PrimaryButton>
                                <SecondaryButton @click="editingReceipt = null">Отмена</SecondaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </PageLayout>

        <!-- Модалка прихода (DESIGN.md §11) -->
        <Modal :show="showModal" @close="showModal = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">Приход товара</h2>
                <p class="mb-4 text-xs text-slate-400">Материал добавится на остатки склада.</p>
                <div v-if="materials.length" class="mb-4 flex flex-wrap gap-2">
                    <button type="button" @click="mode = 'existing'"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                        :class="mode === 'existing' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                        Существующий материал
                    </button>
                    <button type="button" @click="mode = 'new'"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                        :class="mode === 'new' ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                        Новая позиция
                    </button>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-if="mode === 'existing' && materials.length" class="sm:col-span-2">
                        <InputLabel value="Материал" />
                        <select v-model="form.material_id" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                            <option value="">— выберите —</option>
                            <option v-for="m in materials" :key="m.id" :value="m.id">{{ m.name }} (остаток {{ qty(m.quantity) }} {{ m.unit }})</option>
                        </select>
                        <InputError :message="form.errors.material_id" class="mt-1" />
                    </div>
                    <template v-else>
                        <div>
                            <InputLabel value="Название материала *" />
                            <TextInput v-model="form.name" class="mt-1 w-full" placeholder="ЛДСП 16мм белый" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Ед. изм." />
                            <select v-model="form.unit" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                    </template>
                    <div>
                        <InputLabel value="Количество *" />
                        <TextInput v-model="form.quantity" type="number" min="0.01" step="any" class="mt-1 w-full" />
                        <InputError :message="form.errors.quantity" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Цена за ед., ₸" />
                        <TextInput v-model="form.price" type="number" min="0" step="0.01" class="mt-1 w-full" placeholder="Закупочная цена" />
                        <InputError :message="form.errors.price" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Дата" />
                        <TextInput v-model="form.date" type="date" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Заметка" />
                        <TextInput v-model="form.note" class="mt-1 w-full" placeholder="Поставщик, накладная…" />
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Оплата закупа" />
                        <!-- Нал/банк создаёт подтверждённый расход компании (кол-во × цена)
                             и уменьшает кассу/банк; «не списывать» — только остаток склада. -->
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button v-for="opt in [['', 'Не списывать деньги'], ['cash', 'Наличные'], ['bank', 'Банк']]" :key="opt[0]" type="button"
                                @click="form.payment_method = opt[0]"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                                :class="form.payment_method === opt[0] ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">{{ opt[1] }}</button>
                        </div>
                        <p v-if="form.payment_method" class="mt-1 text-xs text-slate-400">Будет создан расход «Закуп материалов» на сумму закупа — деньги уйдут из {{ form.payment_method === 'cash' ? 'кассы' : 'банка' }}.</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Оформить приход</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Детали списания: на какие сделки/заказы ушёл материал (DESIGN.md §11) -->
        <Modal :show="!!writeoffFor" @close="writeoffFor = null" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">Списания — {{ writeoffFor?.name }}</h2>
                <p class="mb-4 text-xs text-slate-400">Клик по строке — переход в сделку / заказ цеха</p>
                <div class="max-h-80 divide-y divide-slate-50 overflow-y-auto">
                    <button v-for="(w, i) in writeoffRows" :key="i" type="button" @click="w.target_id && router.get(writeoffLink(w))"
                        class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-left text-sm transition-colors duration-150"
                        :class="w.target_id ? 'hover:bg-indigo-50/60' : 'cursor-default opacity-60'">
                        <div class="min-w-0">
                            <div class="truncate font-medium" :class="w.target_id ? 'text-slate-800' : 'text-slate-400 line-through'">{{ w.label }}</div>
                            <div class="text-[11px] text-slate-400"><template v-if="w.number">{{ w.number }} · </template>{{ w.type === 'deal' ? 'сделка' : 'заказ цеха' }} · {{ w.date ? formatDate(w.date) : '—' }} · внесено {{ formatDateTime(w.created_at) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="whitespace-nowrap font-semibold tabular-nums text-rose-600">− {{ qty(w.qty) }} {{ writeoffFor?.unit }}</div>
                            <div class="text-[11px] tabular-nums text-slate-400">{{ money(w.amount) }}</div>
                        </div>
                    </button>
                    <div v-if="!writeoffRows.length" class="py-6 text-center text-sm text-slate-400">Списаний нет</div>
                </div>
                <div class="mt-4 flex justify-end"><SecondaryButton @click="writeoffFor = null">Закрыть</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
