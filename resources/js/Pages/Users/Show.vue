<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import Avatar from '@/Components/Avatar.vue';
import { useStickyFilters } from '@/composables/useStickyFilters';

const props = defineProps({
    person: Object,
    deals: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
    payrollRow: { type: Object, default: null },
    adjustments: { type: Array, default: () => [] },
    month: { type: String, default: '' },
    debts: { type: Array, default: () => [] },
    debtPlan: { type: Object, default: null },
    can: { type: Object, default: () => ({ manage: false }) },
});

// Месяц денежных блоков (корректировки + долг) — как на стр. Зарплата.
const monthSel = ref(props.month);
const setMonth = () => router.get(route('users.show', props.person.id),
    { month: monthSel.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('user-card', { monthSel }, setMonth);

const monthLabel = computed(() => props.month
    ? new Date(props.month + '-01T00:00:00').toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' })
    : '');

const roleLabels = { admin: 'СЕО (админ)', director: 'Директор', financist: 'Финансист-Бухгалтер', manager: 'Менеджер', employee: 'Сотрудник (цех)', lawyer: 'Юрист', cook: 'Повар', designer: 'Дизайнер', supplier: 'Снабженец' };
const adjLabels = { absence: 'Отгул', sick: 'Больничный', fine: 'Штраф', advance: 'Аванс', bonus: 'Премия' };
const taskStatusLabels = { todo: 'К выполнению', in_progress: 'В работе', done: 'Готово' };

const fmt = (v) => (v === null || v === undefined) ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₸';
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('ru-RU') : '—';

const tenure = computed(() => {
    if (!props.person.hired_at) return null;
    const from = new Date(props.person.hired_at);
    const now = new Date();
    let months = (now.getFullYear() - from.getFullYear()) * 12 + now.getMonth() - from.getMonth();
    if (now.getDate() < from.getDate()) months--;
    months = Math.max(0, months);
    const y = Math.floor(months / 12);
    const m = months % 12;
    const parts = [];
    if (y) parts.push(`${y} г.`);
    if (m || !y) parts.push(`${m} мес.`);
    return parts.join(' ');
});

const stats = computed(() => ({
    deals: props.deals.length,
    won: props.deals.filter((d) => d.is_won).length,
    projects: props.projects.filter((p) => !['completed', 'cancelled'].includes(p.status)).length,
    tasks: props.tasks.filter((t) => t.status !== 'done').length,
}));
</script>

<template>
    <Head :title="person.name" />
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('users.index')" class="text-slate-400 transition-colors hover:text-slate-600">← Сотрудники</Link>
                <span class="text-slate-300">/</span>
                <span>{{ person.name }}</span>
            </div>
        </template>

        <PageLayout :title="person.name" :subtitle="roleLabels[person.role] ?? person.role ?? '—'">
            <!-- Шапка профиля — секция §6 -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start gap-4">
                    <Avatar :name="person.name" :src="person.avatar" :size="72" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">{{ person.name }}</h3>
                            <span v-for="dep in person.head_of" :key="dep" class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">⭐ Руководитель — {{ dep }}</span>
                            <span v-if="!person.is_active" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">Отключён</span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ roleLabels[person.role] ?? person.role ?? '—' }}
                            <template v-if="person.department"> · {{ person.department }}</template>
                            <template v-if="person.companies?.length"> · {{ person.companies.join(', ') }}</template>
                        </p>
                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                            <a :href="`mailto:${person.email}`" class="transition-colors hover:text-indigo-600">✉️ {{ person.email }}</a>
                            <a v-if="person.phone" :href="`tel:${person.phone}`" class="transition-colors hover:text-indigo-600">📞 {{ person.phone }}</a>
                            <span v-if="person.birth_date">🎂 {{ fmtDate(person.birth_date) }}</span>
                            <span v-if="person.hired_at">🗓 в компании с {{ fmtDate(person.hired_at) }} ({{ tenure }})</span>
                            <a v-if="person.has_contract" :href="route('users.contract', person.id)" class="font-medium text-indigo-600 hover:underline">📄 Договор</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Показатели — плитки §5 -->
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Сделок</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ stats.deals }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Успешных</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ stats.won }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Заказов в цехе</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-indigo-600">{{ stats.projects }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Открытых задач</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="tasks.some((t) => t.overdue) ? 'text-rose-600' : 'text-slate-800'">{{ stats.tasks }}</div>
                </div>
            </div>

            <!-- ЗП (только руководство и сам сотрудник) — секция §6 -->
            <div v-if="payrollRow" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Зарплата (текущий расчёт)</h3>
                    <!-- Фильтр месяца: корректировки и долг считаются за него,
                         цифры сходятся со стр. Зарплата. -->
                    <label class="flex items-center gap-1.5 text-xs text-slate-400">месяц
                        <input v-model="monthSel" @change="setMonth" type="month"
                            class="rounded-lg border-slate-200 py-1 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                    </label>
                </div>
                <div class="p-6 pt-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Оклад</div>
                            <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ fmt(payrollRow.salary) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Бонус от маржи</div>
                            <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ fmt(payrollRow.bonus) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">К выплате (без корректировок)</div>
                            <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-indigo-600">{{ fmt(payrollRow.payout) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-slate-400">Закрытых сделок</div>
                            <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ payrollRow.closed }}</div>
                        </div>
                    </div>
                    <!-- Долг перед компанией: тот же расчёт и та же таблица, что в
                         ведомости ЗП — гасится фиксированной суммой в месяц и только
                         из бонуса, оклад не трогается. -->
                    <div v-if="debts.length" class="mt-6">
                        <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                            Долг перед компанией
                            <span v-if="debtPlan?.before > 0" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium normal-case tabular-nums tracking-normal text-amber-700">
                                {{ fmt(debtPlan.before) }}
                            </span>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-amber-200">
                            <table class="min-w-full divide-y divide-amber-100 text-xs">
                                <thead class="text-left uppercase tracking-wide text-amber-700/70">
                                    <tr class="bg-amber-50/60">
                                        <th class="px-3 py-2 font-semibold">Долг</th>
                                        <th class="px-3 py-2 text-right font-semibold">В месяц</th>
                                        <th class="px-3 py-2 text-right font-semibold">За {{ monthLabel }}</th>
                                        <th class="px-3 py-2 text-right font-semibold">Погашено</th>
                                        <th class="px-3 py-2 text-right font-semibold">Осталось</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-amber-50">
                                    <tr v-for="d in debts" :key="d.id" class="transition-colors duration-150 hover:bg-amber-50/40" :class="d.closed ? 'opacity-60' : ''">
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-semibold tabular-nums text-slate-800">{{ fmt(d.amount) }}</span>
                                                <span v-if="d.closed" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">закрыт</span>
                                            </div>
                                            <div class="mt-0.5 text-[11px] text-slate-400">
                                                {{ fmtDate(d.date) }}<template v-if="d.note"> · {{ d.note }}</template>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-500">{{ fmt(d.monthly_amount) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums" :class="d.paid_this_month > 0 ? 'font-semibold text-rose-600' : 'text-slate-300'">
                                            {{ d.paid_this_month > 0 ? '− ' + fmt(d.paid_this_month) : '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-emerald-600">{{ fmt(d.paid) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums" :class="d.remaining > 0 ? 'text-amber-700' : 'text-slate-300'">{{ fmt(d.remaining) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-amber-200 bg-amber-50/80">
                                    <tr v-if="debtPlan?.charge > 0">
                                        <td colspan="2" class="px-3 py-2 font-semibold text-amber-700">
                                            {{ debtPlan.planned > 0 ? 'Удержим' : 'Удержано' }} из бонуса за {{ monthLabel }}
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-bold tabular-nums text-rose-600">− {{ fmt(debtPlan.charge) }}</td>
                                        <td class="px-3 py-2 text-right text-[11px] uppercase tracking-wide text-slate-400">останется</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-bold tabular-nums text-amber-700">{{ fmt(debtPlan.after) }}</td>
                                    </tr>
                                    <tr v-else>
                                        <td colspan="5" class="px-3 py-2 text-slate-500">
                                            За {{ monthLabel }} бонуса нет — удержания не будет,
                                            долг <b class="tabular-nums text-amber-700">{{ fmt(debtPlan?.before ?? 0) }}</b> переходит на следующий месяц.
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <p v-if="!debts.length" class="mt-6 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-center text-xs text-slate-400">
                        Долгов перед компанией нет
                    </p>

                    <div class="mt-6 overflow-x-auto">
                        <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Корректировки · {{ monthLabel }}</div>
                        <p v-if="!adjustments.length" class="text-xs text-slate-400">За этот месяц корректировок нет</p>
                        <table v-if="adjustments.length" class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr><th class="py-2 pr-4">Корректировка</th><th class="py-2 pr-4">Дата</th><th class="py-2 pr-4 text-right">Сумма</th><th class="py-2">Заметка</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="a in adjustments" :key="a.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                    <td class="py-2.5 pr-4">
                                        <span :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">{{ adjLabels[a.type] ?? a.type }}</span>
                                        <span v-if="a.days" class="text-slate-400"> ({{ a.days }} дн.)</span>
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 pr-4 text-slate-500">{{ fmtDate(a.date) }}</td>
                                    <td class="whitespace-nowrap py-2.5 pr-4 text-right font-semibold tabular-nums" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-rose-600'">
                                        {{ a.type === 'bonus' ? '+' : '−' }}{{ fmt(a.amount) }}
                                    </td>
                                    <td class="py-2.5 text-slate-500">{{ a.note ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-3 lg:grid-cols-2">
                <!-- Сделки — секция §6 -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="text-sm font-semibold text-slate-900">Сделки</h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ deals.length }}</span>
                    </div>
                    <div class="divide-y divide-slate-50">
                        <Link v-for="d in deals" :key="d.id" :href="route('deals.show', d.id)"
                            class="flex items-center justify-between gap-3 px-6 py-2.5 transition-colors duration-150 hover:bg-slate-50/60">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ d.number }} · {{ d.company_name }}</p>
                                <p class="text-[11px] text-slate-400">
                                    <span :class="d.is_won ? 'font-medium text-emerald-600' : ''">{{ d.stage ?? '—' }}</span>
                                    <template v-if="d.deadline"> · срок {{ fmtDate(d.deadline) }}</template>
                                </p>
                            </div>
                            <span v-if="d.budget !== null" class="shrink-0 whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800">{{ fmt(d.budget) }}</span>
                        </Link>
                        <p v-if="!deals.length" class="px-6 py-10 text-center text-sm text-slate-400">Нет сделок</p>
                    </div>
                </div>

                <!-- Заказы цеха — секция §6 -->
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                        <h3 class="text-sm font-semibold text-slate-900">Заказы цеха</h3>
                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ projects.length }}</span>
                    </div>
                    <div class="divide-y divide-slate-50">
                        <Link v-for="p in projects" :key="p.id" :href="route('projects.show', p.id)"
                            class="block px-6 py-2.5 transition-colors duration-150 hover:bg-slate-50/60">
                            <p class="truncate text-sm font-medium text-slate-900">{{ p.number }} · {{ p.name }}</p>
                            <p class="text-[11px] text-slate-400">
                                <template v-if="p.workshop">{{ p.workshop }} · </template>{{ p.stage ?? '—' }}
                                <span v-if="p.status === 'completed'" class="font-medium text-emerald-600"> · готов</span>
                                <span v-else-if="p.status === 'cancelled'" class="text-slate-400"> · отменён</span>
                                <template v-if="p.deadline"> · срок {{ fmtDate(p.deadline) }}</template>
                            </p>
                        </Link>
                        <p v-if="!projects.length" class="px-6 py-10 text-center text-sm text-slate-400">Нет заказов</p>
                    </div>
                </div>
            </div>

            <!-- Задачи — секция §6 -->
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Задачи</h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-slate-500">{{ tasks.length }}</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between gap-3 px-6 py-2.5 transition-colors duration-150 hover:bg-slate-50/60">
                        <p class="min-w-0 truncate text-sm" :class="t.status === 'done' ? 'text-slate-400 line-through' : 'text-slate-900'">{{ t.title }}</p>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <span v-if="t.due_date" class="whitespace-nowrap" :class="t.overdue ? 'font-semibold text-rose-600' : 'text-slate-400'">{{ t.overdue ? '⚠ ' : '' }}{{ fmtDate(t.due_date) }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="t.status === 'done' ? 'bg-emerald-50 text-emerald-700' : t.overdue ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-500'">
                                {{ taskStatusLabels[t.status] ?? t.status }}
                            </span>
                        </div>
                    </div>
                    <p v-if="!tasks.length" class="px-6 py-10 text-center text-sm text-slate-400">Нет задач</p>
                </div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
