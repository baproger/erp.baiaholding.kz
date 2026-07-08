<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ metrics: Object, recent: Array });
const money = (v) => new Intl.NumberFormat('ru-RU').format(Math.round(v ?? 0)) + ' ₸';

// Subtle scroll parallax for the decorative shapes.
const scrollY = ref(0);
const onScroll = () => { scrollY.value = window.scrollY || document.documentElement.scrollTop || 0; };
onMounted(() => window.addEventListener('scroll', onScroll, { passive: true }));
onUnmounted(() => window.removeEventListener('scroll', onScroll));

// Count-up animation for the stat numbers.
function useCountUp(target) {
    const val = ref(0);
    onMounted(() => {
        const to = Number(target) || 0;
        const dur = 900;
        const start = performance.now();
        const step = (now) => {
            const p = Math.min(1, (now - start) / dur);
            val.value = to * (1 - Math.pow(1 - p, 3));
            if (p < 1) requestAnimationFrame(step);
            else val.value = to;
        };
        requestAnimationFrame(step);
    });
    return val;
}

const cTotal = useCountUp(props.metrics.total);
const cNet = useCountUp(props.metrics.net);
const cBonus = useCountUp(props.metrics.bonus);
const cOverdue = useCountUp(props.metrics.overdue);
const bonusPct = props.metrics.net > 0 ? Math.min(100, props.metrics.bonus / props.metrics.net * 100) : 0;

const openDeal = (id) => router.get(route('deals.show', id));
</script>

<template>
    <Head title="Дашборд" />
    <AppLayout>
        <template #header>{{ $t('page.dashboard', 'Дашборд') }}</template>

        <!-- Decorative parallax shapes -->
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
            <div class="absolute -right-24 -top-16 h-72 w-72 rounded-full bg-indigo-100/40 blur-3xl"
                :style="{ transform: `translateY(${scrollY * 0.15}px)` }"></div>
            <div class="absolute left-1/3 top-40 h-56 w-56 rounded-full bg-sky-100/40 blur-3xl"
                :style="{ transform: `translateY(${scrollY * -0.08}px)` }"></div>
            <div class="absolute -left-20 bottom-10 h-64 w-64 rounded-full bg-emerald-100/30 blur-3xl"
                :style="{ transform: `translateY(${scrollY * 0.1}px)` }"></div>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <!-- Общая сумма -->
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md" style="animation-delay: 0ms">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Общая сумма сделок</div>
                <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-slate-900">{{ money(cTotal) }}</div>
                <div class="mt-1 text-xs text-slate-400">по успешным сделкам · до налога</div>
            </div>

            <!-- Чистая прибыль -->
            <div class="rise rounded-2xl border border-transparent p-5 text-white shadow-md transition-shadow hover:shadow-lg" style="animation-delay: 80ms; background-color: #1A3B5C">
                <div class="text-xs font-medium uppercase tracking-wide text-white/60">Чистая прибыль компании</div>
                <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ money(cNet) }}</div>
                <div class="mt-1 text-xs text-white/60">после налога {{ metrics.taxRate }}%</div>
            </div>

            <!-- Бонус менеджера -->
            <div class="rise rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md" style="animation-delay: 160ms">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Бонус менеджера</span>
                    <span class="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">по марже</span>
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-emerald-600">{{ money(cBonus) }}</div>
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="bar h-1.5 rounded-full bg-emerald-500" :style="{ width: bonusPct + '%' }"></div>
                </div>
            </div>

            <!-- Просроченные -->
            <div class="rise rounded-2xl border shadow-sm transition-shadow hover:shadow-md" :class="metrics.overdue > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'" style="animation-delay: 240ms">
                <div class="p-5">
                    <div class="flex items-center gap-2">
                        <span v-if="metrics.overdue > 0" class="text-lg">⚠️</span>
                        <span class="text-xs font-medium uppercase tracking-wide" :class="metrics.overdue > 0 ? 'text-rose-500' : 'text-slate-400'">Просроченные сделки</span>
                    </div>
                    <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums" :class="metrics.overdue > 0 ? 'text-rose-600' : 'text-slate-900'">{{ Math.round(cOverdue) }}</div>
                </div>
            </div>
        </div>

        <!-- Recent deals -->
        <div class="rise mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" style="animation-delay: 320ms">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                <h3 class="text-sm font-semibold text-slate-900">Последние сделки</h3>
                <button class="text-xs font-medium text-indigo-600 hover:text-indigo-700" @click="router.get(route('deals.index'))">Все сделки →</button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                        <tr>
                            <th class="px-5 py-2.5">№</th>
                            <th class="px-5 py-2.5">Контрагент · № договора</th>
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
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(d.budget) }}</td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums text-slate-900">{{ money(d.net) }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ d.deadline || '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="d.overdue_days > 0" class="rounded-md bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700">{{ d.overdue_days }} дн.</span>
                                <span v-else class="text-slate-300">—</span>
                            </td>
                        </tr>
                        <tr v-if="!recent.length"><td colspan="6" class="px-5 py-10 text-center text-slate-400">Сделок пока нет</td></tr>
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
