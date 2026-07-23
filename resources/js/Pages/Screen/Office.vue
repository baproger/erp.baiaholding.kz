<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';
import { formatDate } from '@/utils/format';

const props = defineProps({ screen: Object, stages: Array, deals: Array, leaders: Array });

const byStage = (id) => props.deals.filter((d) => d.stage_id === id);

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
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50 p-4 lg:p-6">
        <!-- Шапка -->
        <div class="mb-5 flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm">
            <div>
                <h1 class="text-2xl font-bold leading-tight text-slate-900 lg:text-3xl">{{ title }}</h1>
                <div class="text-sm text-slate-400">сделок в работе: <b class="tabular-nums text-slate-600">{{ deals.length }}</b></div>
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
            <!-- Сделки по этапам -->
            <div class="min-w-0 flex-1">
                <div class="flex gap-4 overflow-x-auto pb-4">
                    <div v-for="stage in stages" :key="stage.id" class="flex w-72 flex-shrink-0 flex-col rounded-2xl bg-slate-200/60">
                        <div class="flex items-center justify-between px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                                <span class="text-base font-bold text-slate-800">{{ stage.name }}</span>
                            </div>
                            <span class="rounded-full bg-white px-2.5 py-0.5 text-sm font-bold tabular-nums text-slate-500 shadow-sm">{{ byStage(stage.id).length }}</span>
                        </div>
                        <div class="flex-1 space-y-2 px-3 pb-3">
                            <div v-for="d in byStage(stage.id).slice(0, 8)" :key="d.number" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                                <div class="text-sm font-bold leading-snug text-slate-900">{{ d.name }}</div>
                                <div class="mt-0.5 flex items-center justify-between text-xs text-slate-400">
                                    <span>{{ d.number }}</span>
                                    <span v-if="d.manager" class="font-medium text-slate-500">{{ d.manager }}</span>
                                </div>
                                <div v-if="d.deadline" class="mt-1 text-xs font-semibold" :class="d.overdue ? 'text-rose-600' : 'text-slate-500'">⏰ {{ formatDate(d.deadline) }}<span v-if="d.overdue"> · просрочен!</span></div>
                            </div>
                            <div v-if="byStage(stage.id).length > 8" class="text-center text-xs text-slate-400">+ ещё {{ byStage(stage.id).length - 8 }}</div>
                            <div v-if="!byStage(stage.id).length" class="py-6 text-center text-xs text-slate-400">Пусто</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Правый бок: лидеры по сделкам -->
            <div class="w-full flex-shrink-0 xl:w-80">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-3.5 text-base font-bold text-slate-900">Лидеры по сделкам</div>
                    <div class="divide-y divide-slate-50">
                        <div v-for="(m, i) in leaders" :key="m.name" class="flex items-center gap-3 px-5 py-3" :class="i === 0 ? 'bg-amber-50/60' : ''">
                            <span class="w-7 text-center text-lg font-bold" :class="i === 0 ? '' : 'text-slate-300'">{{ i === 0 ? '👑' : i + 1 }}</span>
                            <Avatar :name="m.name" :src="m.avatar" :size="36" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-900">{{ m.name }}</div>
                                <div class="text-xs text-slate-400">успешных: {{ m.won }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xl font-bold tabular-nums text-slate-900">{{ m.total }}</div>
                                <div class="text-[10px] uppercase tracking-wide text-slate-400">сделок</div>
                            </div>
                        </div>
                        <div v-if="!leaders.length" class="px-5 py-8 text-center text-sm text-slate-400">Пока нет сделок</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
