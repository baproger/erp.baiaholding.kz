<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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

        <div v-if="deals.length" class="overflow-hidden rounded-xl bg-white shadow-sm border border-slate-200">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Просрочено</th>
                        <th class="px-4 py-3">Компания</th>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Этап</th>
                        <th class="px-4 py-3">Сумма</th>
                        <th class="px-4 py-3">Срок</th>
                        <th class="px-4 py-3">Ответственный</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="deal in deals" :key="deal.id" class="cursor-pointer transition-colors hover:bg-red-50/60" @click="open(deal.id)">
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700">
                                {{ deal.overdue_days }} {{ dayLabel(deal.overdue_days) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-900">
                            {{ deal.company_name || deal.name }}
                            <span class="block text-[11px] text-slate-400">{{ deal.number }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.client_name || '—' }}</td>
                        <td class="px-4 py-3"><StatusBadge :status="deal.stage?.name" :color="deal.stage?.color" /></td>
                        <td class="px-4 py-3 font-semibold text-indigo-600">{{ money(deal.budget) }}</td>
                        <td class="px-4 py-3 font-semibold text-red-600">{{ formatDate(deal.deadline) }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ deal.responsible?.name ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl bg-white p-12 text-center shadow-sm border border-slate-200">
            <div class="text-4xl">✅</div>
            <p class="mt-3 text-sm text-slate-500">Просроченных сделок нет.</p>
        </div>

        <!-- Просроченные заказы цеха (свой дедлайн у заказа) -->
        <div v-if="projects.length" class="mt-6">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">Просроченные заказы цеха <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">{{ projects.length }}</span></h3>
            <div class="overflow-x-auto rounded-xl bg-white shadow-sm border border-slate-200">
                <table class="min-w-full whitespace-nowrap divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Просрочено</th>
                            <th class="px-4 py-3">Заказ</th>
                            <th class="px-4 py-3">Компания (сделка)</th>
                            <th class="px-4 py-3">Этап цеха</th>
                            <th class="px-4 py-3">Срок</th>
                            <th class="px-4 py-3">Ответственный</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="p in projects" :key="p.id" class="cursor-pointer transition-colors hover:bg-red-50/60" @click="openProject(p.id)">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700">
                                    {{ p.overdue_days }} {{ dayLabel(p.overdue_days) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ p.number }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ p.deal?.company_name || p.name }}
                                <span class="block text-[11px] text-slate-400">{{ p.deal?.number }}</span>
                            </td>
                            <td class="px-4 py-3"><StatusBadge :status="p.stage?.name" :color="p.stage?.color" /></td>
                            <td class="px-4 py-3 font-semibold text-red-600">{{ formatDate(p.deadline) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ p.responsible?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
