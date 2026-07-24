<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money, formatDate, formatDateTime } from '@/utils/format';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ rows: Array, leadership: Boolean, canManage: Boolean, month: String, taxRate: Number, totals: Object });
const me = props.rows[0] ?? null;

const open = ref(new Set());
const toggle = (uid) => { const s = new Set(open.value); s.has(uid) ? s.delete(uid) : s.add(uid); open.value = s; };

// Месяц корректировок (отгулы/больничные/штрафы) — серверный фильтр.
const monthSel = ref(props.month);
const setMonth = () => router.get(route('payroll.index'), { month: monthSel.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });

const typeLabels = { absence: 'Отгул', sick: 'Больничный', fine: 'Штраф', advance: 'Аванс', bonus: 'Премия' };
// «2026-07» → «июль 2026» для заголовков.
const monthLabel = new Date(props.month + '-01').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
const typeClass = (t) => t === 'bonus' ? 'bg-emerald-100 text-emerald-700' : t === 'fine' ? 'bg-rose-100 text-rose-700' : t === 'advance' ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700';

// Оклад: инлайн-правка (бухгалтер/админ).
const editingSalary = ref(null);
const salaryVal = ref('');
const editSalary = (r) => { editingSalary.value = r.uid; salaryVal.value = Number(r.salary) || ''; };
const saveSalary = (r) => router.patch(route('payroll.salary', r.uid), { salary: Number(salaryVal.value) || 0 }, {
    preserveScroll: true, onSuccess: () => (editingSalary.value = null),
});

