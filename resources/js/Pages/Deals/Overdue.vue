<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate, money } from '@/utils/format';

defineProps({ deals: Array, projects: { type: Array, default: () => [] } });

const open = (id) => router.get(route('deals.show', id));
const openProject = (id) => router.get(route('projects.show', id));
const dayLabel = (n) => {
    const abs = Math.abs(n) % 100;
    const last = abs % 10;
    if (abs > 10 && abs < 20) return 'дней';
    if (last === 1) return 'день';
    if (last >= 2 && last <= 4) return 'дня';
    return 'дней';
};
</script>

<template>
    <Head title="Просроченные сделки" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('deals.index')" class="text-slate-400 hover:text-slate-600">← {{ $t('page.deals', 'Сделки') }}</Link>
                <span>{{ $t('page.overdue', 'Просроченные сделки') }}</span>
            </div>
        </template>

        <PageLayout title="Просроченные" subtitle="сделки и заказы цеха с истёкшим сроком">
        <template #actions>
            <Link :href="route('deals.index')" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">← Сделки</Link>
        </template>

        <!-- Плитки-итоги -->
        <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <div class="rounded-xl border p-4 shadow-sm" :class="deals.length ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                <div class="text-[11px] uppercase tracking-wide" :class="deals.length ? 'text-rose-500' : 'text-slate-400'">Сделки</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="deals.length ? 'text-rose-600' : 'text-slate-800'">{{ deals.length }}</div>
            </div>
            <div class="rounded-xl border p-4 shadow-sm" :class="projects.length ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'">
                <div class="text-[11px] uppercase tracking-wide" :class="projects.length ? 'text-rose-500' : 'text-slate-400'">Цех — заказы</div>
                <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="projects.length ? 'text-rose-600' : 'text-slate-800'">{{ projects.length }}</div>
            </div>
        </div>

        <!-- Две колонки: слева сделки, справа заказы цеха -->
        <div class="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
            <!-- Сделки -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Сделки</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" :class="deals.length ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'">{{ deals.length }}</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <button v-for="deal in deals" :key="deal.id" type="button" @click="open(deal.id)"
                        class="block w-full px-6 py-3 text-left transition-colors duration-150 hover:bg-slate-50/60">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="flex-shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-600">{{ deal.overdue_days }} {{ dayLabel(deal.overdue_days) }}</span>
                                <span class="truncate text-sm font-semibold text-slate-900">{{ deal.company_name || deal.name }}</span>
                            </div>
                            <span class="flex-shrink-0 whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800">{{ money(deal.budget) }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-400">
                            <span>{{ deal.number }}</span>
                            <StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" />
                            <span class="whitespace-nowrap font-medium text-rose-600">срок {{ formatDate(deal.deadline) }}</span>
                            <span class="ml-auto">{{ deal.responsible?.name ?? '—' }}</span>
                        </div>
                    </button>
                    <div v-if="!deals.length" class="px-6 py-10 text-center">
                        <div class="text-3xl">✅</div>
                        <p class="mt-2 text-sm text-slate-400">Просроченных сделок нет</p>
                    </div>
                </div>
            </div>

            <!-- Цех -->
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Цех — заказы</h3>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium tabular-nums" :class="projects.length ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'">{{ projects.length }}</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <button v-for="p in projects" :key="p.id" type="button" @click="openProject(p.id)"
                        class="block w-full px-6 py-3 text-left transition-colors duration-150 hover:bg-slate-50/60">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="flex-shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-600">{{ p.overdue_days }} {{ dayLabel(p.overdue_days) }}</span>
                                <span class="truncate text-sm font-semibold text-slate-900">{{ p.deal?.company_name || p.name }}</span>
                            </div>
                            <span class="flex-shrink-0 whitespace-nowrap text-[11px] font-medium tabular-nums text-slate-400">{{ p.number }}</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-400">
                            <span>{{ p.deal?.number }}</span>
                            <StatusBadge :status="p.stage?.name" :color="p.stage?.color" />
                            <span class="whitespace-nowrap font-medium text-rose-600">срок {{ formatDate(p.deadline) }}</span>
                            <span class="ml-auto">{{ p.responsible?.name ?? '—' }}</span>
                        </div>
                    </button>
                    <div v-if="!projects.length" class="px-6 py-10 text-center">
                        <div class="text-3xl">✅</div>
                        <p class="mt-2 text-sm text-slate-400">Просроченных заказов цеха нет</p>
                    </div>
                </div>
            </div>
        </div>
        </PageLayout>
    </AppLayout>
</template>
