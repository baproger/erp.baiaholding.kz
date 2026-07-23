<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDate } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({
    preDeals: Array, items: Array, minMargin: Number, taxPercent: Number,
    leadership: Boolean, stats: Array, managers: Array, filters: Object, canManageChecklist: Boolean,
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры (руководству): менеджер и статус.
const managerF = ref(props.filters?.manager ?? '');
const statusF = ref(props.filters?.status ?? '');
const applyFilters = () => router.get(route('preDeals.index'), {
    manager: managerF.value || undefined, status: statusF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

// Форма лота: живой расчёт как в Excel (партнёр/налог/остаток/маржа).
const showForm = ref(false);
const editingId = ref(null);
const form = useForm({ lot_number: '', bin: '', customer: '', client_name: '', client_phone: '', product: '', contract_sum: '', purchase_price: '', partner_pct: '', delivery: '', commission: '' });
const calc = computed(() => {
    const sum = Number(form.contract_sum || 0);
    const partner = Math.round(sum * Number(form.partner_pct || 0)) / 100;
    const tax = Math.round(sum * (props.taxPercent ?? 3)) / 100;
    const remainder = Math.round((sum - Number(form.purchase_price || 0) - partner - Number(form.delivery || 0) - Number(form.commission || 0) - tax) * 100) / 100;
    const margin = sum > 0 ? Math.round(remainder / sum * 10000) / 100 : 0;
    return { partner, tax, remainder, margin, pass: margin >= (props.minMargin ?? 15) };
});
const openCreate = () => { editingId.value = null; form.reset(); form.clearErrors(); showForm.value = true; };
const openEdit = (p) => {
    editingId.value = p.id;
    form.clearErrors();
    Object.assign(form, {
        lot_number: p.lot_number ?? '', bin: p.bin ?? '', customer: p.customer ?? '',
        client_name: p.client_name ?? '', client_phone: p.client_phone ?? '', product: p.product,
        contract_sum: Number(p.contract_sum), purchase_price: Number(p.purchase_price),
        partner_pct: Number(p.partner_pct), delivery: Number(p.delivery), commission: Number(p.commission),
    });
    showForm.value = true;
};
const submit = () => (editingId.value
    ? form.put(route('preDeals.update', editingId.value), { preserveScroll: true, onSuccess: () => (showForm.value = false) })
    : form.post(route('preDeals.store'), { preserveScroll: true, onSuccess: () => (showForm.value = false) }));

const confirmDeal = async (p) => {
    if (!(await confirmDialog({ title: 'Подтвердить сделку?', message: `«${p.product}» на ${money(p.contract_sum)} (маржа ${p.margin}%) будет создана на странице «Сделки».`, confirmText: 'Подтвердить' }))) return;
    router.post(route('preDeals.confirm', p.id), {}, { preserveScroll: true });
};
const del = async (p) => {
    if (!(await confirmDialog({ title: 'Удалить лот?', message: `«${p.product}» будет удалён.`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('preDeals.destroy', p.id), { preserveScroll: true });
};

// Чек-лист: раскрытие строки + галочки.
const expanded = ref(null);
const checked = (p, item) => !!(p.checks ?? {})[String(item.id)];
const checkedCount = (p) => props.items.filter((i) => checked(p, i)).length;
const toggleCheck = (p, item) => router.post(route('preDeals.check', [p.id, item.id]), {}, { preserveScroll: true });

// Управление чек-листом (админ/финансист).
const showItems = ref(false);
const newItem = ref('');
const itemNames = ref({});
const openItems = () => { itemNames.value = Object.fromEntries(props.items.map((i) => [i.id, i.label])); showItems.value = true; };
const addItem = () => {
    if (!newItem.value.trim()) return;
    router.post(route('preDealItems.store'), { label: newItem.value.trim() }, { preserveScroll: true, onSuccess: () => (newItem.value = '') });
};
const saveItem = (i) => {
    const label = (itemNames.value[i.id] ?? '').trim();
    if (!label || label === i.label) return;
    router.put(route('preDealItems.update', i.id), { label }, { preserveScroll: true });
};
const delItem = async (i) => {
    if (!(await confirmDialog({ title: `Удалить пункт «${i.label}»?`, confirmText: 'Удалить', danger: true }))) return;
    router.delete(route('preDealItems.destroy', i.id), { preserveScroll: true });
};

const marginClass = (m) => Number(m) >= (props.minMargin ?? 15)
    ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700';
</script>

<template>
    <Head title="Предварительные сделки" />
    <AppLayout>
        <template #header>Предварительные сделки</template>

        <!-- Рейтинг менеджеров (руководству) -->
        <div v-if="leadership && stats?.length" class="mb-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">Рейтинг менеджеров <span class="font-normal text-slate-400">— по подтверждённым лотам</span></div>
            <div class="flex gap-3 overflow-x-auto px-5 py-3">
                <div v-for="(m, i) in stats" :key="m.name" class="flex min-w-56 flex-shrink-0 items-center gap-3 rounded-xl border p-3"
                    :class="i === 0 ? 'border-amber-200 bg-amber-50/60' : 'border-slate-100 bg-slate-50'">
                    <span class="text-lg font-bold" :class="i === 0 ? '' : 'text-slate-300'">{{ i === 0 ? '👑' : i + 1 }}</span>
                    <Avatar :name="m.name" :src="m.avatar" :size="36" />
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">{{ m.name }}</div>
                        <div class="text-xs text-slate-500">подтв. <b>{{ m.confirmed }}</b> из {{ m.total }} · <b class="tabular-nums">{{ money(m.sum) }}</b></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Панель: фильтры + действия -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <PrimaryButton @click="openCreate">+ Предв. сделка</PrimaryButton>
            <button v-if="canManageChecklist" @click="openItems"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 shadow-sm transition hover:bg-slate-50">⚙ Чек-лист</button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <select v-if="leadership" v-model="managerF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm">
                    <option value="">Все менеджеры</option>
                    <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <select v-model="statusF" @change="applyFilters" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm">
                    <option value="">Все статусы</option>
                    <option value="new">В работе</option>
                    <option value="confirmed">Подтверждённые</option>
                </select>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500">порог маржи: <b>{{ minMargin }}%</b></span>
            </div>
        </div>

        <!-- Таблица как Excel -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">№ лота</th>
                            <th class="px-4 py-2.5">Заказчик · товар</th>
                            <th v-if="leadership" class="px-4 py-2.5">Менеджер</th>
                            <th class="px-4 py-2.5 text-right">Сумма договора</th>
                            <th class="px-4 py-2.5 text-right">Закуп</th>
                            <th class="px-4 py-2.5 text-right">Партнёр</th>
                            <th class="px-4 py-2.5 text-right">Доставка</th>
                            <th class="px-4 py-2.5 text-right">Комиссия</th>
                            <th class="px-4 py-2.5 text-right">Налог</th>
                            <th class="px-4 py-2.5 text-right">Остаток</th>
                            <th class="px-4 py-2.5 text-center">Маржа</th>
                            <th class="px-4 py-2.5 text-center">Участвую</th>
                            <th class="px-4 py-2.5 text-center">Чек-лист</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="p in preDeals" :key="p.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 text-slate-500">{{ p.lot_number || '—' }}<span class="block text-[10px] text-slate-300">{{ formatDate(p.created_at) }}</span></td>
                                <td class="max-w-56 px-4 py-3">
                                    <div class="truncate font-medium text-slate-800" :title="p.customer">{{ p.customer || '—' }}<span v-if="p.bin" class="text-xs text-slate-400"> · {{ p.bin }}</span></div>
                                    <div class="truncate text-xs text-slate-500" :title="p.product">{{ p.product }}</div>
                                </td>
                                <td v-if="leadership" class="px-4 py-3 text-xs text-slate-500">{{ p.user?.name ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">{{ money(p.contract_sum) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.purchase_price) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.partner_sum) }}<span class="block text-[10px] text-slate-400">{{ Number(p.partner_pct) }}%</span></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.delivery) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.commission) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ money(p.tax) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="Number(p.remainder) >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(p.remainder) }}</td>
                                <td class="px-4 py-3 text-center"><span class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="marginClass(p.margin)">{{ Number(p.margin) }}%</span></td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-md px-2.5 py-1 text-xs font-bold" :class="Number(p.margin) >= minMargin ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'">{{ Number(p.margin) >= minMargin ? 'да' : 'нет' }}</span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button @click="expanded = expanded === p.id ? null : p.id"
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold transition"
                                        :class="checkedCount(p) === items.length && items.length ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                                        ☑ {{ checkedCount(p) }}/{{ items.length }}</button>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <template v-if="p.status === 'confirmed'">
                                        <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">→ {{ p.deal?.number ?? 'сделка' }}</span>
                                    </template>
                                    <template v-else>
                                        <button v-if="Number(p.margin) >= minMargin" @click="confirmDeal(p)"
                                            class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">Подтвердить</button>
                                        <span v-else class="text-[11px] text-rose-400" title="Маржа ниже порога — сделка отклонена">отклонена</span>
                                        <button class="ml-1 rounded p-1 text-slate-300 transition hover:text-indigo-600" title="Изменить" @click="openEdit(p)">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                        </button>
                                        <button class="rounded p-1 text-slate-300 transition hover:text-rose-600" title="Удалить" @click="del(p)">✕</button>
                                    </template>
                                </td>
                            </tr>
                            <!-- Чек-лист + контакт клиента -->
                            <tr v-if="expanded === p.id" class="bg-slate-50/60">
                                <td :colspan="leadership ? 14 : 13" class="px-6 py-3">
                                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                        <label v-for="i in items" :key="i.id" class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" :checked="checked(p, i)" @change="toggleCheck(p, i)"
                                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" />
                                            {{ i.label }}
                                        </label>
                                        <span v-if="p.client_name || p.client_phone" class="ml-auto text-sm text-slate-500">
                                            Клиент: <b class="text-slate-700">{{ p.client_name || '—' }}</b>
                                            <a v-if="p.client_phone" :href="'tel:' + p.client_phone" class="ml-2 font-semibold text-indigo-600 hover:underline">{{ p.client_phone }}</a>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!preDeals.length"><td :colspan="leadership ? 14 : 13" class="px-6 py-10 text-center text-slate-400">Пока нет предварительных сделок — «+ Предв. сделка»</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Модалка лота: живой расчёт -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <div class="p-6">
                <h3 class="mb-4 text-base font-semibold text-slate-900">{{ editingId ? 'Изменить предварительную сделку' : 'Новая предварительная сделка' }}</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><InputLabel value="№ лота" /><TextInput v-model="form.lot_number" class="mt-1 w-full" /></div>
                    <div><InputLabel value="БИН заказчика" /><TextInput v-model="form.bin" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Заказчик (компания)" /><TextInput v-model="form.customer" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Название товара *" /><TextInput v-model="form.product" class="mt-1 w-full" /><div v-if="form.errors.product" class="mt-1 text-xs text-rose-600">{{ form.errors.product }}</div></div>
                    <div><InputLabel value="Имя клиента (контакт)" /><TextInput v-model="form.client_name" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Телефон клиента" /><TextInput v-model="form.client_phone" class="mt-1 w-full" placeholder="+7 ___ ___ __ __" /></div>
                    <div><InputLabel value="Сумма договора *" /><TextInput v-model="form.contract_sum" type="number" min="1" class="mt-1 w-full" /><div v-if="form.errors.contract_sum" class="mt-1 text-xs text-rose-600">{{ form.errors.contract_sum }}</div></div>
                    <div><InputLabel value="Закуп цена" /><TextInput v-model="form.purchase_price" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Доля партнёра, %" /><TextInput v-model="form.partner_pct" type="number" min="0" max="100" step="0.1" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Доставка, грузчики" /><TextInput v-model="form.delivery" type="number" min="0" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Комиссия (ГЗ, Омаркет, Самрук)" /><TextInput v-model="form.commission" type="number" min="0" class="mt-1 w-full" /></div>
                </div>

                <!-- Живой расчёт -->
                <div class="mt-4 rounded-xl border p-4" :class="calc.pass ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60'">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                        <span class="text-slate-500">Партнёр: <b class="tabular-nums text-slate-700">{{ money(calc.partner) }}</b></span>
                        <span class="text-slate-500">Налог {{ taxPercent }}%: <b class="tabular-nums text-slate-700">{{ money(calc.tax) }}</b></span>
                        <span class="text-slate-500">Остаток: <b class="tabular-nums" :class="calc.remainder >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(calc.remainder) }}</b></span>
                        <span class="ml-auto flex items-center gap-2">
                            <span class="rounded-full px-3 py-1 text-sm font-bold tabular-nums" :class="calc.pass ? 'bg-emerald-500 text-white' : 'bg-rose-500 text-white'">маржа {{ calc.margin }}%</span>
                            <span class="text-xs font-semibold" :class="calc.pass ? 'text-emerald-700' : 'text-rose-600'">{{ calc.pass ? 'участвую — да' : 'ниже ' + minMargin + '% — отклоняется' }}</span>
                        </span>
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <SecondaryButton @click="showForm = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">{{ editingId ? 'Сохранить' : 'Добавить' }}</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Настройка чек-листа (админ/финансист) -->
        <Modal :show="showItems" max-width="md" @close="showItems = false">
            <div class="p-6">
                <h3 class="mb-1 text-base font-semibold text-slate-900">Чек-лист предварительной сделки</h3>
                <p class="mb-4 text-xs text-slate-400">Пункты видят все менеджеры. Переименование — Enter или клик мимо, ✕ — удалить.</p>
                <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                    <div v-for="i in items" :key="i.id" class="flex items-center gap-2">
                        <input v-model="itemNames[i.id]" @keyup.enter="saveItem(i)" @blur="saveItem(i)" type="text"
                            class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <button @click="delItem(i)" class="rounded p-1.5 text-slate-300 transition hover:text-rose-600" title="Удалить пункт">✕</button>
                    </div>
                    <div v-if="!items.length" class="py-4 text-center text-sm text-slate-400">Пунктов пока нет</div>
                </div>
                <div class="mt-4 flex gap-2">
                    <input v-model="newItem" @keyup.enter="addItem" type="text" placeholder="Новый пункт…"
                        class="flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <PrimaryButton type="button" @click="addItem">Добавить</PrimaryButton>
                </div>
                <div class="mt-4 text-right"><SecondaryButton @click="showItems = false">Закрыть</SecondaryButton></div>
            </div>
        </Modal>
    </AppLayout>
</template>
