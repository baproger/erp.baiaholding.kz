<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ResetFiltersButton from '@/Components/ResetFiltersButton.vue';
import { money } from '@/utils/format';

// Финансы → «Долг сделкам» (ТЗ 02.11.2026, п.7): долги поставщикам из расходов
// «в долг» — сгруппированы по сделке, итог «Всего должны». Гасит бухгалтер/админ.
const props = defineProps({
    groups: { type: Array, default: () => [] },
    totals: Object,
    filters: Object,
    canRepay: Boolean,
    balances: { type: Object, default: null },
});

const search = ref(props.filters?.search ?? '');
const supplier = ref(props.filters?.supplier ?? '');
const status = ref(props.filters?.status ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const apply = () => router.get(route('supplierDebts.index'), {
    search: search.value || undefined, supplier: supplier.value || undefined, status: status.value || undefined,
    from: from.value || undefined, to: to.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const reset = () => { search.value = ''; supplier.value = ''; status.value = ''; from.value = ''; to.value = ''; apply(); };
const hasFilters = () => search.value || supplier.value || status.value || from.value || to.value;

const fmtDay = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('.') : '—');
const stateMeta = {
    active: { label: 'Активен', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
    overdue: { label: 'Просрочен', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
    paid: { label: 'Погашен', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
};

// «Погасить»: выплата из кассы/банка с чеком (полностью или частично).
const repaying = ref(null);
const today = () => new Date().toISOString().slice(0, 10);
const repayForm = useForm({ amount: '', payment_method: 'bank', date: today(), file: null, note: '' });
const openRepay = (row) => { repayForm.reset(); repayForm.clearErrors(); repayForm.amount = row.remaining; repayForm.date = today(); repaying.value = row; };
const onFile = (e) => { repayForm.file = e.target.files[0] ?? null; };
const submitRepay = () => repayForm.post(route('supplierDebts.repay', repaying.value.id), {
    preserveScroll: true, forceFormData: true, onSuccess: () => (repaying.value = null),
});
</script>

<template>
    <Head title="Долг сделкам" />
    <AppLayout>
        <template #header>{{ $t('page.finance', 'Финансы') }}</template>
        <FinanceLayout title="Долг сделкам" subtitle="долги поставщикам по сделкам — из расходов «в долг»" active="supplierDebts.index">
            <template #actions>
                <input v-model="search" @keyup.enter="apply" @blur="apply" type="search" placeholder="Сделка: № или заказчик"
                    class="w-48 rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <input v-model="supplier" @keyup.enter="apply" @blur="apply" type="search" placeholder="Поставщик"
                    class="w-40 rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <select v-model="status" @change="apply" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm">
                    <option value="">Все статусы</option>
                    <option value="active">Активные</option>
                    <option value="overdue">Просроченные</option>
                    <option value="paid">Погашенные</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-400">с <input v-model="from" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" /></label>
                <label class="flex items-center gap-1 text-xs text-slate-400">по <input v-model="to" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm" /></label>
                <ResetFiltersButton v-if="hasFilters()" @click="reset" />
            </template>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl p-4 shadow-md" style="background-color:#1A3B5C">
                    <div class="text-[11px] uppercase tracking-wide text-white/60">Всего должны</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-amber-300">{{ money(totals.owed) }}</div>
                    <div class="mt-0.5 text-[11px] text-white/50">долгов: {{ totals.count }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Из них просрочено</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="totals.overdue > 0 ? 'text-rose-600' : 'text-slate-300'">{{ totals.overdue > 0 ? money(totals.overdue) : '—' }}</div>
                </div>
                <div v-if="balances" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Доступно для погашения</div>
                    <div class="mt-1 text-sm tabular-nums text-slate-700">касса <b>{{ money(balances.cash) }}</b> · счёт <b>{{ money(balances.bank) }}</b></div>
                </div>
            </div>

            <div v-for="g in groups" :key="g.deal?.id ?? 0" class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50 px-5 py-3">
                    <Link v-if="g.deal" :href="route('deals.show', g.deal.id)" class="font-semibold text-indigo-600 hover:underline">{{ g.deal.number }}</Link>
                    <span class="text-sm text-slate-700">{{ g.deal?.customer ?? 'Без сделки' }}</span>
                    <span class="ml-auto text-xs text-slate-500">долг {{ money(g.amount) }} · оплачено {{ money(g.paid) }} ·
                        <b :class="g.remaining > 0 ? 'text-rose-600' : 'text-emerald-600'">остаток {{ money(g.remaining) }}</b></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-5 py-2">Поставщик</th><th class="px-3 py-2">Материал / описание</th>
                                <th class="px-3 py-2 text-right">Долг</th><th class="px-3 py-2 text-right">Оплачено</th><th class="px-3 py-2 text-right">Остаток</th>
                                <th class="px-3 py-2">Срок</th><th class="px-3 py-2">Статус</th><th class="px-3 py-2">Документ</th><th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="r in g.rows" :key="r.id">
                                <tr>
                                    <td class="px-5 py-2.5"><div class="font-medium text-slate-800">{{ r.supplier }}</div><div class="text-xs text-slate-400">{{ r.contact }}</div></td>
                                    <td class="px-3 py-2.5 text-slate-600">{{ r.description || '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">{{ money(r.amount) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-emerald-600">{{ r.paid > 0 ? money(r.paid) : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums" :class="r.remaining > 0 ? 'text-rose-600' : 'text-slate-300'">{{ r.remaining > 0 ? money(r.remaining) : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 tabular-nums" :class="r.state === 'overdue' ? 'font-semibold text-rose-600' : 'text-slate-600'">{{ fmtDay(r.due_date) }}</td>
                                    <td class="px-3 py-2.5"><span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1" :class="stateMeta[r.state].cls">{{ stateMeta[r.state].label }}</span></td>
                                    <td class="px-3 py-2.5"><a v-if="r.has_document" :href="route('supplierDebts.file', r.id)" target="_blank" class="text-xs font-semibold text-indigo-600 hover:underline">счёт/накладная</a><span v-else class="text-xs text-slate-300">—</span></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right">
                                        <button v-if="canRepay && r.state !== 'paid'" @click="openRepay(r)"
                                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">Погасить</button>
                                    </td>
                                </tr>
                                <tr v-for="p in r.payments" :key="'p' + p.id" class="bg-emerald-50/40 text-xs text-slate-500">
                                    <td class="px-5 py-1.5" colspan="3">↳ погашение {{ fmtDay(p.date) }} · {{ p.method === 'cash' ? 'наличные' : 'банк' }} · {{ p.by ?? '—' }}<template v-if="p.note"> · {{ p.note }}</template></td>
                                    <td class="px-3 py-1.5 text-right font-semibold tabular-nums text-emerald-600">{{ money(p.amount) }}</td>
                                    <td colspan="3"></td>
                                    <td class="px-3 py-1.5"><a :href="route('supplierDebts.file', [r.id, p.id])" target="_blank" class="font-semibold text-emerald-600 hover:underline">чек</a></td>
                                    <td></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!groups.length" class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-400">
                Долгов нет. Они появляются сами, когда в сделке вносят расход «🧾 В долг».
            </div>
        </FinanceLayout>

        <Modal :show="!!repaying" max-width="md" @close="repaying = null">
            <div v-if="repaying" class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Погасить долг — {{ repaying.supplier }}</h2>
                <p class="mt-1 text-sm text-slate-500">Остаток {{ money(repaying.remaining) }}. Можно частично. Выплата уменьшит кассу или счёт; без чека не проводится.</p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div><label class="text-xs text-slate-500">Сумма *</label><input v-model="repayForm.amount" type="number" min="1" step="0.01" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" /></div>
                    <div><label class="text-xs text-slate-500">Дата *</label><input v-model="repayForm.date" type="date" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" /></div>
                    <div class="col-span-2 flex gap-2">
                        <button type="button" @click="repayForm.payment_method = 'cash'" class="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                            :class="repayForm.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-100 text-emerald-700' : 'border-slate-200 text-slate-500'">💵 Наличные</button>
                        <button type="button" @click="repayForm.payment_method = 'bank'" class="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                            :class="repayForm.payment_method === 'bank' ? 'border-sky-500 bg-sky-100 text-sky-700' : 'border-slate-200 text-slate-500'">🏦 Банк</button>
                    </div>
                    <div class="col-span-2"><label class="text-xs text-slate-500">Чек оплаты * (фото или PDF)</label><input type="file" accept="image/*,.pdf" @change="onFile" class="mt-1 block w-full text-sm" /></div>
                    <div class="col-span-2"><input v-model="repayForm.note" placeholder="Комментарий" class="w-full rounded-md border-slate-300 text-sm shadow-sm" /></div>
                </div>
                <div v-if="Object.keys(repayForm.errors).length" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    <div v-for="(err, k) in repayForm.errors" :key="k">{{ err }}</div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="repaying = null">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="repayForm.processing || !(Number(repayForm.amount) > 0) || !repayForm.file" @click="submitRepay">Погасить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