// Корректировка: отгул/больничный — днями (сумма авто = оклад/22 × дни) или суммой.
const showAdj = ref(false);
const adjForm = useForm({ user_id: '', type: 'absence', days: '', amount: '', date: new Date().toISOString().slice(0, 10), note: '' });
const openAdj = (uid = '') => { adjForm.reset(); adjForm.user_id = uid; adjForm.date = new Date().toISOString().slice(0, 10); showAdj.value = true; };
const submitAdj = () => adjForm.post(route('payroll.adjustments.store'), { preserveScroll: true, onSuccess: () => (showAdj.value = false) });
const delAdj = async (a) => {
    if (await confirmDialog({ title: 'Удалить корректировку', message: `«${typeLabels[a.type]} ${money(a.amount)}» будет удалена.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('payroll.adjustments.destroy', a.id), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Зарплата" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $t('page.payroll', 'Зарплата и бонусы') }}</span>
                <div class="flex items-center gap-2">
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">месяц
                        <input v-model="monthSel" @change="setMonth" type="month" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <button v-if="canManage" @click="openAdj()"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">+ Корректировка</button>
                </div>
            </div>
        </template>

        <!-- Manager: слева выплата/корректировки/сделки, справа — шкала бонусов -->
        <div v-if="!leadership" class="grid max-w-5xl grid-cols-1 items-start gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl bg-white p-6 shadow-sm border border-slate-200">
                <div class="text-xs uppercase text-slate-400">К выплате · {{ monthLabel }}</div>
                <div class="mt-1 text-3xl font-bold text-green-600">{{ money(me?.final ?? me?.payout ?? 0) }}</div>
                <div class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Оклад</span><span class="font-medium tabular-nums">{{ money(me?.salary ?? 0) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Бонус по марже сделок</span><span class="font-medium tabular-nums text-emerald-600">{{ money(me?.bonus ?? 0) }}</span></div>
                    <div v-if="me?.deductions" class="flex justify-between"><span class="text-slate-500">Удержания (отгул/больничный/штраф/аванс)</span><span class="font-medium tabular-nums text-rose-600">− {{ money(me.deductions) }}</span></div>
                    <div v-if="me?.additions" class="flex justify-between"><span class="text-slate-500">Премии</span><span class="font-medium tabular-nums text-emerald-600">+ {{ money(me.additions) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Успешных сделок</span><span class="font-medium">{{ me?.closed ?? 0 }}</span></div>
                </div>
            </div>

            <!-- Корректировки за месяц -->
            <div v-if="me?.adjustments?.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Корректировки · {{ monthLabel }}</div>
                <div class="divide-y divide-slate-50 text-sm">
                    <div v-for="a in me.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-2">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                            <span class="text-xs text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} дн.</template><template v-if="a.note"> · {{ a.note }}</template> · внесено {{ formatDateTime(a.created_at) }}</span>
                        </div>
                        <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="me?.dealsList?.length" class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                        <tr><th class="px-3 py-2">Сделка</th><th class="px-3 py-2">Этап</th><th class="px-3 py-2 text-right">Сумма</th><th class="px-3 py-2 text-right">Оплачено</th><th class="px-3 py-2 text-right text-emerald-600">Бонус</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="d in me.dealsList" :key="d.id" class="hover:bg-slate-50">
                            <td class="px-3 py-2"><Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link> <span class="text-slate-400">{{ d.number }}</span></td>
                            <td class="px-3 py-2"><span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                {{ money(d.bonus) }}
                                <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="'Ручной % финансиста: ' + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>

            <!-- Правая колонка: система бонусов (шкала по марже сделки) -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:sticky lg:top-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Система бонусов — по марже сделки</div>
                <div class="mt-3 space-y-1.5 text-sm">
                    <div v-for="t in [
                            { m: 'до 10%', b: 'бонуса нет', muted: true },
                            { m: '11% – 15%', b: '5% от остатка' },
                            { m: '16% – 20%', b: '7% от остатка' },
                            { m: '21% – 30%', b: '10% от остатка' },
                            { m: '31% – 40%', b: '13% от остатка' },
                            { m: 'от 41%', b: '15% от остатка' },
                        ]" :key="t.m" class="flex items-center justify-between rounded-lg px-3 py-1.5"
                        :class="t.muted ? 'bg-slate-50 text-slate-400' : 'bg-emerald-50/50'">
                        <span :class="t.muted ? '' : 'text-slate-600'">маржа {{ t.m }}</span>
                        <span class="font-semibold tabular-nums" :class="t.muted ? '' : 'text-emerald-700'">{{ t.b }}</span>
                    </div>
                </div>
                <p class="mt-3 text-[11px] text-slate-400">Маржа = (сумма договора − расходы) / сумма договора. Остаток = сумма − налог − расходы.</p>
            </div>
        </div>

        <!-- Leadership: everyone -->
        <template v-else>
            <!-- 2 ряда по 4 плитки: суммам хватает места, без переносов -->
            <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Сумма договоров</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-slate-900 xl:text-xl">{{ money(totals.budget) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Налог {{ taxRate }}%</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-rose-600 xl:text-xl">−{{ money(totals.tax) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Расходы</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-rose-600 xl:text-xl">−{{ money(totals.expense) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Бонусы (по марже)</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-600 xl:text-xl">{{ money(totals.bonus) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Оклады</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-slate-700 xl:text-xl">{{ money(totals.salary) }}</div></div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Удержания / премии</div>
                    <div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums xl:text-xl" :class="totals.deductions > 0 ? 'text-rose-600' : 'text-slate-300'">
                        <template v-if="totals.deductions > 0">−{{ money(totals.deductions) }}</template>
                        <template v-else>—</template>
                        <span v-if="totals.additions > 0" class="text-sm text-emerald-600"> +{{ money(totals.additions) }}</span>
                    </div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm"><div class="truncate text-[11px] uppercase tracking-wide text-emerald-600/70">К выплате · {{ monthLabel }}</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums text-emerald-700 xl:text-xl">{{ money(totals.final) }}</div></div>
                <div class="rounded-xl p-4 text-white shadow-md" style="background-color: #1A3B5C"><div class="truncate text-[11px] uppercase tracking-wide text-white/60">Чистая прибыль компании</div><div class="mt-1 whitespace-nowrap text-lg font-semibold tabular-nums xl:text-xl">{{ money(totals.company) }}</div></div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Сотрудник</th>
                            <th class="px-4 py-3">Сделок</th>
                            <th class="px-4 py-3">Успешных</th>
                            <th class="px-4 py-3 text-right">Сумма договоров</th>
                            <th class="px-4 py-3 text-right text-rose-600">Налог {{ taxRate }}%</th>
                            <th class="px-4 py-3 text-right text-rose-600">Расходы</th>
                            <th class="px-4 py-3 text-right text-emerald-600">Бонус</th>
                            <th class="px-4 py-3 text-right">Оклад</th>
                            <th class="px-4 py-3 text-right text-rose-600">Удержания</th>
                            <th class="px-4 py-3 text-right text-emerald-600">К выплате</th>
                            <th class="px-4 py-3 text-right">Чистая прибыль</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <template v-for="r in rows" :key="r.uid">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="toggle(r.uid)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="open.has(r.uid) ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                                        <Avatar :name="r.user" :src="r.avatar" :size="32" />
                                        <span class="font-medium text-slate-900">{{ r.user }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-500">{{ r.deals }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ r.closed }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ money(r.budget) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.tax) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(r.expense) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                                <!-- Оклад: инлайн-правка бухгалтером/админом -->
                                <td class="px-4 py-3 text-right tabular-nums text-slate-700" @click.stop>
                                    <div v-if="editingSalary === r.uid" class="flex items-center justify-end gap-1">
                                        <input v-model="salaryVal" type="number" min="0" class="w-28 rounded-md border-slate-300 py-1 text-right text-xs"
                                            @keydown.enter="saveSalary(r)" @keydown.escape="editingSalary = null" />
                                        <button class="rounded bg-emerald-600 px-1.5 py-1 text-[10px] font-bold text-white" @click="saveSalary(r)">✓</button>
                                    </div>
                                    <button v-else-if="canManage" class="group inline-flex items-center gap-1 hover:text-indigo-600" title="Изменить оклад" @click="editSalary(r)">
                                        {{ money(r.salary) }}
                                        <svg class="h-3 w-3 text-slate-300 group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </button>
                                    <span v-else>{{ money(r.salary) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="r.deductions > 0 ? 'text-rose-600 font-medium' : 'text-slate-300'">
                                    <template v-if="r.deductions > 0">− {{ money(r.deductions) }}</template>
                                    <template v-else>—</template>
                                    <span v-if="r.additions > 0" class="text-emerald-600"> +{{ money(r.additions) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-bold tabular-nums text-emerald-600">{{ money(r.final) }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums" :class="r.company >= 0 ? 'text-slate-900' : 'text-rose-600'">{{ money(r.company) }}</td>
                            </tr>
                            <tr v-if="open.has(r.uid)" class="bg-slate-50/60">
                                <td colspan="11" class="px-4 py-3">
                                    <!-- Корректировки сотрудника за месяц -->
                                    <div class="mb-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Корректировки · {{ monthLabel }}</span>
                                            <button v-if="canManage" class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="openAdj(r.uid)">+ добавить</button>
                                        </div>
                                        <div v-if="r.adjustments?.length" class="divide-y divide-slate-50 text-xs">
                                            <div v-for="a in r.adjustments" :key="a.id" class="flex items-center justify-between gap-2 py-1.5">
                                                <div class="flex items-center gap-2">
                                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="typeClass(a.type)">{{ typeLabels[a.type] }}</span>
                                                    <span class="text-slate-400">{{ formatDate(a.date) }}<template v-if="a.days"> · {{ a.days }} дн.</template><template v-if="a.note"> · {{ a.note }}</template><template v-if="a.creator"> · {{ a.creator }}</template> · внесено {{ formatDateTime(a.created_at) }}</span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ a.type === 'bonus' ? '+' : '−' }} {{ money(a.amount) }}</span>
                                                    <button v-if="canManage" class="text-slate-300 hover:text-rose-600" title="Удалить" @click="delAdj(a)">✕</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="py-1 text-xs text-slate-300">Нет корректировок</div>
                                    </div>
                                    <div v-if="r.dealsList && r.dealsList.length" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                        <table class="min-w-full divide-y divide-slate-100 text-xs">
                                            <thead class="text-left uppercase tracking-wide text-slate-400">
                                                <tr>
                                                    <th class="px-3 py-2">Сделка</th>
                                                    <th class="px-3 py-2">Этап</th>
                                                    <th class="px-3 py-2 text-right">Сумма</th>
                                                    <th class="px-3 py-2 text-right">Оплачено</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">Расходы</th>
                                                    <th class="px-3 py-2 text-right text-rose-600">Налог</th>
                                                    <th class="px-3 py-2 text-right text-emerald-600">Бонус (ЗП)</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-50">
                                                <tr v-for="d in r.dealsList" :key="d.id" class="hover:bg-slate-50">
                                                    <td class="px-3 py-2">
                                                        <Link :href="route('deals.show', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.company }}</Link>
                                                        <span class="ml-1 text-slate-400">{{ d.number }}</span>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <span :class="d.is_won ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'" class="rounded-full px-2 py-0.5 text-[11px] font-medium">{{ d.stage }}</span>
                                                    </td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums" :class="d.paid >= d.budget ? 'text-emerald-600' : 'text-slate-500'">{{ money(d.paid) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.expense) }}</td>
                                                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ money(d.tax) }}</td>
                                                    <td class="px-3 py-2 text-right font-semibold tabular-nums text-emerald-600">
                                                        {{ money(d.bonus) }}
                                                        <span v-if="d.bonus_manual" class="ml-1 rounded bg-amber-100 px-1 py-px text-[9px] font-bold uppercase text-amber-700" :title="'Ручной % финансиста: ' + d.bonus_rate + '%'">{{ d.bonus_rate }}%</span>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                        <p class="px-3 py-2 text-[11px] text-slate-400">🟢 «Оплата успешно» — в ЗП; 🟡 «Акт утверждение» — ожидает оплаты, ещё не в ЗП.</p>
                                    </div>
                                    <div v-else class="py-2 text-center text-xs text-slate-400">Нет сделок на «Оплата успешно» / «Акт утверждение»</div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="11" class="px-4 py-8 text-center text-slate-400">Нет данных</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-400">К выплате = оклад + бонус − удержания (отгул/больничный/штраф/аванс) + премии за выбранный месяц. Отгул/больничный днями: удержание = оклад / 22 × дни. Остаток = сумма договора − налог {{ taxRate }}% − расходы. Бонус по марже сделки (остаток/сумма), выплачивается пропорционально оплаченной доле (оплачено/сумма): до 10% — нет; 11–15% — 5%; 16–20% — 7%; 21–30% — 10%; 31–40% — 13%; от 41% — 15% от остатка. Чистая прибыль компании = остаток − бонус.</p>
        </template>

        <!-- Модалка корректировки -->
        <Modal :show="showAdj" @close="showAdj = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Корректировка ЗП</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сотрудник *</label>
                        <select v-model="adjForm.user_id" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">— выберите —</option>
                            <option v-for="r in rows" :key="r.uid" :value="r.uid">{{ r.user }}</option>
                        </select>
                        <div v-if="adjForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ adjForm.errors.user_id }}</div>
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                        <button v-for="(label, t) in typeLabels" :key="t" type="button" @click="adjForm.type = t"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-all"
                            :class="adjForm.type === t ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">{{ label }}</button>
                    </div>
                    <div v-if="adjForm.type === 'absence' || adjForm.type === 'sick'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дней (сумма = оклад / 22 × дни)</label>
                        <input v-model="adjForm.days" type="number" min="0.5" step="0.5" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.days" class="mt-1 text-xs text-red-600">{{ adjForm.errors.days }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Сумма, ₸ {{ adjForm.type === 'absence' || adjForm.type === 'sick' ? '(или авто по дням)' : '*' }}</label>
                        <input v-model="adjForm.amount" type="number" min="0" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.amount" class="mt-1 text-xs text-red-600">{{ adjForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Дата *</label>
                        <input v-model="adjForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        <div v-if="adjForm.errors.date" class="mt-1 text-xs text-red-600">{{ adjForm.errors.date }}</div>
                    </div>
                    <div :class="adjForm.type === 'absence' || adjForm.type === 'sick' ? '' : 'sm:col-span-2'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Комментарий</label>
                        <input v-model="adjForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="Причина…" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showAdj = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="adjForm.processing || !adjForm.user_id" @click="submitAdj">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
