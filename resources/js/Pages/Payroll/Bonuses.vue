<script setup>
// «Бонусы» — годовая таблица накоплений: менеджеры × 12 месяцев.
// Менеджеры иногда не берут бонус месяцами и копят — поэтому главная цифра
// «К выплате» = накопленный баланс за всё время (заработано − выплачено).
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { money } from '@/utils/format';
import { useStickyFilters } from '@/composables/useStickyFilters';

const props = defineProps({ rows: Array, year: Number, leadership: Boolean, canManage: Boolean, totals: Object });

const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const MONTHS_FULL = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
const thisYear = new Date().getFullYear();
const thisMonth = new Date().getMonth() + 1;

// Фильтр — год (по умолчанию текущий), запоминается за страницей.
const yearSel = ref(props.year);
const years = computed(() => { const a = []; for (let y = thisYear + 1; y >= 2024; y--) a.push(y); return a; });
const setYear = () => router.get(route('payroll.bonuses'), { year: yearSel.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('payroll-bonuses-year', { yearSel }, setYear);

// Разбивка «переноса»: клик по строчке под именем → модалка, из каких
// сделок (до 1 января выбранного года) и выплат сложилась сумма.
const carryModal = ref(false);
const carryFor = ref(null);
const carryData = ref(null);
const carryLoading = ref(false);
const openCarry = async (r) => {
    carryFor.value = r;
    carryData.value = null;
    carryModal.value = true;
    carryLoading.value = true;
    try {
        const res = await fetch(route('payroll.bonuses.carry', { user: r.uid, year: props.year }), { headers: { Accept: 'application/json' } });
        carryData.value = await res.json();
    } catch (e) {
        carryData.value = { error: true };
    } finally {
        carryLoading.value = false;
    }
};

const search = ref('');
const list = computed(() => props.rows
    .filter((r) => (r.year_earned || 0) !== 0 || (r.year_paid || 0) !== 0 || (r.balance || 0) !== 0)
    .filter((r) => !search.value.trim() || (r.user ?? '').toLowerCase().includes(search.value.trim().toLowerCase())));
const me = computed(() => props.rows[0] ?? null);

// Полная сумма в ячейке месяца без знака валюты (он в легенде): 288 000.
const short = (v) => { const n = Math.round(Number(v || 0)); return n ? n.toLocaleString('ru-RU') : ''; };
const isFuture = (m) => props.year > thisYear || (props.year === thisYear && m > thisMonth);
const isCurrent = (m) => props.year === thisYear && m === thisMonth;
const cellTitle = (r, c) => `${MONTHS_FULL[c.m - 1]} ${props.year}: заработано ${money(c.bonus)}${c.paid ? ' · выплачено ' + money(c.paid) : ''}`;
const colSum = (m, key) => list.value.reduce((n, r) => n + (r.months[m - 1]?.[key] || 0), 0);

// 💵 Выплатить бонус: реальная выдача денег из накопленного (тип «Выплата», источник «бонус»).
// Сумма по умолчанию — весь остаток «К выплате» сотрудника; можно выдать часть.
const showPay = ref(false);
const payForm = useForm({ user_id: '', type: 'payout', source: 'bonus', amount: '', date: new Date().toISOString().slice(0, 10), payment_method: 'cash', note: '' });
const payRow = computed(() => props.rows.find((r) => String(r.uid) === String(payForm.user_id)));
const openPay = (uid = '') => {
    payForm.reset(); payForm.type = 'payout'; payForm.source = 'bonus';
    payForm.date = new Date().toISOString().slice(0, 10); payForm.user_id = uid;
    const r = props.rows.find((x) => String(x.uid) === String(uid));
    payForm.amount = r && r.balance > 0 ? r.balance : '';
    showPay.value = true;
};
const onPayUser = () => { if (payRow.value && payRow.value.balance > 0) payForm.amount = payRow.value.balance; };
const submitPay = () => payForm.post(route('payroll.adjustments.store'), { preserveScroll: true, onSuccess: () => (showPay.value = false) });
</script>

<template>
    <Head title="Бонусы" />
    <AppLayout>
        <template #header><span class="truncate">Бонусы</span></template>
        <FinanceLayout title="Бонусы" :subtitle="`накопление по месяцам ${year} · к выплате — баланс за всё время`" active="payroll.bonuses" :wide="true">
            <template #actions>
                <label class="flex items-center gap-1 text-xs font-normal text-slate-400">год
                    <select v-model.number="yearSel" @change="setYear" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </label>
                <input v-if="leadership" v-model="search" type="search" placeholder="Поиск по сотруднику…"
                    class="w-44 rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <button v-if="canManage" @click="openPay()"
                    class="whitespace-nowrap rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-emerald-700">💵 Выплатить бонус</button>
            </template>

            <!-- Плитки: за год заработано / выплачено / К ВЫПЛАТЕ (накоплено за всё время) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Заработано за {{ year }}</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ money(leadership ? totals.year_earned : (me?.year_earned ?? 0)) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="truncate text-[11px] uppercase tracking-wide text-slate-400">Выплачено за {{ year }}</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="(leadership ? totals.year_paid : me?.year_paid) > 0 ? 'text-rose-600' : 'text-slate-300'">{{ money(leadership ? totals.year_paid : (me?.year_paid ?? 0)) }}</div>
                    <div class="mt-0.5 text-[11px] text-slate-400">авансы из бонуса + погашение долгов</div>
                </div>
                <div class="rounded-xl p-4 shadow-md" style="background-color:#1A3B5C">
                    <div class="truncate text-[11px] uppercase tracking-wide text-white/60">К выплате · накоплено за всё время</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-300">{{ money(leadership ? totals.balance : (me?.balance ?? 0)) }}</div>
                    <div class="mt-0.5 text-[11px] text-white/50">заработано − выплачено, с переносом с прошлых лет</div>
                </div>
            </div>

            <!-- Таблица: сотрудник × 12 месяцев -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Бонусы по месяцам · {{ year }}
                        <span class="ml-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ list.length }} сотр.</span>
                    </h3>
                    <span class="text-[11px] text-slate-400">суммы в ₸ · <span class="font-semibold text-emerald-700">заработано</span> · <span class="text-rose-500">−выплачено</span> · пусто — бонуса не было</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr class="divide-x divide-slate-100">
                                <th class="sticky left-0 z-10 bg-slate-50 px-6 py-2.5">Сотрудник</th>
                                <th v-for="(mn, i) in MONTHS" :key="mn" class="px-2.5 py-2.5 text-right" :class="isCurrent(i + 1) ? 'bg-indigo-50 text-indigo-600' : ''">{{ mn }}</th>
                                <th class="px-3 py-2.5 text-right">За год</th>
                                <th class="px-3 py-2.5 text-right">Выплачено</th>
                                <th class="px-3 py-2.5 text-right" title="Накопленный баланс за всё время (с переносом с прошлых лет)">К выплате</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="r in list" :key="r.uid" class="divide-x divide-slate-100 transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="sticky left-0 z-10 bg-white px-6 py-2">
                                    <div class="flex items-center gap-2.5">
                                        <Avatar :name="r.user" :src="r.avatar" :size="28" />
                                        <div class="min-w-0 leading-tight">
                                            <div class="truncate font-medium text-slate-900">{{ r.user }}</div>
                                            <button v-if="r.carry" @click="openCarry(r)" class="text-[10px] tabular-nums underline decoration-dotted underline-offset-2 transition-colors hover:text-indigo-600" :class="r.carry > 0 ? 'text-slate-400' : 'text-rose-400'" title="Перенос с прошлых лет — нажмите, чтобы увидеть, из чего сложилась сумма">перенос {{ money(r.carry) }}</button>
                                        </div>
                                    </div>
                                </td>
                                <td v-for="c in r.months" :key="c.m" class="whitespace-nowrap px-2.5 py-2.5 text-right text-sm tabular-nums leading-tight"
                                    :class="isCurrent(c.m) ? 'bg-indigo-50/40' : ''" :title="cellTitle(r, c)">
                                    <template v-if="c.bonus || c.paid">
                                        <div :class="c.bonus > 0 ? 'font-semibold text-emerald-700' : 'text-slate-300'">{{ c.bonus ? short(c.bonus) : '—' }}</div>
                                        <div v-if="c.paid" class="text-xs text-rose-500">−{{ short(c.paid) }}</div>
                                    </template>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-emerald-700">{{ money(r.year_earned) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums" :class="r.year_paid > 0 ? 'font-medium text-rose-600' : 'text-slate-300'">{{ r.year_paid > 0 ? '− ' + money(r.year_paid) : '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-bold tabular-nums" :class="r.balance > 0 ? 'text-emerald-600' : (r.balance < 0 ? 'text-rose-600' : 'text-slate-300')">
                                    {{ money(r.balance) }}
                                    <button v-if="canManage && r.balance > 0" class="ml-2 rounded-lg bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100" title="Выплатить бонус" @click="openPay(r.uid)">💵 выплатить</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="leadership && list.length" class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
                            <tr class="divide-x divide-slate-100">
                                <td class="sticky left-0 z-10 bg-slate-50 px-6 py-3 text-slate-500">Итого</td>
                                <td v-for="m in 12" :key="m" class="whitespace-nowrap px-2.5 py-3 text-right text-sm tabular-nums leading-tight">
                                    <div class="text-emerald-700">{{ colSum(m, 'bonus') ? short(colSum(m, 'bonus')) : '' }}</div>
                                    <div v-if="colSum(m, 'paid')" class="text-xs text-rose-500">−{{ short(colSum(m, 'paid')) }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-emerald-700">{{ money(totals.year_earned) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums" :class="totals.year_paid > 0 ? 'text-rose-600' : 'text-slate-300'">{{ totals.year_paid > 0 ? '− ' + money(totals.year_paid) : '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-emerald-700">{{ money(totals.balance) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div v-if="!list.length" class="px-6 py-10 text-center text-sm text-slate-400">За {{ year }} бонусов и выплат нет</div>
            </div>
            <p class="mt-3 text-xs text-slate-400">К выплате = Σ бонусов по марже за всё время − Σ выплат из бонуса (авансы «из бонуса» на «Зарплате» + погашения долгов). Менеджер может не брать бонус месяцами — остаток копится и переносится между годами («перенос» под именем). В ячейке месяца: зелёным — заработано, красным — выплачено в этом месяце.</p>

            <!-- Модалка выплаты бонуса -->
            <Modal :show="showPay" max-width="lg" @close="showPay = false">
                <div class="p-6">
                    <h2 class="mb-1 text-lg font-semibold text-slate-900">Выплатить бонус</h2>
                    <p class="mb-4 text-xs text-slate-400">Реальная выдача денег из накопленного бонуса — уйдёт в Расходы и уменьшит кассу/банк. Можно выплатить часть, остаток продолжит копиться.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Сотрудник *</label>
                            <select v-model="payForm.user_id" @change="onPayUser" class="w-full rounded-md border-slate-300 text-sm shadow-sm">
                                <option value="">— выберите —</option>
                                <option v-for="r in rows" :key="r.uid" :value="r.uid">{{ r.user }} — к выплате {{ money(r.balance) }}</option>
                            </select>
                            <div v-if="payForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ payForm.errors.user_id }}</div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Сумма, ₸ *</label>
                            <input v-model="payForm.amount" type="number" min="0" step="0.01" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                            <p v-if="payRow" class="mt-1 text-[11px] text-slate-500">Накоплено к выплате: <b class="tabular-nums text-slate-800">{{ money(payRow.balance) }}</b>
                                <button type="button" class="ml-1 font-semibold text-indigo-600 hover:underline" @click="payForm.amount = payRow.balance">всё</button></p>
                            <div v-if="payForm.errors.amount" class="mt-1 text-xs text-red-600">{{ payForm.errors.amount }}</div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Дата *</label>
                            <input v-model="payForm.date" type="date" class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Откуда выданы деньги *</label>
                            <div class="flex gap-2">
                                <button type="button" @click="payForm.payment_method = 'cash'"
                                    :class="payForm.payment_method === 'cash' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium">💵 Наличные</button>
                                <button type="button" @click="payForm.payment_method = 'bank'"
                                    :class="payForm.payment_method === 'bank' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium">🏦 Банк</button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Комментарий</label>
                            <input v-model="payForm.note" type="text" class="w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="За какой период…" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <SecondaryButton @click="showPay = false">Отмена</SecondaryButton>
                        <PrimaryButton :disabled="payForm.processing || !payForm.user_id || !(Number(payForm.amount) > 0)" @click="submitPay">💵 Выплатить</PrimaryButton>
                    </div>
                </div>
            </Modal>

            <!-- Разбивка переноса: сделки до выбранного года и выплаты из бонуса -->
            <Modal :show="carryModal" max-width="2xl" @close="carryModal = false">
                <div class="p-6">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Перенос — {{ carryFor?.user }}</h2>
                            <p class="text-sm text-slate-400">всё, что заработано и выплачено до 1 января {{ year }} года</p>
                        </div>
                        <button @click="carryModal = false" class="rounded-lg px-2 py-1 text-slate-400 hover:bg-slate-100">✕</button>
                    </div>

                    <div v-if="carryLoading" class="py-10 text-center text-sm text-slate-400">Загрузка…</div>
                    <div v-else-if="carryData?.error" class="py-10 text-center text-sm text-rose-500">Не удалось загрузить — попробуйте ещё раз</div>
                    <template v-else-if="carryData">
                        <!-- Заработано: бонусы по выигранным сделкам -->
                        <div class="mt-4 rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50/80 to-emerald-100/50 p-3">
                            <div class="flex items-baseline justify-between px-1">
                                <span class="text-xs font-bold uppercase tracking-wide text-emerald-700">Заработано по сделкам</span>
                                <span class="font-bold tabular-nums text-emerald-700">{{ money(carryData.earned_sum) }}</span>
                            </div>
                            <div class="mt-2 max-h-48 divide-y divide-emerald-100 overflow-y-auto rounded-lg bg-white/70">
                                <a v-for="d in carryData.earned" :key="d.id" :href="route('deals.show', d.id)" target="_blank"
                                    class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm transition-colors hover:bg-emerald-50">
                                    <span class="min-w-0 truncate">
                                        <span class="font-semibold text-indigo-700">{{ d.number }}</span>
                                        <span class="ml-1.5 text-slate-500">{{ d.customer }}</span>
                                    </span>
                                    <span class="flex-shrink-0 text-xs tabular-nums text-slate-400">{{ d.date }}</span>
                                    <span class="flex-shrink-0 font-semibold tabular-nums text-emerald-700">{{ money(d.bonus) }}</span>
                                </a>
                                <div v-if="!carryData.earned.length" class="px-3 py-4 text-center text-xs text-slate-400">Сделок с бонусом до {{ year }} года нет</div>
                            </div>
                        </div>

                        <!-- Выплачено: авансы/выплаты из бонуса и погашения долгов -->
                        <div class="mt-3 rounded-xl border border-rose-200 bg-gradient-to-br from-rose-50/80 to-rose-100/50 p-3">
                            <div class="flex items-baseline justify-between px-1">
                                <span class="text-xs font-bold uppercase tracking-wide text-rose-700">Выплачено / удержано</span>
                                <span class="font-bold tabular-nums text-rose-700">−{{ money(carryData.paid_sum) }}</span>
                            </div>
                            <div class="mt-2 max-h-48 divide-y divide-rose-100 overflow-y-auto rounded-lg bg-white/70">
                                <div v-for="(pp, i) in carryData.paid" :key="i" class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm">
                                    <span class="min-w-0 truncate text-slate-600">{{ pp.label }}<span v-if="pp.note" class="ml-1 text-xs text-slate-400">· {{ pp.note }}</span></span>
                                    <span class="flex-shrink-0 text-xs tabular-nums text-slate-400">{{ pp.date }}</span>
                                    <span class="flex-shrink-0 font-semibold tabular-nums text-rose-600">−{{ money(pp.amount) }}</span>
                                </div>
                                <div v-if="!carryData.paid.length" class="px-3 py-4 text-center text-xs text-slate-400">Выплат до {{ year }} года не было</div>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between rounded-xl bg-slate-900 px-4 py-3 text-white">
                            <span class="text-sm font-medium">Итого перенос на 1 января {{ year }}</span>
                            <span class="text-lg font-bold tabular-nums" :class="carryData.carry >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ money(carryData.carry) }}</span>
                        </div>
                    </template>
                </div>
            </Modal>
        </FinanceLayout>
    </AppLayout>
</template>
