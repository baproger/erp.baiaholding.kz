<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import ResetFiltersButton from '@/Components/ResetFiltersButton.vue';

const props = defineProps({ logins: Object, results: Array, filters: Object });

const search = ref(props.filters?.search ?? '');
const result = ref(props.filters?.result ?? '');
const apply = () => router.get(route('audit.logins'), { search: search.value || undefined, result: result.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
const reset = () => { search.value = ''; result.value = ''; apply(); };
const fmt = (iso) => (iso ? new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '');
// Короткое имя браузера/устройства из user-agent: полный UA — в title.
const agent = (ua) => {
    if (!ua) return '';
    const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
    const br = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : /Firefox\//.test(ua) ? 'Firefox' : '';
    return [br, os].filter(Boolean).join(' · ') || ua.slice(0, 40);
};
</script>

<template>
    <Head title="Журнал входов" />
    <AppLayout>
        <template #header>Журнал входов</template>

        <PageLayout title="Входы" subtitle="кто, когда и откуда входил — журнал не удаляется">
            <template #actions>
                <Link :href="route('audit.index')"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-500 shadow-sm transition-colors duration-150 hover:bg-slate-50">← Журнал аудита</Link>
                <select v-model="result" @change="apply" class="rounded-full border-slate-200 bg-white py-1 pl-3 pr-8 text-xs font-medium text-slate-500 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все события</option>
                    <option v-for="r in results" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
                <input v-model="search" @keyup.enter="apply" @blur="apply" type="search" placeholder="Имя, email или IP…"
                    class="w-48 rounded-full border-slate-200 bg-white py-1 pl-3 pr-3 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <ResetFiltersButton v-if="search || result" @click="reset" />
                <span class="text-xs tabular-nums text-slate-400">записей: {{ logins.total ?? logins.data.length }}</span>
            </template>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Когда</th>
                                <th class="px-4 py-2.5">Кто</th>
                                <th class="px-4 py-2.5">Событие</th>
                                <th class="px-4 py-2.5">IP</th>
                                <th class="px-4 py-2.5">Устройство</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="l in logins.data" :key="l.id" class="transition-colors hover:bg-slate-50/60">
                                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500">{{ fmt(l.at) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-slate-800">{{ l.user || '—' }}</div>
                                    <div class="text-xs text-slate-400">{{ l.email }}</div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1"
                                        :class="l.ok ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-rose-200'">{{ l.label }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-slate-500">{{ l.ip }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-500" :title="l.agent">{{ agent(l.agent) }}</td>
                            </tr>
                            <tr v-if="!logins.data.length">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400">Записей нет</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination v-if="logins.links" :links="logins.links" class="border-t border-slate-100 px-4 py-3" />
            </div>
        </PageLayout>
    </AppLayout>
</template>
