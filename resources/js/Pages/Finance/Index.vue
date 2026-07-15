<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Avatar from '@/Components/Avatar.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatDate } from '@/utils/format';

const props = defineProps({ invoices: Object, invoiceTotals: Object, expenses: Object, expenseTotals: Object, filters: Object, totals: Object, salaries: Array, summary: Object, categories: Array, canManage: Boolean });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Фильтры раздела «Расходы»: вид (материалы/прочие), оплата (нал/банк),
// статус и период. Период влияет и на сводку-плитки, и на таблицу.
const expKind = ref(props.filters?.exp_kind ?? '');
const expMethod = ref(props.filters?.exp_method ?? '');
const expStatus = ref(props.filters?.exp_status ?? '');
const expFrom = ref(props.filters?.exp_from ?? '');
const expTo = ref(props.filters?.exp_to ?? '');
const applyExpFilters = () => router.get(route('finance.index'), {
    ...props.filters,
    exp_kind: expKind.value || undefined,
    exp_method: expMethod.value || undefined,
    exp_status: expStatus.value || undefined,
    exp_from: expFrom.value || undefined,
    exp_to: expTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });

// Плитки сводки работают как фильтры: клик по «Наличные» фильтрует таблицу.
const setTile = (kind, method, status) => {
    expKind.value = kind; expMethod.value = method; expStatus.value = status;
    applyExpFilters();
};
const tileActive = (kind, method, status) =>
    expKind.value === kind && expMethod.value === method && expStatus.value === status;

// Ссылка на сделку/заказ расхода (морф: deal | project).
const expLink = (e) => e.expenseable_type === 'project'
    ? route('projects.show', e.expenseable_id)
    : route('deals.show', e.expenseable_id);

// Расход КОМПАНИИ (без сделки): аренда, комуслуги, интернет, бензин и т.п.
// Вводит бухгалтер/админ; категория обязательна, статус сразу confirmed.
const showCompanyExpense = ref(false);
const cForm = useForm({ expenseable_type: '', expenseable_id: '', category_id: '', amount: '', date: new Date().toISOString().slice(0, 10), payment_method: 'bank', description: '', status: 'confirmed', file: null });
const openCompanyExpense = () => { cForm.reset(); cForm.date = new Date().toISOString().slice(0, 10); showCompanyExpense.value = true; };
const onCReceipt = (e) => { cForm.file = e.target.files[0] ?? null; };
const submitCompanyExpense = () => cForm.post(route('expenses.store'), {
    preserveScroll: true, forceFormData: true,
    onSuccess: () => (showCompanyExpense.value = false),
});
</script>

