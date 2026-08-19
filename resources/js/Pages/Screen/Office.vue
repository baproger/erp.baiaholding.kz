<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({ screen: Object, plan: Number, month: String, monthLabel: String, managers: Array, leader: Object, funnel: { type: Array, default: () => [] } });

// ТВ-режим: часы + автообновление раз в 10 секунд. Без автопрокрутки —
// список статичен, весь во всю ширину (просьба владельца 31.07.2026).
const clock = ref('');
let clockTimer = null, refreshTimer = null;
const tick = () => (clock.value = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
onMounted(() => {
    tick();
    clockTimer = setInterval(tick, 1000);
    // Рейтинг меняется нечасто — раз в минуту более чем достаточно для ТВ.
    refreshTimer = setInterval(() => router.reload({ preserveScroll: true }), 60000);
});
onUnmounted(() => { clearInterval(clockTimer); clearInterval(refreshTimer); });

const title = computed(() => ['Офис', props.screen?.company].filter(Boolean).join(' · '));
const leave = () => router.post(route('screen.leave'));

// Фильтр месяца: кто был лучшим сотрудником в выбранном месяце.
const monthF = ref(props.month ?? '');
const applyMonth = () => router.get(route('screen.show'), { month: monthF.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
const isCurrent = computed(() => props.month === new Date().toISOString().slice(0, 7));

const convClass = (c) => c >= 50 ? 'bg-emerald-50 text-emerald-700' : c >= 25 ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500';
const barClass = (s) => s >= 70 ? 'bg-emerald-500' : s >= 30 ? 'bg-indigo-500' : 'bg-amber-400';
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50 p-4 lg:p-6">
        <!-- Шапка -->
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold leading-tight text-slate-900 lg:text-3xl">{{ title }}</h1>
                <div class="text-sm text-slate-400">рейтинг эффективности — {{ monthLabel }}<span v-if="!isCurrent" class="ml-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">архив</span></div>
            </div>
            <div class="flex items-center gap-3">
                <input v-model="monthF" @change="applyMonth" type="month"
                    class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" title="Какой месяц показать" />
                <div class="flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-emerald-700">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    <span class="text-xl font-bold tabular-nums lg:text-2xl">{{ clock }}</span>
                </div>
                <button @click="leave" class="rounded-lg px-3 py-2 text-xs text-slate-400 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-600" title="Сменить код">выйти</button>
            </div>
        </div>

        <!-- ГЛАВНОЕ и единственное: рейтинг отдела продаж во всю ширину,
             без воронки-плиток, без карточки лидера, без автопрокрутки. -->
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-baseline justify-between border-b border-slate-100 px-5 py-3.5">
                    <span class="text-base font-bold text-slate-900">Отдел продаж — лоты за месяц</span>
                    <span class="text-xs text-slate-400">Участие / Звонок / КП → из них выиграно</span>
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
                        <!-- Справа — разбивка по действиям: сколько сделал и сколько из них выиграл -->
                        <div class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                <span v-for="a in m.actions" :key="a.label"
                                    class="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-sm font-semibold ring-1 ring-inset"
                                    :class="a.count > 0 ? 'bg-slate-50 text-slate-600 ring-slate-200' : 'bg-slate-50 text-slate-300 ring-slate-100'"
                                    :title="a.label + ': всего ' + a.count + ', выиграно ' + a.won">
                                    <span class="max-w-36 truncate">{{ a.label }}</span>
                                    <b class="text-lg leading-none tabular-nums" :class="a.count > 0 ? 'text-slate-900' : 'text-slate-300'">{{ a.count }}</b>
                                    <span class="rounded-full px-1.5 py-0.5 text-xs font-bold tabular-nums"
                                        :class="a.won > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-300'">✓ {{ a.won }}</span>
                                </span>
                                <span class="ml-1 text-4xl font-black leading-none tabular-nums" :class="m.won > 0 ? 'text-emerald-600' : 'text-slate-300'" title="Выиграл всего">{{ m.won }}</span>
                            </div>
                            <div class="mt-1 flex items-center justify-end gap-1.5 text-xs text-slate-400">
                                <span>выиграл · лотов {{ m.total }} · сделок {{ m.deals }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="!managers.length" class="px-5 py-10 text-center text-sm text-slate-400">В отделе продаж пока нет менеджеров</div>
                </div>
        </div>

    </div>
</template>

