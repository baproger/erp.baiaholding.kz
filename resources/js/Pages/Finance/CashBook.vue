<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useStickyFilters } from '@/composables/useStickyFilters';
import { money } from '@/utils/format';

const props = defineProps({
    date: String,
    opening: Number,
    income: Number,
    expense: Number,
    closing: Number,
    operations: { type: Array, default: () => [] },
    liveBalance: Number,
});

// День кассовой книги. Запоминается, как остальные фильтры страниц.
const day = ref(props.date);
const setDay = () => router.get(route('cashBook.index'), { date: day.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('cash-book', { day }, setDay);

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
        <template #header>
            <div class="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span class="truncate">Касса · отчёт за день</span>
                <div class="flex flex-wrap items-center gap-1.5">
                    <button @click="shift(-1)" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-50" title="Предыдущий день">←</button>
                    <input v-model="day" @change="setDay" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    <button @click="shift(1)" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-50" title="Следующий день">→</button>
                    <button v-if="!isToday" @click="day = new Date().toISOString().slice(0,10); setDay()"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">Сегодня</button>
                </div>
            </div>
        </template>

        <!-- Четыре цифры дня: начало → приход → расход → остаток сейчас -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Остаток на начало дня</div>
                <div class="mt-1.5 whitespace-nowrap text-xl font-semibold tabular-nums text-slate-800">{{ money(opening) }}</div>
                <div class="mt-0.5 text-[11px] text-slate-400">перенос с прошлого дня</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Приход за день</div>
                <div class="mt-1.5 whitespace-nowrap text-xl font-semibold tabular-nums" :class="income > 0 ? 'text-emerald-600' : 'text-slate-300'">
                    {{ income > 0 ? '+ ' + money(income) : '—' }}
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Расход за день</div>
                <div class="mt-1.5 whitespace-nowrap text-xl font-semibold tabular-nums" :class="expense > 0 ? 'text-rose-600' : 'text-slate-300'">
                    {{ expense > 0 ? '− ' + money(expense) : '—' }}
                </div>
            </div>
            <!-- Главная цифра: сколько наличных в кассе на конец этого дня -->
            <div class="rounded-2xl border border-transparent p-4 text-white shadow-md" style="background-color: #1A3B5C">
                <div class="text-[11px] font-medium uppercase tracking-wide text-white/60">
                    {{ isToday ? 'Доступно сейчас' : 'Остаток на конец дня' }}
                </div>
                <div class="mt-1.5 whitespace-nowrap text-2xl font-bold tabular-nums">{{ money(closing) }}</div>
                <div class="mt-0.5 text-[11px] text-white/60">{{ money(opening) }} + {{ money(income) }} − {{ money(expense) }}</div>
            </div>
        </div>

        <!-- Расхождение с плиткой «Касса» на Финансах бывает только у прошлых
             дней (там остаток на сегодня) — подсказываем, чтобы не искали ошибку. -->
        <p v-if="!isToday" class="mt-2 px-1 text-[11px] text-slate-400">
            Это остаток на конец {{ dayLabel }}. Наличные в кассе сегодня: <b class="tabular-nums text-slate-600">{{ money(liveBalance) }}</b>.
        </p>

        <!-- Лента операций: как строки бумажного отчёта кассира -->
        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
                <span class="text-xs font-bold uppercase tracking-wide text-slate-600">Операции · {{ dayLabel }}</span>
                <span class="text-[11px] text-slate-400">{{ operations.length }} шт.</span>
            </div>

            <div v-if="operations.length" class="divide-y divide-slate-50">
                <div v-for="op in operations" :key="op.id" class="flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50">
                    <span class="w-11 shrink-0 text-xs tabular-nums text-slate-400">{{ time(op.at) }}</span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <Link v-if="op.deal_id" :href="route('deals.show', op.deal_id)" class="truncate font-medium text-indigo-600 hover:underline">{{ op.title }}</Link>
                            <span v-else class="truncate font-medium text-slate-800">{{ op.title }}</span>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="op.kind === 'in' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ op.tag }}</span>
                        </div>
                        <div v-if="op.note" class="mt-0.5 truncate text-xs text-slate-400">{{ op.note }}</div>
                    </div>

                    <span class="shrink-0 whitespace-nowrap text-sm font-semibold tabular-nums"
                        :class="op.kind === 'in' ? 'text-emerald-600' : 'text-rose-600'">
                        {{ op.kind === 'in' ? '+ ' : '− ' }}{{ money(op.amount) }}
                    </span>
                    <!-- Промежуточный баланс: сколько стало в кассе после операции -->
                    <span class="hidden w-32 shrink-0 text-right text-xs tabular-nums sm:block"
                        :class="op.balance < 0 ? 'font-semibold text-rose-600' : 'text-slate-400'"
                        title="Остаток в кассе после этой операции">{{ money(op.balance) }}</span>
                </div>
            </div>

            <div v-else class="px-4 py-14 text-center">
                <div class="mb-2 text-3xl">▣</div>
                <div class="text-sm text-slate-400">За {{ dayLabel }} движения наличных не было</div>
            </div>

            <div v-if="operations.length" class="flex flex-wrap items-center justify-between gap-2 border-t-2 border-slate-200 bg-slate-50 px-4 py-3">
                <span class="text-xs font-bold uppercase tracking-wide text-slate-600">Итого за день</span>
                <span class="flex flex-wrap items-center gap-4 text-sm tabular-nums">
                    <span class="text-emerald-600">+ {{ money(income) }}</span>
                    <span class="text-rose-600">− {{ money(expense) }}</span>
                    <span class="font-bold text-slate-900">остаток {{ money(closing) }}</span>
                </span>
            </div>
        </div>

        <p class="mt-3 px-1 text-[11px] text-slate-400">
            Только наличные. Касса единая на холдинг: наличные физически в одной кассе, поэтому расход налом любой фирмы уменьшает общий остаток.
            В книгу входят оплаты по счетам налом, поступления в кассу и подтверждённые расходы налом.
        </p>
    </AppLayout>
</template>
