<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import { useStickyFilters } from '@/composables/useStickyFilters';
import { money } from '@/utils/format';

const props = defineProps({
    date: String,
    kind: { type: String, default: 'cash' },
    opening: Number,
    income: Number,
    expense: Number,
    closing: Number,
    operations: { type: Array, default: () => [] },
    liveBalance: Number,
});

// День книги и вид денег (наличные / банк). Запоминаются, как остальные фильтры.
const day = ref(props.date);
const kindSel = ref(props.kind);
const setDay = () => router.get(route('cashBook.index'),
    // Передаём ЛЮБОЙ режим кроме наличных (они — умолчание сервера).
    // Раньше здесь проверялось только 'bank', и «Общее» молча падало в кассу.
    { date: day.value || undefined, kind: kindSel.value !== 'cash' ? kindSel.value : undefined },
    { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('cash-book', { day, kindSel }, setDay);
const isBank = computed(() => props.kind === 'bank');
const isAll = computed(() => props.kind === 'all');
// В «Общем» у каждой операции показываем, нал это или банк.
const methodLabel = (m) => m === 'bank' ? 'банк' : 'наличные';

const shift = (n) => {
    const d = new Date(props.date + 'T00:00:00');
    d.setDate(d.getDate() + n);
    day.value = d.toISOString().slice(0, 10);
    setDay();
};
const isToday = computed(() => props.date === new Date().toISOString().slice(0, 10));
const dayLabel = computed(() => new Date(props.date + 'T00:00:00')
    .toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }));

const time = (iso) => iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '—';
</script>

