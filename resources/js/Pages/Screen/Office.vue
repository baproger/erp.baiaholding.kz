<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({ screen: Object, plan: Number, monthLabel: String, managers: Array, leader: Object });

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
const totalMonth = computed(() => (props.managers ?? []).reduce((s, m) => s + m.count, 0));
const leave = () => router.post(route('screen.leave'));
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50 p-4 lg:p-6">
        <!-- Шапка -->
        <div class="mb-5 flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold leading-tight text-slate-900 lg:text-3xl">{{ title }}</h1>
                <div class="text-sm text-slate-400">{{ monthLabel }} · план {{ plan }} сделок/мес на менеджера · всего за месяц: <b class="tabular-nums text-slate-600">{{ totalMonth }}</b></div>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-emerald-700">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    <span class="text-xl font-bold tabular-nums lg:text-2xl">{{ clock }}</span>
                </div>
                <button @click="leave" class="rounded-lg px-3 py-2 text-xs text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" title="Сменить код">выйти</button>
            </div>
        </div>

        <div class="flex flex-col gap-4 xl:flex-row">
            <!-- Слева: отдел продаж — сделки за месяц против плана -->
            <div class="min-w-0 flex-1 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3.5 text-base font-bold text-slate-900">Отдел продаж — {{ monthLabel }}</div>
                <div class="divide-y divide-slate-50">
                    <div v-for="(m, i) in managers" :key="m.name" class="flex items-center gap-4 px-5 py-3.5">
                        <span class="w-7 text-center text-lg font-bold" :class="i === 0 ? '' : 'text-slate-300'">{{ i === 0 ? '👑' : i + 1 }}</span>
                        <Avatar :name="m.name" :src="m.avatar" :size="40" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-base font-semibold text-slate-900">{{ m.name }}</div>
                            <div class="mt-1 flex items-center gap-2">
                                <div class="h-2 w-full max-w-xs overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full transition-all duration-700"
                                        :class="m.pct >= 100 ? 'bg-emerald-500' : m.pct >= 50 ? 'bg-indigo-500' : 'bg-amber-400'"
                                        :style="{ width: Math.max(3, m.pct) + '%' }"></div>
                                </div>
                                <span class="flex-shrink-0 text-xs tabular-nums text-slate-400">{{ m.pct }}%</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold tabular-nums text-slate-900">{{ m.count }}<span class="text-sm font-medium text-slate-300"> / {{ plan }}</span></div>
                            <div class="text-xs" :class="m.left === 0 ? 'font-semibold text-emerald-600' : 'text-slate-400'">{{ m.left === 0 ? 'план выполнен ✓' : 'до плана: ' + m.left }}</div>
                        </div>
                    </div>
                    <div v-if="!managers.length" class="px-5 py-10 text-center text-sm text-slate-400">В отделе продаж пока нет менеджеров</div>
                </div>
            </div>

            <!-- Справа: лидер месяца, подробнее -->
            <div class="w-full flex-shrink-0 xl:w-96">
                <div v-if="leader" class="rounded-2xl border border-amber-200 bg-white shadow-sm">
                    <div class="rounded-t-2xl bg-amber-50/70 px-5 py-4 text-center">
                        <div class="text-3xl">👑</div>
                        <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-amber-600">Лидер месяца</div>
                    </div>
                    <div class="flex flex-col items-center px-5 py-5">
                        <Avatar :name="leader.name" :src="leader.avatar" :size="72" />
                        <div class="mt-3 text-xl font-bold text-slate-900">{{ leader.name }}</div>
                        <div class="mt-4 grid w-full grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-slate-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-slate-900">{{ leader.count }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">сделок за месяц</div>
                            </div>
                            <div class="rounded-xl bg-emerald-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-emerald-600">{{ leader.won }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">успешных</div>
                            </div>
                            <div class="rounded-xl bg-indigo-50 p-3">
                                <div class="text-2xl font-bold tabular-nums text-indigo-600">{{ leader.active }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">в работе</div>
                            </div>
                        </div>
                        <div class="mt-4 w-full">
                            <div class="mb-1 flex justify-between text-xs text-slate-400">
                                <span>выполнение плана</span><span class="font-semibold tabular-nums text-slate-600">{{ leader.count }} / {{ plan }} · {{ leader.pct }}%</span>
                            </div>
                            <div class="h-3 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-3 rounded-full transition-all duration-700" :class="leader.pct >= 100 ? 'bg-emerald-500' : 'bg-amber-400'" :style="{ width: Math.max(3, leader.pct) + '%' }"></div>
                            </div>
                            <div class="mt-2 text-center text-sm" :class="leader.left === 0 ? 'font-semibold text-emerald-600' : 'text-slate-500'">
                                {{ leader.left === 0 ? 'План выполнен! 🎉' : 'До плана осталось: ' + leader.left }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
