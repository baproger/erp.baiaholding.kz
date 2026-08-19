<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Avatar from '@/Components/Avatar.vue';
import Pagination from '@/Components/Pagination.vue';
import { onMounted, onUnmounted } from 'vue';
import { formatDuration } from '@/utils/format';

const props = defineProps({ projects: [Array, Object], stages: Array, view: String, filters: Object, canSeeMoney: Boolean });

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const list = computed(() => Array.isArray(props.projects) ? props.projects : props.projects.data);
const byStage = (id) => list.value.filter((p) => p.project_stage_id === id);

const draggingId = ref(null);
const onDrop = (stage) => {
    const id = draggingId.value; draggingId.value = null;
    if (!id) return;
    const p = list.value.find((x) => x.id === id);
    if (!p || p.project_stage_id === stage.id) return;
    router.patch(route('projects.stage', id), { project_stage_id: stage.id }, { preserveScroll: true, preserveState: true });
};
const switchView = (v) => router.get(route('projects.index'), { ...props.filters, view: v }, { preserveState: true });
const advance = (p) => router.patch(route('projects.advance', p.id), {}, { preserveScroll: true, preserveState: true });
// Секции канбана: у BAIA два цеха («Металл цех» / «Ағаш цех») — своя строка
// этапов на каждый; у ASU один цех (workshop=null) — одна секция без шапки.
const workshopGroups = computed(() => {
    const groups = [];
    for (const s of props.stages) {
        const key = s.workshop ?? '';
        let g = groups.find((x) => x.key === key);
        if (!g) groups.push(g = { key, name: s.workshop, stages: [] });
        g.stages.push(s);
    }
    return groups;
});
const lastStageOf = (g) => [...g.stages].reverse().find((s) => s.is_completed)?.id ?? g.stages[g.stages.length - 1]?.id;
const sendToAct = (p) => router.post(route('projects.toAct', p.id), {}, { preserveScroll: true, preserveState: true });
// Тайминг этапа: сколько заказ уже на текущем этапе (тикает раз в минуту).
const nowTs = ref(Date.now());
let durTimer = null;
onMounted(() => (durTimer = setInterval(() => (nowTs.value = Date.now()), 60000)));
onUnmounted(() => clearInterval(durTimer));
const onStage = (p) => p.stage_entered_at ? formatDuration((nowTs.value - new Date(p.stage_entered_at).getTime()) / 1000) : null;
// Сколько заказ ВСЕГО находится в цехе (с момента отправки) — крупно на карточке.
const inWorkshop = (p) => p.created_at ? formatDuration((nowTs.value - new Date(p.created_at).getTime()) / 1000) : null;
</script>