<template>
    <Head title="Финансы" />
    <AppLayout>
        <template #header>{{ $t('page.finance', 'Финансы') }}</template>

        <!-- Верхний ряд: договоры · дебиторка · касса · банк -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Общая сумма договоров</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-slate-800">{{ money(summary.contracts) }}</div>
            </div>
            <div class="rounded-xl border p-5 shadow-sm" :class="summary.receivables > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                <div class="text-[11px] uppercase tracking-wide" :class="summary.receivables > 0 ? 'text-rose-500' : 'text-slate-400'">Дебиторская задолженность</div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.receivables > 0 ? 'text-rose-600' : 'text-slate-800'">{{ money(summary.receivables) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Остаток в кассе</div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.cash >= 0 ? 'text-slate-800' : 'text-rose-600'">{{ money(summary.cash) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">наличные: поступило − потрачено</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Остаток в банке</div>
                <div class="mt-1 text-xl font-bold tabular-nums" :class="summary.bank >= 0 ? 'text-slate-800' : 'text-rose-600'">{{ money(summary.bank) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">безнал: поступило − потрачено</div>
            </div>
        </div>

        <!-- Доход − ВСЕ расходы = Чистая прибыль (минимализм, как в тетради) -->
        <div class="mb-6 grid grid-cols-1 gap-3 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] uppercase tracking-wide text-slate-400">Доход</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-emerald-600">{{ money(summary.income) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">все поступления по счетам</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-baseline justify-between">
                    <span class="text-[11px] uppercase tracking-wide text-slate-400">Расходы — всего</span>
                    <span class="text-xl font-bold tabular-nums text-rose-600">−{{ money(summary.expensesTotal) }}</span>
                </div>
                <div class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Зарплата (оклады + бонусы)</span><span class="tabular-nums text-slate-700">{{ money(summary.payroll) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Налог</span><span class="tabular-nums text-slate-700">{{ money(summary.tax) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">По сделкам и цеху</span><span class="tabular-nums text-slate-700">{{ money(summary.dealExpenses) }}</span></div>
                    <div v-for="c in summary.categories" :key="c.name" class="flex justify-between">
                        <span class="text-slate-500">{{ c.name }}</span><span class="tabular-nums text-slate-700">{{ money(c.sum) }}</span>
                    </div>
                </div>
            </div>
            <div class="rounded-xl p-5 shadow-md" style="background-color: #1A3B5C">
                <div class="text-[11px] uppercase tracking-wide text-white/60">Чистая прибыль</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="summary.net >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ money(summary.net) }}</div>
                <div class="mt-0.5 text-[11px] text-white/60">доход − все расходы</div>
            </div>
        </div>

        <!-- ================= Расходы ================= -->
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-6 py-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-semibold text-slate-900">Расходы</h3>
                    <button v-if="canManage" @click="openCompanyExpense"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">+ Расход компании</button>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <label class="flex items-center gap-1 text-xs text-slate-400">с
                        <input v-model="expFrom" @change="applyExpFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </label>
                    <label class="flex items-center gap-1 text-xs text-slate-400">по
                        <input v-model="expTo" @change="applyExpFilters" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </label>
                    <select v-model="expKind" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Все виды</option>
                        <option value="material">Материальные (склад)</option>
                        <option value="other">Прочие</option>
                    </select>
                    <select v-model="expMethod" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Любая оплата</option>
                        <option value="cash">Наличные</option>
                        <option value="bank">Банк (счёт)</option>
                    </select>
                    <select v-model="expStatus" @change="applyExpFilters" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option value="">Все статусы</option>
                        <option value="pending">Ждёт бухгалтера</option>
                        <option value="confirmed">Подтверждён</option>
                    </select>
                </div>
            </div>

            <!-- Сводка по расходам: плитки-фильтры (клик фильтрует таблицу).
                 Нал + банк = прочие: у материальных списаний способа оплаты нет. -->
            <div class="grid grid-cols-2 gap-3 px-6 py-4 lg:grid-cols-5">
                <button type="button" @click="setTile('', '', '')"
                    class="rounded-xl bg-slate-900 p-3 text-left transition hover:opacity-90"
                    :class="tileActive('', '', '') ? 'ring-2 ring-slate-900 ring-offset-2' : ''">
                    <div class="text-[11px] font-medium text-slate-300">Все расходы ({{ expenseTotals.all_count }})</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-white">{{ money(expenseTotals.all) }}</div>
                </button>
                <button type="button" @click="setTile('material', '', 'confirmed')"
                    class="rounded-xl bg-indigo-50 p-3 text-left transition hover:bg-indigo-100"
                    :class="tileActive('material', '', 'confirmed') ? 'ring-2 ring-indigo-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-indigo-700">Материальные (склад)</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-indigo-700">{{ money(expenseTotals.material) }}</div>
                </button>
                <button type="button" @click="setTile('other', 'cash', 'confirmed')"
                    class="rounded-xl bg-emerald-50 p-3 text-left transition hover:bg-emerald-100"
                    :class="tileActive('other', 'cash', 'confirmed') ? 'ring-2 ring-emerald-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-emerald-700">Прочие расходы (нал)</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-emerald-700">{{ money(expenseTotals.cash) }}</div>
                </button>
                <button type="button" @click="setTile('other', 'bank', 'confirmed')"
                    class="rounded-xl bg-sky-50 p-3 text-left transition hover:bg-sky-100"
                    :class="tileActive('other', 'bank', 'confirmed') ? 'ring-2 ring-sky-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-sky-700">Прочие расходы (банк)</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-sky-700">{{ money(expenseTotals.bank) }}</div>
                </button>
                <button type="button" @click="setTile('', '', 'pending')"
                    class="rounded-xl bg-amber-50 p-3 text-left transition hover:bg-amber-100"
                    :class="tileActive('', '', 'pending') ? 'ring-2 ring-amber-400 ring-offset-1' : ''">
                    <div class="text-[11px] font-medium text-amber-700">Ждёт бухгалтера ({{ expenseTotals.pending_count }})</div>
                    <div class="mt-0.5 text-base font-bold tabular-nums text-amber-700">{{ money(expenseTotals.pending_sum) }}</div>
                </button>
            </div>

            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Сумма</th>
                        <th class="px-4 py-3">Описание</th>
                        <th class="px-4 py-3">Сделка / заказ</th>
                        <th class="px-4 py-3">Вид</th>
                        <th class="px-4 py-3">Оплата</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3">Автор</th>
                        <th class="px-4 py-3">Дата</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="e in expenses.data" :key="e.id" class="transition-colors hover:bg-slate-50">
                        <td class="px-6 py-3 font-semibold tabular-nums text-slate-900">{{ money(e.amount) }}</td>
                        <td class="max-w-[220px] truncate px-4 py-3 text-slate-500">{{ e.description || '—' }}</td>
                        <td class="px-4 py-3">
                            <Link v-if="e.expenseable_id" :href="expLink(e)" class="font-medium text-indigo-600 hover:underline">{{ e.expenseable?.number ?? '—' }}</Link>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ e.category?.name ?? 'Компания' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="e.material" class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">склад</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">прочий</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.payment_method === 'cash' ? 'наличные' : (e.payment_method === 'bank' ? 'банк' : '—') }}</td>
                        <td class="px-4 py-3">
                            <span v-if="e.status === 'confirmed'" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Подтверждён</span>
                            <span v-else-if="e.status === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Ждёт бухгалтера</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Черновик</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ e.responsible?.name ?? '—' }}<span v-if="e.confirmed_by?.name" class="block text-[10px] text-slate-400">подтв.: {{ e.confirmed_by.name }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-400">{{ formatDate(e.date) }}</td>
                    </tr>
                    <tr v-if="!expenses.data.length"><td colspan="8" class="px-6 py-10 text-center text-slate-400">Расходов нет</td></tr>
                </tbody>
            </table>
            <div class="p-4"><Pagination :links="expenses.links" /></div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Зарплаты сотрудников -->
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200 lg:col-span-1">
                <h3 class="mb-4 text-sm font-semibold text-slate-900">Зарплаты сотрудников</h3>
                <div class="space-y-2">
                    <div v-for="s in salaries" :key="s.user" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                        <div class="flex items-center gap-2.5">
                            <Avatar :name="s.user" :src="s.avatar" :size="34" />
                            <div>
                                <div class="text-sm font-medium text-slate-800">{{ s.user }}</div>
                                <div class="text-[11px] text-slate-400">маржа {{ s.margin }}%</div>
                            </div>
                        </div>
                        <div class="text-sm font-bold text-green-600">{{ money(s.bonus) }}</div>
                    </div>
                    <div v-if="!salaries.length" class="py-6 text-center text-sm text-slate-400">Нет данных</div>
                </div>
                <div v-if="salaries.length" class="mt-3 flex items-center justify-between border-t pt-3 text-sm">
                    <span class="font-medium text-slate-500">Итого ЗП</span>
                    <span class="font-bold text-amber-600">{{ money(totals.salaries) }}</span>
                </div>
            </div>

            <!-- Счета -->
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm border border-slate-200 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4">
                    <span class="text-sm font-semibold text-slate-900">Счета</span>
                    <!-- Дебиторка: выставлено / оплачено / клиенты должны -->
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 font-medium text-slate-600">выставлено <b class="tabular-nums">{{ money(invoiceTotals.invoiced) }}</b></span>
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 font-medium text-emerald-700">оплачено <b class="tabular-nums">{{ money(invoiceTotals.paid) }}</b></span>
                        <span class="rounded-full px-2.5 py-1 font-medium" :class="invoiceTotals.debt > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-400'">долг клиентов <b class="tabular-nums">{{ money(invoiceTotals.debt) }}</b></span>
                    </div>
                </div>
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Номер</th><th class="px-4 py-3">Клиент</th><th class="px-4 py-3">Сумма</th>
                            <th class="px-4 py-3">Оплачено</th><th class="px-4 py-3">Статус</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">{{ inv.number }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ inv.client?.name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ money(inv.amount) }}</td>
                            <td class="px-4 py-3 text-green-600">{{ money(inv.payments_sum_amount ?? 0) }}</td>
                            <td class="px-4 py-3"><StatusBadge :status="inv.status" /></td>
                        </tr>
                        <tr v-if="!invoices.data.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">Счетов нет</td></tr>
                    </tbody>
                </table>
                <div class="p-4"><Pagination :links="invoices.links" /></div>
            </div>
        </div>

        <!-- Модалка: расход компании (аренда, комуслуги, интернет, бензин…) -->
        <Modal :show="showCompanyExpense" @close="showCompanyExpense = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">Расход компании</h2>
                <p class="mb-4 text-xs text-slate-400">Не по сделке: аренда, комуслуги, интернет, бензин, канцтовары… Подтверждается сразу.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Категория *</label>
                        <select v-model="cForm.category_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">— выберите —</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <div v-if="cForm.errors.category_id" class="mt-1 text-xs text-red-600">{{ cForm.errors.category_id }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сумма, ₸ *</label>
                        <input v-model="cForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="cForm.errors.amount" class="mt-1 text-xs text-red-600">{{ cForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дата *</label>
                        <input v-model="cForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                    </div>
                    <div class="sm:col-span-2 flex gap-2">
                        <button type="button" @click="cForm.payment_method = 'cash'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="cForm.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500'">Наличные</button>
                        <button type="button" @click="cForm.payment_method = 'bank'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="cForm.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700 ring-1 ring-sky-500' : 'border-slate-200 bg-white text-slate-500'">Банк (счёт)</button>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Описание</label>
                        <input v-model="cForm.description" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="За что…" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Чек / квитанция (фото или PDF, необязательно)</label>
                        <input type="file" accept="image/*,.pdf" @change="onCReceipt"
                            class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                        <div v-if="cForm.errors.file" class="mt-1 text-xs text-red-600">{{ cForm.errors.file }}</div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showCompanyExpense = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="cForm.processing || !cForm.category_id || !(Number(cForm.amount) > 0)" @click="submitCompanyExpense">Сохранить расход</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
