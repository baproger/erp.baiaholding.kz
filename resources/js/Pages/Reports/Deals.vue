<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ rows: Array, totals: Object, taxRate: Number, filters: Object });

const money = (v) => new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v ?? 0));

// Серверные фильтры: поиск + период по дате создания сделки.
const search = ref(props.filters?.search ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const apply = () => router.get(route('reports.deals'), {
    search: search.value || undefined, from: from.value || undefined, to: to.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
let searchTimer = null;
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };
const reset = () => { search.value = ''; from.value = ''; to.value = ''; apply(); };

const openDeal = (id) => router.get(route('deals.show', id));
const marginClass = (m) => m >= 40 ? 'text-emerald-700' : m >= 20 ? 'text-amber-600' : 'text-rose-600';
</script>

<template>
    <Head title="Реестр сделок" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>Реестр сделок <span class="text-sm font-normal text-slate-400">· {{ totals.count }} шт · налог {{ taxRate }}%</span></span>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <input v-model="search" @input="onSearch" type="text" placeholder="Поиск: №, компания, договор, адрес…"
                            class="w-64 rounded-xl border-slate-200 py-2 pl-9 pr-3 text-sm font-normal shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">с
                        <input v-model="from" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">по
                        <input v-model="to" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <button v-if="search || from || to" @click="reset" class="rounded-lg px-2 py-1.5 text-xs font-medium text-slate-400 hover:bg-slate-100 hover:text-slate-600">✕</button>
                </div>
            </div>
        </template>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full whitespace-nowrap text-xs">
                    <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5">№</th>
                            <th class="px-3 py-2.5">№ договора / БИН</th>
                            <th class="px-3 py-2.5">Название организации</th>
                            <th class="px-3 py-2.5">Адрес доставки</th>
                            <th class="px-3 py-2.5">Товар</th>
                            <th class="px-3 py-2.5 text-right">Кол-во</th>
                            <th class="px-3 py-2.5 text-right">Сумма договора</th>
                            <th class="px-3 py-2.5 text-right">Оплачено</th>
                            <th class="px-3 py-2.5 text-right">Закуп (склад)</th>
                            <th class="px-3 py-2.5 text-right">Прочие расходы</th>
                            <th class="px-3 py-2.5 text-right">Налог</th>
                            <th class="px-3 py-2.5 text-right">Остаток</th>
                            <th class="px-3 py-2.5 text-right">Маржа %</th>
                            <th class="px-3 py-2.5 text-right">Бонус менеджера</th>
                            <th class="px-3 py-2.5 text-right">Фирма</th>
                            <th class="px-3 py-2.5">Менеджер</th>
                            <th class="px-3 py-2.5">Срок</th>
                            <th class="px-3 py-2.5">Этап</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="r in rows" :key="r.id" @click="openDeal(r.id)"
                            class="cursor-pointer transition-colors hover:bg-indigo-50/40">
                            <td class="px-3 py-2.5 font-medium text-slate-500">{{ r.number }}</td>
                            <td class="px-3 py-2.5 text-slate-500">{{ r.bin || '—' }}</td>
                            <td class="max-w-56 truncate px-3 py-2.5 font-medium text-slate-800" :title="r.company_name">{{ r.company_name || '—' }}</td>
                            <td class="max-w-48 truncate px-3 py-2.5 text-slate-500" :title="r.address">{{ r.address || '—' }}</td>
                            <td class="max-w-40 truncate px-3 py-2.5 text-slate-600" :title="r.product">{{ r.product || '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ r.qty || '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ money(r.budget) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="r.paid >= r.budget && r.budget > 0 ? 'text-emerald-600 font-semibold' : 'text-slate-600'">{{ money(r.paid) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-indigo-600">{{ money(r.material) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ money(r.other) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-rose-500">{{ money(r.tax) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="r.remainder < 0 ? 'text-rose-600 font-semibold' : 'text-slate-700'">{{ money(r.remainder) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums" :class="marginClass(r.margin)">{{ r.margin }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-emerald-600">{{ money(r.bonus) }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ money(r.company) }}</td>
                            <td class="max-w-32 truncate px-3 py-2.5 text-slate-600">{{ r.manager || '—' }}</td>
                            <td class="px-3 py-2.5 text-slate-500">{{ r.deadline || '—' }}</td>
                            <td class="px-3 py-2.5">
                                <span v-if="r.stage" class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.stage_color || '#94a3b8' }">{{ r.stage }}</span>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="18" class="px-4 py-12 text-center text-sm text-slate-400">{{ filters.search || filters.from || filters.to ? 'Ничего не найдено' : 'Сделок пока нет' }}</td></tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                        <tr>
                            <td colspan="6" class="px-3 py-3 text-right uppercase tracking-wide text-slate-500">Итого</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ money(totals.budget) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-emerald-700">{{ money(totals.paid) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-indigo-700">{{ money(totals.material) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ money(totals.other) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-rose-600">{{ money(totals.tax) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ money(totals.remainder) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ totals.margin }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-emerald-700">{{ money(totals.bonus) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ money(totals.company) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
