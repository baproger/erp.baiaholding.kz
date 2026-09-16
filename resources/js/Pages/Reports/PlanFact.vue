<script setup>
// «План / Факт» по предсделкам (только админ и директор): что менеджер
// предпосчитал в лоте против факта по сделке — и разница по расходам и марже.
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { money } from '@/utils/format';
import { useStickyFilters, clearStickyFilters } from '@/composables/useStickyFilters';
import ResetFiltersButton from '@/Components/ResetFiltersButton.vue';

const props = defineProps({ rows: { type: Array, default: () => [] }, totals: Object, filters: Object, managers: { type: Array, default: () => [] } });

// Серверные фильтры: менеджер, период внесения лота, статус сделки.
const managerF = ref(props.filters?.manager ?? '');
const fromF = ref(props.filters?.from ?? '');
const toF = ref(props.filters?.to ?? '');
const statusF = ref(props.filters?.status ?? '');
const apply = () => router.get(route('reports.planFact'), {
    manager: managerF.value || undefined, from: fromF.value || undefined,
    to: toF.value || undefined, status: statusF.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const hasFilters = () => managerF.value || fromF.value || toF.value || statusF.value || onlyDiff.value;
const reset = () => { managerF.value = ''; fromF.value = ''; toF.value = ''; statusF.value = ''; onlyDiff.value = false; clearStickyFilters('reports.planfact'); apply(); };
useStickyFilters('reports.planfact', { managerF, fromF, toF, statusF }, apply);

// Клиентские: поиск + «только отклонения» (перерасход или маржа ниже плана).
const search = ref('');
const onlyDiff = ref(false);
const list = computed(() => props.rows.filter((r) => {
    if (onlyDiff.value && !(r.diff.expense > 0 || r.diff.margin < 0)) return false;
    const t = search.value.trim().toLowerCase();
    if (!t) return true;
    return [r.number, r.customer, r.manager].some((v) => (v ?? '').toLowerCase().includes(t));
}));

// Разница: расходы «+» — потратил больше плана (плохо, красным);
// маржа/остаток «−» — заработал меньше обещанного (плохо, красным).
const diffMoney = (v, badWhenPositive = false) => v === 0 ? 'text-slate-300'
    : ((v > 0) === badWhenPositive ? 'text-rose-600' : 'text-emerald-600');
const sign = (v) => (v > 0 ? '+' : '') + money(v);
const signPct = (v) => (v > 0 ? '+' : '') + v + '%';
</script>

<template>
    <Head title="План / Факт" />
    <AppLayout>
        <template #header>План / Факт по предсделкам</template>

        <PageLayout title="План / Факт" subtitle="расчёт менеджера в лоте против факта по сделке" full>
            <template #actions>
                <Link :href="route('reports.deals')"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">← Сводный отчёт</Link>
            </template>

            <!-- Фильтры: менеджер, период внесения лота, статус сделки, отклонения, поиск -->
            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <input v-model="search" type="search" placeholder="Поиск: №, заказчик, менеджер"
                    class="w-52 rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                <select v-model="managerF" @change="apply" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                    <option value="">Все менеджеры</option>
                    <option v-for="m in managers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
                <select v-model="statusF" @change="apply" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" title="Статус сделки">
                    <option value="">Все сделки</option>
                    <option value="won">Выигранные (закрытые)</option>
                    <option value="active">В работе</option>
                </select>
                <label class="flex items-center gap-1 text-xs text-slate-400" title="Период внесения лота">с
                    <input v-model="fromF" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <label class="flex items-center gap-1 text-xs text-slate-400">по
                    <input v-model="toF" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-sm text-slate-600 shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <!-- Быстрый тумблер: показать только тех, кто вышел за план -->
                <button type="button" @click="onlyDiff = !onlyDiff"
                    class="rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
                    :class="onlyDiff ? 'border-rose-400 bg-rose-50 text-rose-600 ring-2 ring-rose-500/20' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'"
                    title="Перерасход против плана или маржа ниже обещанной">⚠ Только отклонения</button>
                <ResetFiltersButton v-if="hasFilters()" @click="reset" />
                <span class="ml-auto text-xs tabular-nums text-slate-400">показано: {{ list.length }} из {{ rows.length }}</span>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full whitespace-nowrap text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr class="divide-x divide-slate-200">
                                <th class="px-4 py-2.5" rowspan="2">Сделка</th>
                                <th class="px-4 py-2.5" rowspan="2">Менеджер · этап</th>
                                <th class="px-4 py-2.5 text-right" rowspan="2">Сумма договора</th>
                                <th class="border-b border-slate-200 bg-indigo-50/50 px-4 py-1.5 text-center text-indigo-500" colspan="4">План менеджера (лот)</th>
                                <th class="border-b border-slate-200 bg-emerald-50/50 px-4 py-1.5 text-center text-emerald-600" colspan="4">Факт по сделке</th>
                                <th class="border-b border-slate-200 bg-amber-50/50 px-4 py-1.5 text-center text-amber-600" colspan="3">Разница (факт − план)</th>
                            </tr>
                            <tr class="divide-x divide-slate-200">
                                <th class="bg-indigo-50/40 px-3 py-1.5 text-right">Расходы</th>
                                <th class="bg-indigo-50/40 px-3 py-1.5 text-center">Маржа</th>
                                <th class="bg-indigo-50/40 px-3 py-1.5 text-right">Бонус</th>
                                <th class="bg-indigo-50/40 px-3 py-1.5 text-right" title="Остаток − бонус: сколько планировалось фирме чистыми">Фирме</th>
                                <th class="bg-emerald-50/40 px-3 py-1.5 text-right">Расходы</th>
                                <th class="bg-emerald-50/40 px-3 py-1.5 text-center">Маржа</th>
                                <th class="bg-emerald-50/40 px-3 py-1.5 text-right">Бонус</th>
                                <th class="bg-emerald-50/40 px-3 py-1.5 text-right" title="Остаток − бонус: сколько фирме остаётся чистыми по факту">Фирме</th>
                                <th class="bg-amber-50/40 px-3 py-1.5 text-right">Расходы</th>
                                <th class="bg-amber-50/40 px-3 py-1.5 text-center">Маржа</th>
                                <th class="bg-amber-50/40 px-3 py-1.5 text-right" title="Факт «Фирме» минус план">Фирме</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in list" :key="r.deal_id" class="divide-x divide-slate-100 transition-colors hover:bg-slate-50/60">
                                <td class="px-4 py-2.5">
                                    <Link :href="route('deals.show', r.deal_id)" class="font-semibold text-indigo-600 hover:underline">{{ r.number }}</Link>
                                    <div class="max-w-52 truncate text-xs text-slate-400" :title="r.customer">{{ r.customer }}</div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="text-sm font-medium text-slate-700">{{ r.manager ?? '—' }}</div>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: (r.stage_color || '#94a3b8') + '22', color: r.stage_color || '#64748b' }">{{ r.stage ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">{{ money(r.budget) }}</td>
                                <!-- План -->
                                <td class="bg-indigo-50/20 px-3 py-2.5 text-right tabular-nums text-slate-600">{{ money(r.plan.expense) }}</td>
                                <td class="bg-indigo-50/20 px-3 py-2.5 text-center tabular-nums text-slate-600">{{ r.plan.margin }}%</td>
                                <td class="bg-indigo-50/20 px-3 py-2.5 text-right tabular-nums text-emerald-700">{{ money(r.plan.bonus) }}</td>
                                <td class="bg-indigo-50/20 px-3 py-2.5 text-right font-semibold tabular-nums text-slate-700">{{ money(r.plan.net) }}</td>
                                <!-- Факт -->
                                <td class="bg-emerald-50/20 px-3 py-2.5 text-right tabular-nums text-slate-800">{{ money(r.fact.expense) }}</td>
                                <td class="bg-emerald-50/20 px-3 py-2.5 text-center font-semibold tabular-nums" :class="r.fact.margin < 0 ? 'text-rose-600' : 'text-slate-800'">{{ r.fact.margin }}%</td>
                                <td class="bg-emerald-50/20 px-3 py-2.5 text-right tabular-nums text-emerald-700">{{ money(r.fact.bonus) }}</td>
                                <td class="bg-emerald-50/20 px-3 py-2.5 text-right font-bold tabular-nums" :class="r.fact.net < 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(r.fact.net) }}</td>
                                <!-- Разница -->
                                <td class="bg-amber-50/20 px-3 py-2.5 text-right font-semibold tabular-nums" :class="diffMoney(r.diff.expense, true)" :title="r.diff.expense > 0 ? 'Потратили больше плана' : 'Уложились в план'">{{ sign(r.diff.expense) }}</td>
                                <td class="bg-amber-50/20 px-3 py-2.5 text-center font-semibold tabular-nums" :class="diffMoney(r.diff.margin)" :title="r.diff.margin < 0 ? 'Маржа ниже обещанной в лоте' : 'Маржа не хуже плана'">{{ signPct(r.diff.margin) }}</td>
                                <td class="bg-amber-50/20 px-3 py-2.5 text-right font-bold tabular-nums" :class="diffMoney(r.diff.net)" :title="r.diff.net < 0 ? 'Фирме остаётся меньше, чем планировал менеджер' : 'Фирме не хуже плана'">{{ sign(r.diff.net) }}</td>
                            </tr>
                            <tr v-if="!list.length">
                                <td colspan="14" class="px-6 py-12 text-center text-sm text-slate-400">Сделок из предсделок пока нет</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="list.length" class="border-t-2 border-slate-200 bg-slate-50 text-sm font-semibold">
                            <tr class="divide-x divide-slate-200">
                                <td class="px-4 py-2.5 text-slate-500" colspan="3">Итого · {{ list.length }} сделок</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-600">{{ money(totals.plan_expense) }}</td>
                                <td></td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-emerald-700">{{ money(totals.plan_bonus) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ money(totals.plan_net) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-800">{{ money(totals.fact_expense) }}</td>
                                <td></td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-emerald-700">{{ money(totals.fact_bonus) }}</td>
                                <td class="px-3 py-2.5 text-right font-bold tabular-nums text-slate-900">{{ money(totals.fact_net) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums" :class="diffMoney(totals.fact_expense - totals.plan_expense, true)">{{ sign(Math.round((totals.fact_expense - totals.plan_expense) * 100) / 100) }}</td>
                                <td></td>
                                <td class="px-3 py-2.5 text-right font-bold tabular-nums" :class="diffMoney(totals.fact_net - totals.plan_net)">{{ sign(Math.round((totals.fact_net - totals.plan_net) * 100) / 100) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-400">💡 План — справочные цифры менеджера из предсделки. Факт — подтверждённые расходы по сделке на текущий момент (по незавершённым сделкам расходы ещё могут добавляться — смотрите на этап). «Бонус» — по шкале от маржи (в факте — с ручным % финансиста, если задан); «Фирме» = остаток − бонус, чистая сумма компании. Красные расходы «+» — потратили больше плана; красное «Фирме −» — компании остаётся меньше, чем обещал расчёт лота.</p>
        </PageLayout>
    </AppLayout>
</template>
