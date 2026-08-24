<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ errors: Object, filters: Object });

const fmt = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });

const search = ref(props.filters?.search ?? '');
const apply = () => router.get(route('audit.errors'), { search: search.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });

// Раскрытие трейса по клику на строку (только одна раскрыта).
const openId = ref(null);
const toggle = (id) => (openId.value = openId.value === id ? null : id);

const methodClass = (m) => ({
    GET: 'bg-slate-100 text-slate-500',
    POST: 'bg-emerald-50 text-emerald-700',
    PUT: 'bg-amber-50 text-amber-700',
    PATCH: 'bg-amber-50 text-amber-700',
    DELETE: 'bg-rose-50 text-rose-700',
}[m] || 'bg-slate-100 text-slate-500');
</script>

<template>
    <Head title="Ошибки сайта" />
    <AppLayout>
        <template #header>Журнал ошибок</template>

        <PageLayout title="Ошибки сайта" subtitle="серверные ошибки — журнал не удаляется">
            <template #actions>
                <Link :href="route('audit.index')"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-500 shadow-sm transition-colors duration-150 hover:bg-slate-50">← Журнал аудита</Link>
                <input v-model="search" @keyup.enter="apply" @blur="apply" type="search" placeholder="Поиск: текст, класс, URL"
                    class="w-56 rounded-full border-slate-200 bg-white py-1 px-3 text-xs font-medium text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <span class="text-xs tabular-nums text-slate-400">записей: {{ errors.total ?? errors.data.length }}</span>
            </template>

            <!-- Пользователи ошибок не видят: им показывается аккуратная страница
                 «что-то пошло не так», а сюда пишется техника. Удаления нет. -->
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                            <tr class="divide-x divide-slate-200">
                                <th class="px-4 py-2.5">Когда</th>
                                <th class="px-4 py-2.5">Кто</th>
                                <th class="px-4 py-2.5">Запрос</th>
                                <th class="px-4 py-2.5">Ошибка</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template v-for="e in errors.data" :key="e.id">
                                <tr class="cursor-pointer divide-x divide-slate-100 align-top transition-colors hover:bg-slate-50/70"
                                    :class="openId === e.id ? 'bg-slate-50' : ''" @click="toggle(e.id)">
                                    <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500">{{ fmt(e.at) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <div class="font-medium text-slate-700">{{ e.user || 'гость' }}</div>
                                        <div class="text-xs tabular-nums text-slate-400">{{ e.ip }}</div>
                                    </td>
                                    <td class="max-w-[18rem] px-4 py-2.5">
                                        <span class="rounded-md px-1.5 py-0.5 text-xs font-bold" :class="methodClass(e.method)">{{ e.method }}</span>
                                        <span class="ml-1 break-all text-xs text-slate-500">{{ e.url }}</span>
                                    </td>
                                    <td class="max-w-[28rem] px-4 py-2.5">
                                        <span class="rounded-md bg-rose-50 px-1.5 py-0.5 text-xs font-semibold text-rose-700">{{ e.exception }}</span>
                                        <div class="mt-1 break-words leading-snug text-slate-700">{{ e.message }}</div>
                                        <div v-if="e.file" class="mt-0.5 break-all text-xs text-slate-400">{{ e.file }}</div>
                                    </td>
                                </tr>
                                <tr v-if="openId === e.id" class="bg-slate-900">
                                    <td colspan="4" class="px-4 py-3">
                                        <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-all text-xs leading-relaxed text-slate-300">{{ e.trace || 'Трейс не записан' }}</pre>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!errors.data.length">
                                <td colspan="4" class="px-4 py-12 text-center text-sm text-slate-400">
                                    🎉 Ошибок нет — сайт работает чисто
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :links="errors.links" class="border-t border-slate-100 px-4 py-3" />
            </div>
            <p class="mt-3 text-xs text-slate-400">Журнал только для чтения: записи не удаляются и не редактируются. Нажмите на строку, чтобы раскрыть технический трейс.</p>
        </PageLayout>
    </AppLayout>
</template>