<template>
    <Head title="Касса" />
    <AppLayout>
        <FinanceLayout :title="isAll ? 'Все деньги' : (isBank ? 'Банк' : 'Касса')" subtitle="отчёт за день" active="cashBook.index">
            <template #actions>
                <!-- Наличные единые на холдинг, банк раздельный по фирмам -->
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                    <button v-for="k in [['cash','Наличные'],['bank','Банк'],['all','Общее']]" :key="k[0]" type="button"
                        @click="kindSel = k[0]; setDay()"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                        :class="kind === k[0] ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'">{{ k[1] }}</button>
                </div>
                <button @click="shift(-1)" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50" title="Предыдущий день">←</button>
                <input v-model="day" @change="setDay" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <button @click="shift(1)" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50" title="Следующий день">→</button>
                <button v-if="!isToday" @click="day = new Date().toISOString().slice(0,10); setDay()"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">Сегодня</button>
            </template>

            <!-- Четыре цифры дня: начало → приход → расход → остаток сейчас -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Остаток на начало дня</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ money(opening) }}</div>
                    <div class="mt-0.5 text-[11px] text-slate-400">перенос с прошлого дня</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Приход за день</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="income > 0 ? 'text-emerald-600' : 'text-slate-300'">
                        {{ income > 0 ? '+ ' + money(income) : '—' }}
                    </div>
                </div>
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-rose-500">Расход за день</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="expense > 0 ? 'text-rose-600' : 'text-rose-300'">
                        {{ expense > 0 ? '− ' + money(expense) : '—' }}
                    </div>
                </div>
                <!-- Главная цифра: сколько наличных в кассе на конец этого дня -->
                <div class="rounded-xl border border-transparent p-4 shadow-md" style="background-color: #1A3B5C">
                    <div class="text-[11px] uppercase tracking-wide text-white/60">
                        {{ isToday ? (isAll ? 'Всего денег сейчас' : (isBank ? 'На счёте сейчас' : 'Доступно сейчас')) : 'Остаток на конец дня' }}
                    </div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-300">{{ money(closing) }}</div>
                    <div class="mt-0.5 text-[11px] text-white/60">{{ money(opening) }} + {{ money(income) }} − {{ money(expense) }}</div>
                </div>
            </div>

            <!-- Расхождение с плиткой «Касса» на Финансах бывает только у прошлых
                 дней (там остаток на сегодня) — подсказываем, чтобы не искали ошибку. -->
            <p v-if="!isToday" class="mt-2 px-1 text-[11px] text-slate-400">
                Это остаток на конец {{ dayLabel }}. {{ isAll ? 'Всего денег сегодня:' : (isBank ? 'На счёте сегодня:' : 'Наличные в кассе сегодня:') }} <b class="tabular-nums text-slate-600">{{ money(liveBalance) }}</b>.
            </p>

            <!-- Лента операций: как строки бумажного отчёта кассира -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Операции · {{ dayLabel }}</h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ operations.length }} шт.</span>
                </div>

                <div v-if="operations.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5 font-medium">Время</th>
                                <th class="px-4 py-2.5 font-medium">Операция</th>
                                <th class="px-4 py-2.5 text-right font-medium">Сумма</th>
                                <th class="hidden px-4 py-2.5 text-right font-medium sm:table-cell" title="Остаток в кассе после операции">Остаток</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="op in operations" :key="op.id" class="group transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="whitespace-nowrap px-6 py-2.5 align-top text-xs tabular-nums text-slate-400">{{ time(op.at) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <Link v-if="op.deal_id" :href="route('deals.show', op.deal_id)" class="truncate font-medium text-indigo-600 hover:underline">{{ op.title }}</Link>
                                        <span v-else class="truncate font-medium text-slate-800">{{ op.title }}</span>
                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="op.kind === 'in' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ op.tag }}</span>
                                        <!-- Выплата сотруднику: кому и за что — ссылкой на карточку -->
                                        <span v-if="op.payout" class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">{{ op.payout }}</span>
                                        <span v-if="isAll" class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="op.method === 'bank' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-500'">{{ methodLabel(op.method) }}</span>
                                    </div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-slate-400">
                                        <Link v-if="op.employee" :href="route('users.show', op.employee.id)" class="font-medium text-indigo-600 hover:underline" @click.stop>
                                            ☻ {{ op.employee.name }}
                                        </Link>
                                        <span v-if="op.note" class="truncate">{{ op.note }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right align-top font-semibold tabular-nums"
                                    :class="op.kind === 'in' ? 'text-emerald-600' : 'text-rose-600'">
                                    {{ op.kind === 'in' ? '+ ' : '− ' }}{{ money(op.amount) }}
                                </td>
                                <!-- Промежуточный баланс: сколько стало в кассе после операции -->
                                <td class="hidden whitespace-nowrap px-4 py-2.5 text-right align-top text-xs tabular-nums sm:table-cell"
                                    :class="op.balance < 0 ? 'font-semibold text-rose-600' : 'text-slate-400'"
                                    title="Остаток в кассе после этой операции">{{ money(op.balance) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
                            <tr>
                                <td class="whitespace-nowrap px-6 py-3 text-slate-500" colspan="2">Итого за день</td>
                                <td class="px-4 py-3 text-right" colspan="2">
                                    <span class="inline-flex flex-wrap items-center justify-end gap-x-4 gap-y-1 tabular-nums">
                                        <span class="whitespace-nowrap text-emerald-600">+ {{ money(income) }}</span>
                                        <span class="whitespace-nowrap text-rose-600">− {{ money(expense) }}</span>
                                        <span class="whitespace-nowrap font-bold text-slate-900">остаток {{ money(closing) }}</span>
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div v-else class="px-6 py-10 text-center text-sm text-slate-400">За {{ dayLabel }} движения денег не было</div>
            </div>

            <p class="mt-3 px-1 text-[11px] text-slate-400">
                <template v-if="isAll">Наличные и банк вместе. Каждый считается по своему правилу: касса единая на холдинг, банк — счёт текущей фирмы; у операции стоит пометка, откуда деньги.</template>
                <template v-else-if="isBank">Банк раздельный по фирмам — показан счёт текущей компании. В книгу входят оплаты по счетам безналом, поступления на счёт и подтверждённые расходы безналом.</template>
                <template v-else>Только наличные. Касса единая на холдинг: наличные физически в одной кассе, поэтому расход налом любой фирмы уменьшает общий остаток. В книгу входят оплаты по счетам налом, поступления в кассу и подтверждённые расходы налом.</template>
                Расход попадает сюда только ПОСЛЕ подтверждения бухгалтером.
            </p>
        </FinanceLayout>
    </AppLayout>
</template>