<template>
    <Head title="Проекты" />
    <AppLayout>
        <template #header>{{ $t('page.workshop', 'Цех') }}</template>

        <PageLayout :title="$t('page.workshop', 'Цех')" subtitle="заказы по этапам цеха" full>
            <template #actions>
                <!-- Сегмент-контрол (DESIGN.md §10) -->
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5">
                    <button :class="view === 'kanban' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'" class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150" @click="switchView('kanban')">Канбан</button>
                    <button :class="view === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'" class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors duration-150" @click="switchView('list')">Список</button>
                </div>
            </template>

            <div v-if="view === 'kanban'" class="space-y-6">
            <!-- Секция цеха (DESIGN.md §6) -->
            <div v-for="g in workshopGroups" :key="g.key" class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="g.name" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">{{ g.name }}</h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ g.stages.reduce((n, s) => n + byStage(s.id).length, 0) }}</span>
                </div>
                <div class="flex gap-3 overflow-x-auto p-4">
                <div v-for="stage in g.stages" :key="stage.id" class="flex w-72 flex-shrink-0 flex-col rounded-xl border border-slate-100 bg-slate-50" @dragover.prevent @drop="onDrop(stage)">
                    <div class="flex items-center justify-between px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: stage.color }"></span>
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ stage.name }}</span>
                            <span class="text-[11px] tabular-nums text-slate-400">{{ byStage(stage.id).length }}</span>
                        </div>
                    </div>
                    <div class="flex-1 space-y-3 px-2 pb-2">
                        <!-- Карточка заказа (DESIGN.md §8) -->
                        <Link v-for="p in byStage(stage.id)" :key="p.id" :href="route('projects.show', p.id)" draggable="true" @dragstart="draggingId = p.id"
                            class="block cursor-move rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
                            <!-- Минимум для цеха: товар, номер сделки, адрес, КРУПНО время в цехе -->
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 text-sm font-semibold leading-snug text-slate-900">{{ p.deal?.client_name || p.deal?.company_name || p.name }}</div>
                                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700">{{ p.deal?.number || p.number }}</span>
                            </div>
                            <div v-if="p.deal?.address" class="mt-1 text-[11px] leading-snug text-slate-400">📍 {{ p.deal.address }}</div>
                            <!-- Ответственный — сразу видно, кто ведёт заказ -->
                            <div v-if="p.responsible" class="mt-1.5 flex items-center gap-1.5">
                                <Avatar :name="p.responsible.name" :src="p.responsible.avatar" :size="18" />
                                <span class="truncate text-[11px] font-medium text-slate-600">{{ p.responsible.name }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] tabular-nums text-slate-400">
                                <span title="Сколько заказ находится в цехе">⏱ в цехе <span class="font-medium text-slate-600">{{ inWorkshop(p) ?? '—' }}</span></span>
                                <span v-if="onStage(p)">на этапе {{ onStage(p) }}</span>
                            </div>
                            <button v-if="p.project_stage_id === lastStageOf(g)" @click.prevent.stop="sendToAct(p)" class="mt-3 w-full rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition-colors duration-150 hover:bg-emerald-100">🚚 Готово → Логистика</button>
                            <button v-else @click.prevent.stop="advance(p)" class="mt-3 w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">Далее →</button>
                        </Link>
                        <!-- Пустое состояние (DESIGN.md §12) -->
                        <div v-if="!byStage(stage.id).length" class="px-3 py-8 text-center text-sm text-slate-400">Пусто</div>
                    </div>
                </div>
                </div>
            </div>
            </div>

            <!-- Список (DESIGN.md §7) -->
            <div v-else class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">Номер</th><th class="px-4 py-2.5">Компания</th><th class="px-4 py-2.5">Клиент</th>
                                <th class="px-4 py-2.5">Ответственный</th>
                                <th class="px-4 py-2.5">Этап</th><th v-if="canSeeMoney" class="px-4 py-2.5 text-right">Бюджет</th><th class="px-4 py-2.5">Статус</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="p in projects.data" :key="p.id" class="cursor-pointer transition-colors duration-150 hover:bg-slate-50/60" @click="router.get(route('projects.show', p.id))">
                                <td class="whitespace-nowrap px-6 py-2.5 text-slate-500">{{ p.number }}</td>
                                <td class="px-4 py-2.5 font-medium text-slate-900">{{ p.deal?.company_name || p.name }}</td>
                                <td class="px-4 py-2.5 text-slate-500">{{ p.client?.name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span v-if="p.responsible" class="flex items-center gap-1.5">
                                        <Avatar :name="p.responsible.name" :src="p.responsible.avatar" :size="20" />
                                        <span class="text-xs font-medium text-slate-600">{{ p.responsible.name }}</span>
                                    </span>
                                    <span v-else class="text-xs text-slate-300">—</span>
                                </td>
                                <td class="px-4 py-2.5"><StatusBadge :status="p.stage?.name" :color="p.stage?.color" /></td>
                                <td v-if="canSeeMoney" class="whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(p.budget) }}</td>
                                <td class="px-4 py-2.5"><StatusBadge :status="p.status" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 p-4"><Pagination :links="projects.links" /></div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
