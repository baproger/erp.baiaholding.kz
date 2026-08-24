<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ logs: Object, filters: Object, tables: Array, users: Array });
const actionLabel = { created: 'Создание', updated: 'Изменение', deleted: 'Удаление' };
const actionColor = { created: 'text-green-600', updated: 'text-amber-600', deleted: 'text-red-600' };
const fmt = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// Фильтры: раздел, действие, пользователь, период.
const fTable = ref(props.filters?.table ?? '');
const fAction = ref(props.filters?.action ?? '');
const fUser = ref(props.filters?.user ?? '');
const fFrom = ref(props.filters?.from ?? '');
const fTo = ref(props.filters?.to ?? '');
const apply = () => router.get(route('audit.index'), {
    table: fTable.value || undefined,
    action: fAction.value || undefined,
    user: fUser.value || undefined,
    from: fFrom.value || undefined,
    to: fTo.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const resetFilters = () => { fTable.value = ''; fAction.value = ''; fUser.value = ''; fFrom.value = ''; fTo.value = ''; apply(); };
const hasFilters = () => fTable.value || fAction.value || fUser.value || fFrom.value || fTo.value;
</script>

<template>
    <Head title="Аудит" />
    <AppLayout>
        <template #header>{{ $t('page.audit', 'Журнал аудита') }}</template>

        <PageLayout title="Аудит" subtitle="журнал изменений">
            <template #actions>
                <Link :href="route('audit.errors')"
                    class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-600 shadow-sm transition-colors duration-150 hover:bg-rose-100">⚠ Ошибки сайта</Link>
                <select v-model="fTable" @change="apply" class="rounded-full border-slate-200 bg-white py-1 pl-3 pr-8 text-xs font-medium text-slate-500 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все разделы</option>
                    <option v-for="t in tables" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <select v-model="fAction" @change="apply" class="rounded-full border-slate-200 bg-white py-1 pl-3 pr-8 text-xs font-medium text-slate-500 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все действия</option>
                    <option value="created">Создание</option>
                    <option value="updated">Изменение</option>
                    <option value="deleted">Удаление</option>
                </select>
                <select v-model="fUser" @change="apply" class="rounded-full border-slate-200 bg-white py-1 pl-3 pr-8 text-xs font-medium text-slate-500 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все пользователи</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <input v-model="fFrom" @change="apply" type="date" class="rounded-full border-slate-200 bg-white py-1 px-3 text-xs font-medium tabular-nums text-slate-500 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" title="Период с" />
                <span class="text-xs text-slate-400">—</span>
                <input v-model="fTo" @change="apply" type="date" class="rounded-full border-slate-200 bg-white py-1 px-3 text-xs font-medium tabular-nums text-slate-500 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" title="Период по" />
                <button v-if="hasFilters()" @click="resetFilters"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50">Сбросить</button>
                <span class="text-xs tabular-nums text-slate-400">записей: {{ logs.total ?? logs.data.length }}</span>
            </template>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">Время</th><th class="px-4 py-2.5">Пользователь</th>
                                <th class="px-4 py-2.5">Раздел</th><th class="px-4 py-2.5">Запись</th>
                                <th class="px-4 py-2.5">Сделка</th>
                                <th class="px-4 py-2.5">Действие</th><th class="px-4 py-2.5">Что изменили</th>
                                <th class="px-4 py-2.5">Было → Стало</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="log in logs.data" :key="log.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="whitespace-nowrap px-6 py-2.5 tabular-nums text-slate-500">{{ fmt(log.created_at) }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ log.user ?? 'Система' }}
                                    <span v-if="log.ip" class="block text-[11px] tabular-nums text-slate-300">{{ log.ip }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ log.table }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums">
                                    <Link v-if="log.link" :href="log.link" class="font-medium text-indigo-600 hover:underline">#{{ log.record_id }}</Link>
                                    <span v-else class="text-slate-400">#{{ log.record_id }}</span>
                                </td>
                                <!-- По какой сделке действие; у удалённой — номер серым без ссылки -->
                                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums">
                                    <Link v-if="log.deal && !log.deal.deleted" :href="route('deals.show', log.deal.id)" class="font-medium text-indigo-600 hover:underline">{{ log.deal.number }}</Link>
                                    <span v-else-if="log.deal" class="text-slate-400" title="Сделка удалена">{{ log.deal.number }}</span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="log.action === 'created' ? 'bg-emerald-50 text-emerald-700' : log.action === 'deleted' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ actionLabel[log.action] ?? log.action }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    <span v-if="log.field">{{ log.field }}</span>
                                    <!-- Создание/удаление: менялась вся запись целиком -->
                                    <span v-else-if="log.snapshot?.length" class="text-[11px] font-semibold uppercase tracking-wide"
                                        :class="log.action === 'deleted' ? 'text-rose-500' : 'text-emerald-600'">
                                        вся запись · {{ log.snapshot.length }} полей
                                    </span>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500">
                                    <!-- «не было» — поле раньше не заполнялось; «убрано» — значение очистили -->
                                    <span v-if="log.field"><span :class="log.old ? 'text-rose-600' : 'italic text-slate-300'">{{ log.old ?? 'не было' }}</span> → <span :class="log.new ? 'text-emerald-600' : 'italic text-slate-300'">{{ log.new ?? 'убрано' }}</span></span>
                                    <!-- Снимок: что именно исчезло (или с чем появилось) -->
                                    <div v-else-if="log.snapshot?.length" class="flex flex-wrap gap-1">
                                        <span v-for="f in log.snapshot" :key="f.label"
                                            class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="log.action === 'deleted' ? 'bg-rose-50 text-rose-600 line-through decoration-rose-300' : 'bg-emerald-50 text-emerald-700'">
                                            {{ f.label }}: <b class="font-semibold">{{ f.value }}</b>
                                        </span>
                                    </div>
                                    <span v-else class="text-slate-300">—</span>
                                </td>
                            </tr>
                            <tr v-if="!logs.data.length"><td colspan="8" class="px-6 py-10 text-center text-sm text-slate-400">Записей нет — измените фильтры</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 p-4"><Pagination :links="logs.links" /></div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
