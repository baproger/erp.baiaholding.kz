<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({ screen: Object, plan: Number, month: String, monthLabel: String, managers: Array, leader: Object, lots: { type: Array, default: () => [] } });

// План отдела на месяц: сколько лотов добавлено всеми к плану (например 100).
const deptTotal = computed(() => props.managers.reduce((s, m) => s + (m.total || 0), 0));
const deptWon = computed(() => props.managers.reduce((s, m) => s + (m.won || 0), 0));
const planPct = computed(() => Math.min(100, Math.round(deptTotal.value / Math.max(1, props.plan) * 100)));
// Чек-лист: доля закрытых галочек — видно, работает менеджер по лотам или нет.
const checksPct = (m) => m.checks_total > 0 ? Math.round(m.checks_done / m.checks_total * 100) : 0;
const checksClass = (p) => p >= 70 ? 'bg-emerald-100 text-emerald-700' : p >= 30 ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-600';

// ТВ-режим: часы + автообновление раз в 30 секунд.
const clock = ref('');
let clockTimer = null, refreshTimer = null;
const tick = () => (clock.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
onMounted(() => {
    tick();
    clockTimer = setInterval(tick, 1000);
    refreshTimer = setInterval(() => router.reload(), 30000);
});
onUnmounted(() => { clearInterval(clockTimer); clearInterval(refreshTimer); });

const title = computed(() => ['Офис', props.screen?.company].filter(Boolean).join(' · '));
const leave = () => router.post(route('screen.leave'));

// Фильтр месяца: кто был лучшим сотрудником в выбранном месяце.
const monthF = ref(props.month ?? '');
const applyMonth = () => router.get(route('screen.show'), { month: monthF.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
const isCurrent = computed(() => props.month === new Date().toISOString().slice(0, 7));

const convClass = (c) => c >= 50 ? 'bg-emerald-100 text-emerald-700' : c >= 25 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500';
const barClass = (s) => s >= 70 ? 'bg-emerald-500' : s >= 30 ? 'bg-indigo-500' : 'bg-amber-400';
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50 p-4 lg:p-6">
        <!-- Шапка -->
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold leading-tight text-slate-900 lg:text-3xl">{{ title }}</h1>
                <div class="text-sm text-slate-400">рейтинг эффективности — {{ monthLabel }}<span v-if="!isCurrent" class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">архив</span></div>
            </div>
            <div class="flex items-center gap-3">
                <input v-model="monthF" @change="applyMonth" type="month"
                    class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" title="Какой месяц показать" />
                <div class="flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-emerald-700">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    <span class="text-xl font-bold tabular-nums lg:text-2xl">{{ clock }}</span>
                </div>
                <button @click="leave" class="rounded-lg px-3 py-2 text-xs text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" title="Сменить код">выйти</button>
            </div>
        </div>

        <!-- План отдела на месяц: лотов добавлено / план -->
        <div class="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-base font-bold text-slate-900">План лотов на {{ monthLabel }}</span>
                <span class="text-sm text-slate-400">добавлено <b class="tabular-nums text-slate-700">{{ deptTotal }}</b> из <b class="tabular-nums text-slate-700">{{ plan }}</b> · выиграно <b class="tabular-nums text-emerald-600">{{ deptWon }}</b></span>
            </div>
            <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-100">
                <div class="h-3 rounded-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-700" :style="{ width: Math.max(1, planPct) + '%' }"></div>
            </div>
            <div class="mt-1 text-right text-xs font-semibold tabular-nums" :class="planPct >= 100 ? 'text-emerald-600' : 'text-slate-400'">{{ planPct }}% плана</div>
        </div>

        <div class="flex flex-col gap-4 xl:flex-row">
            <!-- Слева: рейтинг эффективности отдела продаж -->
            <div class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-baseline justify-between border-b border-slate-100 px-5 py-3.5">
                    <span class="text-base font-bold text-slate-900">Отдел продаж — лоты за месяц</span>
                    <span class="text-xs text-slate-400">добавил лотов · выиграл · конверсия %</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="(m, i) in managers" :key="m.name" class="flex items-center gap-4 px-5 py-3.5">
                        <span class="w-7 text-center text-lg font-bold" :class="i === 0 && m.won > 0 ? '' : 'text-slate-300'">{{ i === 0 && m.won > 0 ? '👑' : i + 1 }}</span>
                        <Avatar :name="m.name" :src="m.avatar" :size="40" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate text-base font-semibold text-slate-900">{{ m.name }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums" :class="convClass(m.conversion)">конверсия {{ m.conversion }}%</span>
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <div class="h-2 w-full max-w-xs overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full transition-all duration-700" :class="barClass(m.conversion)" :style="{ width: Math.max(2, m.conversion) + '%' }"></div>
                                </div>
                                <span class="flex-shrink-0 text-xs text-slate-400">план лотов: {{ m.total }}/{{ plan }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold tabular-nums" :class="m.won > 0 ? 'text-emerald-600' : 'text-slate-300'">{{ m.won }}</div>
                            <div class="flex items-center justify-end gap-1.5 text-[11px] text-slate-400">
                                <span>выиграл · лотов {{ m.total }} · сделок {{ m.deals }}</span>
                                <!-- Чек-лист: закрыто галочек из возможных по его лотам -->
                                <span class="rounded-full px-1.5 py-0.5 font-bold tabular-nums"
                                    :class="m.checks_total > 0 ? checksClass(checksPct(m)) : 'bg-slate-100 text-slate-400'"
                                    title="Чек-лист по лотам: сделано / всего">☑ {{ m.checks_done }}/{{ m.checks_total }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="!managers.length" class="px-5 py-10 text-center text-sm text-slate-400">В отделе продаж пока нет менеджеров</div>
                </div>
            </div>

            <!-- Справа: лидер месяца по эффективности -->
            <div class="w-full flex-shrink-0 xl:w-96">
                <div v-if="leader && leader.won > 0" class="rounded-2xl border border-amber-200 bg-white shadow-sm">
                    <div class="rounded-t-2xl bg-amber-50/70 px-5 py-4 text-center">
                        <div class="text-3xl">👑</div>
                        <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-amber-600">Лидер — {{ monthLabel }}</div>
                    </div>
                    <div class="flex flex-col items-center px-5 py-5">
                        <Avatar :name="leader.name" :src="leader.avatar" :size="72" />
                        <div class="mt-3 text-xl font-bold text-slate-900">{{ leader.name }}</div>
                        <div class="mt-1 text-sm text-slate-400">больше всех выигранных лотов</div>
                        <div class="mt-4 grid w-full grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-emerald-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-emerald-600">{{ leader.won }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">выиграл</div>
                            </div>
                            <div class="rounded-xl bg-amber-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-amber-600">{{ leader.conversion }}%</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">конверсия</div>
                            </div>
                            <div class="rounded-xl bg-indigo-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-indigo-600">{{ leader.total }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">лотов добавил</div>
                            </div>
                        </div>
                        <div class="mt-3 w-full rounded-xl bg-slate-50 p-3 text-center">
                            <div class="text-xl font-bold tabular-nums text-slate-900">{{ leader.deals }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-400">лотов стало сделками</div>
                        </div>
                    </div>
                </div>
                <div v-else class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-400 shadow-sm">
                    В {{ monthLabel }} ещё нет выигранных лотов —<br />лидер появится после первого «Выиграл»
                </div>
            </div>
        </div>

        <!-- Лоты месяца с чек-листами: видно, кто реально работает по лотам -->
        <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-baseline justify-between border-b border-slate-100 px-5 py-3.5">
                <span class="text-base font-bold text-slate-900">Лоты месяца — чек-листы</span>
                <span class="text-xs text-slate-400">☑ галочки лота («КП», «Позвонил»…) — работа менеджера по лоту</span>
            </div>
            <div class="divide-y divide-slate-50">
                <div v-for="(l, i) in lots" :key="i" class="flex items-center gap-4 px-5 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-semibold text-slate-900">{{ l.product || '—' }}</span>
                            <span v-if="l.won" class="flex-shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">ВЫИГРАЛ ✓</span>
                        </div>
                        <div class="truncate text-xs text-slate-400">{{ l.customer || '—' }} · {{ l.manager }}</div>
                    </div>
                    <div class="flex w-44 flex-shrink-0 items-center gap-2">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-2 rounded-full transition-all duration-700"
                                :class="l.checks_total > 0 && l.checks_done === l.checks_total ? 'bg-emerald-500' : l.checks_done > 0 ? 'bg-amber-400' : 'bg-rose-300'"
                                :style="{ width: Math.max(4, l.checks_total > 0 ? Math.round(l.checks_done / l.checks_total * 100) : 0) + '%' }"></div>
                        </div>
                        <span class="flex-shrink-0 text-xs font-bold tabular-nums" :class="l.checks_done === l.checks_total && l.checks_total > 0 ? 'text-emerald-600' : 'text-slate-500'">☑ {{ l.checks_done }}/{{ l.checks_total }}</span>
                    </div>
                </div>
                <div v-if="!lots.length" class="px-5 py-8 text-center text-sm text-slate-400">В {{ monthLabel }} лотов ещё нет</div>
            </div>
        </div>
    </div>
</template>
