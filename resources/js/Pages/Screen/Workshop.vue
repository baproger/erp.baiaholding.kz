<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { formatDate } from '@/utils/format';

const props = defineProps({ screen: Object, stages: Array, projects: Array });

const byStage = (id) => props.projects.filter((p) => p.stage_id === id);

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

const title = computed(() => [props.screen?.company, props.screen?.workshop].filter(Boolean).join(' · ') || 'Цех');
const leave = () => router.post(route('screen.leave'));
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-950 p-4 text-white lg:p-6">
        <!-- Шапка: цех, живые часы, счётчик заказов -->
        <div class="mb-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🏭</span>
                <div>
                    <h1 class="text-2xl font-bold leading-tight lg:text-3xl">{{ title }}</h1>
                    <div class="text-sm text-slate-400">заказов в работе: {{ projects.length }}</div>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-emerald-300">
                    <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span></span>
                    <span class="text-xl font-bold tabular-nums lg:text-2xl">{{ clock }}</span>
                </div>
                <button @click="leave" class="rounded-lg px-3 py-2 text-xs text-slate-500 transition hover:bg-slate-800 hover:text-slate-300" title="Сменить код">выйти</button>
            </div>
        </div>

        <!-- Канбан цеха: только свои этапы и заказы, без сумм -->
        <div class="flex gap-4 overflow-x-auto pb-4">
            <div v-for="stage in stages" :key="stage.id" class="flex w-80 flex-shrink-0 flex-col rounded-2xl bg-slate-900">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                        <span class="text-lg font-bold">{{ stage.name }}</span>
                        <span v-if="stage.is_completed" title="Завершающий">🏁</span>
                    </div>
                    <span class="rounded-full bg-slate-800 px-2.5 py-0.5 text-sm font-bold tabular-nums text-slate-300">{{ byStage(stage.id).length }}</span>
                </div>
                <div class="flex-1 space-y-2.5 px-3 pb-3">
                    <div v-for="p in byStage(stage.id)" :key="p.id" class="rounded-xl border border-slate-800 bg-slate-800/60 p-3.5">
                        <div class="text-lg font-bold leading-snug">{{ p.name }}</div>
                        <div class="text-xs text-slate-500">{{ p.number }}</div>
                        <div v-if="p.address" class="mt-1.5 text-sm text-slate-400">📍 {{ p.address }}</div>
                        <div v-if="p.deadline" class="mt-1 text-sm font-semibold" :class="p.overdue ? 'text-rose-400' : 'text-slate-300'">⏰ {{ formatDate(p.deadline) }}<span v-if="p.overdue"> · просрочен!</span></div>
                        <div v-if="p.description" class="mt-1.5 whitespace-pre-line text-sm leading-snug text-slate-400">{{ p.description }}</div>
                        <div v-if="p.note" class="mt-2 rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-sm leading-snug text-amber-300">📌 {{ p.note }}</div>
                    </div>
                    <div v-if="!byStage(stage.id).length" class="py-8 text-center text-sm text-slate-600">Пусто</div>
                </div>
            </div>
        </div>
    </div>
</template>
