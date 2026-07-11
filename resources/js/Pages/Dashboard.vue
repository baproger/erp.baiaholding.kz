<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    metrics: Object, attention: Object, funnel: Array,
    period: Object, topManagers: Array, recent: Array, filters: Object,
});
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Subtle scroll parallax for the decorative shapes.
const scrollY = ref(0);
const onScroll = () => { scrollY.value = window.scrollY || document.documentElement.scrollTop || 0; };
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }));
onUnmounted(() => window.removeEventListener('scroll', onScroll));

// Count-up animation for the stat numbers. Принимает getter и следит за ним:
// при смене компании (BAIA/ASU/Все) Inertia обновляет props без пересоздания
// компонента — цифры должны переанимироваться на новые значения.
function useCountUp(getter) {
    const val = ref(0);
    const animate = (to) => {
        to = Number(to) || 0;
        const fromVal = val.value;
        const dur = 900;
        const start = performance.now();
        const step = (now) => {
            const p = Math.min(1, (now - start) / dur);
            val.value = fromVal + (to - fromVal) * (1 - Math.pow(1 - p, 3));
            if (p < 1) requestAnimationFrame(step);
            else val.value = to;
        };
        requestAnimationFrame(step);
    };
    watch(getter, animate);
    onMounted(() => animate(getter()));
    return val;
}

const cTotal = useCountUp(() => props.metrics.total);
const cNet = useCountUp(() => props.metrics.net);
const cExpense = useCountUp(() => props.metrics.expense);
const cDebt = useCountUp(() => props.metrics.debt);

// Поиск по сделкам + период (менеджеры и факт за период) — серверные фильтры.
const search = ref(props.filters?.search ?? '');
const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
let searchTimer = null;
const apply = () => router.get(route('dashboard'), {
    search: search.value || undefined, from: from.value || undefined, to: to.value || undefined,
}, { preserveState: true, preserveScroll: true, replace: true });
const onSearch = () => { clearTimeout(searchTimer); searchTimer = setTimeout(apply, 350); };

const maxFunnel = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));
const openDeal = (id) => router.get(route('deals.show', id));
</script>

<template>
    <Head title="Дашборд" />
    <AppLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $t('page.dashboard', 'Дашборд') }}</span>
                <!-- Поиск и период -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                        <input v-model="search" @input="onSearch" type="text" placeholder="Поиск: №, контрагент, договор…"
                            class="w-64 rounded-xl border-slate-200 py-2 pl-9 pr-3 text-sm font-normal shadow-sm transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">с
                        <input v-model="from" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                    <label class="flex items-center gap-1 text-xs font-normal text-slate-400">по
                        <input v-model="to" @change="apply" type="date" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                    </label>
                </div>
            </div>
        </template>

        <!-- Decorative parallax shapes -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
            <div class="absolute -right-24 -top-16 h-72 w-72 rounded-full bg-indigo-100/40 blur-3xl"
                :style="{ transform: `translateY(${scrollY * 0.15}px)` }"></div>
            <div class="absolute left-1/3 top-40 h-56 w-56 rounded-full bg-sky-100/40 blur-3xl"
                :style="{ transform: `translateY(${scrollY * -0.08}px)` }"></div>
            <div class="absolute -left-20 bottom-10 h-64 w-64 rounded-full bg-emerald-100/30 blur-3xl"
                :style="{ transform: `translateY(${scrollY * 0.1}px)` }"></div>
        </div>

        <!-- 1. Деньги -->
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-5">
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md" style="animation-delay: 0ms">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Сумма договоров</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{{ money(cTotal) }}</div>
                <div class="mt-1 text-xs text-slate-400">оплачено {{ money(metrics.paid) }}</div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md" style="animation-delay: 60ms">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Расходы</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-red-600">−{{ money(cExpense) }}</div>
                <div class="mt-1 text-xs text-slate-400">ЗП/бонусы −{{ money(metrics.salaries) }}</div>
            </div>
            <div class="rise rounded-2xl border border-transparent p-5 text-white shadow-md transition-shadow hover:shadow-lg" style="animation-delay: 120ms; background-color: #1A3B5C">
                <div class="text-xs font-medium uppercase tracking-wide text-white/60">Чистая прибыль</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">{{ money(cNet) }}</div>
                <div class="mt-1 text-xs text-white/60">после налога {{ metrics.taxRate }}%</div>
            </div>
            <div class="rise rounded-2xl border p-5 shadow-sm transition-shadow hover:shadow-md" :class="metrics.debt > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'" style="animation-delay: 180ms">
                <div class="text-xs font-medium uppercase tracking-wide" :class="metrics.debt > 0 ? 'text-rose-500' : 'text-slate-400'">Долг клиентов</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums" :class="metrics.debt > 0 ? 'text-rose-600' : 'text-slate-900'">{{ money(cDebt) }}</div>
                <div class="mt-1 text-xs" :class="metrics.debt > 0 ? 'text-rose-400' : 'text-slate-400'">по выставленным счетам</div>
            </div>
            <div class="rise col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-1" style="animation-delay: 240ms">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">За период</div>
                <div class="mt-1.5 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-400">Оплачено</span><b class="tabular-nums text-emerald-600">{{ money(period.paid) }}</b></div>
                    <div class="flex justify-between"><span class="text-slate-400">Расходы</span><b class="tabular-nums text-red-600">−{{ money(period.expenses) }}</b></div>
                    <div class="flex justify-between"><span class="text-slate-400">Новых сделок</span><b class="tabular-nums text-slate-800">{{ period.newDeals }}</b></div>
                </div>
            </div>
        </div>

        <!-- 2. Требует внимания -->
        <div class="mt-4 grid grid-cols-2 gap-4 xl:grid-cols-4">
            <Link :href="route('deals.overdue')" class="rise rounded-2xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.overdueDeals > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'" style="animation-delay: 280ms">
                <div class="text-xs font-medium" :class="attention.overdueDeals > 0 ? 'text-rose-500' : 'text-slate-400'">⚠️ Просроченные сделки</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.overdueDeals > 0 ? 'text-rose-600' : 'text-slate-900'">{{ attention.overdueDeals }}</div>
            </Link>
            <div class="rise rounded-2xl border p-4 shadow-sm" :class="attention.overdueTasks > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'" style="animation-delay: 320ms">
                <div class="text-xs font-medium" :class="attention.overdueTasks > 0 ? 'text-amber-600' : 'text-slate-400'">Просроченные задачи</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.overdueTasks > 0 ? 'text-amber-600' : 'text-slate-900'">{{ attention.overdueTasks }}</div>
            </div>
            <Link :href="route('finance.index', { exp_status: 'pending' })" class="rise rounded-2xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.pendingExpenses.count > 0 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'" style="animation-delay: 360ms">
                <div class="text-xs font-medium" :class="attention.pendingExpenses.count > 0 ? 'text-amber-600' : 'text-slate-400'">Расходы ждут бухгалтера ({{ attention.pendingExpenses.count }})</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.pendingExpenses.count > 0 ? 'text-amber-600' : 'text-slate-900'">{{ money(attention.pendingExpenses.sum) }}</div>
            </Link>
            <Link :href="route('warehouse.index')" class="rise rounded-2xl border p-4 shadow-sm transition hover:shadow-md" :class="attention.zeroMaterials > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'" style="animation-delay: 400ms">
                <div class="text-xs font-medium" :class="attention.zeroMaterials > 0 ? 'text-rose-500' : 'text-slate-400'">Материалы на нуле</div>
                <div class="mt-1 text-2xl font-bold tabular-nums" :class="attention.zeroMaterials > 0 ? 'text-rose-600' : 'text-slate-900'">{{ attention.zeroMaterials }}</div>
            </Link>
        </div>

        <!-- 3. Воронка + топ менеджеров -->
        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2" style="animation-delay: 440ms">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">Воронка — активные сделки по этапам</h3>
                    <Link :href="route('deals.index')" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Канбан →</Link>
                </div>
                <div class="space-y-2">
                    <div v-for="f in funnel" :key="f.name" class="flex items-center gap-3">
                        <div class="w-40 truncate text-xs text-slate-500">{{ f.name }}</div>
                        <div class="h-5 flex-1 overflow-hidden rounded-md bg-slate-100">
                            <div class="bar flex h-5 items-center rounded-md px-2 text-[11px] font-bold text-white"
                                :style="{ width: Math.max(6, f.count / maxFunnel * 100) + '%', backgroundColor: f.color || '#6366f1' }">
                                {{ f.count }}
                            </div>
                        </div>
                        <div class="w-28 text-right text-xs tabular-nums text-slate-400">{{ money(f.sum) }}</div>
                    </div>
                    <div v-if="!funnel.length" class="py-6 text-center text-sm text-slate-400">Нет этапов</div>
                </div>
            </div>
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" style="animation-delay: 480ms">
                <h3 class="mb-3 text-sm font-semibold text-slate-900">Топ менеджеров <span class="font-normal text-slate-400">за период</span></h3>
                <div class="space-y-2">
                    <div v-for="(m, i) in topManagers" :key="m.user" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                        <div class="flex items-center gap-2 text-sm"><span class="text-xs font-bold text-slate-300">{{ i + 1 }}</span><span class="font-medium text-slate-800">{{ m.user }}</span></div>
                        <div class="text-right">
                            <div class="text-sm font-bold tabular-nums text-slate-800">{{ money(m.budget) }}</div>
                            <div class="text-[11px] text-slate-400">{{ m.deals }} сделок</div>
                        </div>
                    </div>
                    <div v-if="!topManagers.length" class="py-6 text-center text-sm text-slate-400">Нет сделок за период</div>
                </div>
            </div>
        </div>

        <!-- 4. Сделки (поиск фильтрует этот список) -->
        <div class="rise mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" style="animation-delay: 520ms">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                <h3 class="text-sm font-semibold text-slate-900">{{ filters.search ? `Найдено по «${filters.search}»` : 'Последние сделки' }}</h3>
                <button class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="router.get(route('deals.index'))">Все сделки →</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-5 py-2.5">№</th>
                            <th class="px-5 py-2.5">Контрагент · № договора</th>
                            <th class="px-5 py-2.5">Этап</th>
                            <th class="px-5 py-2.5 text-right">Сумма общая</th>
                            <th class="px-5 py-2.5 text-right">Сумма чистая</th>
                            <th class="px-5 py-2.5">Дедлайн</th>
                            <th class="px-5 py-2.5 text-right">Просрочка</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="d in recent" :key="d.id" @click="openDeal(d.id)"
                            class="cursor-pointer transition-colors" :class="d.overdue_days > 0 ? 'bg-rose-50/50 hover:bg-rose-50' : 'hover:bg-slate-50'">
                            <td class="px-5 py-3 text-slate-400">{{ d.number }}</td>
                            <td class="px-5 py-3">
                                <div class="font-medium text-slate-800">{{ d.company || '—' }}</div>
                                <div class="text-xs text-slate-400">{{ d.bin || 'без № договора' }}</div>
                            </td>
                            <td class="px-5 py-3"><span v-if="d.stage" class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white" :style="{ backgroundColor: d.color || '#94a3b8' }">{{ d.stage }}</span></td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums text-slate-900">{{ money(d.net) }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ d.deadline || '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="d.overdue_days > 0" class="rounded-md bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700">{{ d.overdue_days }} дн.</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                        </tr>
                        <tr v-if="!recent.length"><td colspan="7" class="px-5 py-10 text-center text-slate-400">{{ filters.search ? 'Ничего не найдено' : 'Сделок пока нет' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
@keyframes rise {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.rise {
    opacity: 0;
    animation: rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.bar {
    animation: grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    transform-origin: left;
}
@keyframes grow {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
}
</style>
