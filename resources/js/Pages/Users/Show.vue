<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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
                <Link :href="route('users.index')" class="text-slate-400 hover:text-slate-600">← Сотрудники</Link>
                <span class="text-slate-300">/</span>
                <span>{{ person.name }}</span>
            </div>
        </template>

        <!-- Шапка профиля -->
        <div class="mb-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start gap-4">
                <Avatar :name="person.name" :src="person.avatar" :size="72" />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900">{{ person.name }}</h1>
                        <span v-for="dep in person.head_of" :key="dep" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200">⭐ Руководитель — {{ dep }}</span>
                        <span v-if="!person.is_active" class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">Отключён</span>
                    </div>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ roleLabels[person.role] ?? person.role ?? '—' }}
                        <template v-if="person.department"> · {{ person.department }}</template>
                        <template v-if="person.companies?.length"> · {{ person.companies.join(', ') }}</template>
                    </p>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                        <a :href="`mailto:${person.email}`" class="hover:text-indigo-600">✉️ {{ person.email }}</a>
                        <a v-if="person.phone" :href="`tel:${person.phone}`" class="hover:text-indigo-600">📞 {{ person.phone }}</a>
                        <span v-if="person.birth_date">🎂 {{ fmtDate(person.birth_date) }}</span>
                        <span v-if="person.hired_at">🗓 в компании с {{ fmtDate(person.hired_at) }} ({{ tenure }})</span>
                        <a v-if="person.has_contract" :href="route('users.contract', person.id)" class="text-indigo-600 hover:underline">📄 Договор</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Показатели -->
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-slate-900">{{ stats.deals }}</p>
                <p class="text-xs text-slate-500">Сделок</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-emerald-600">{{ stats.won }}</p>
                <p class="text-xs text-slate-500">Успешных</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold text-indigo-600">{{ stats.projects }}</p>
                <p class="text-xs text-slate-500">Заказов в цехе</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-2xl font-bold" :class="tasks.some((t) => t.overdue) ? 'text-red-500' : 'text-slate-900'">{{ stats.tasks }}</p>
                <p class="text-xs text-slate-500">Открытых задач</p>
            </div>
        </div>

        <!-- ЗП (только руководство и сам сотрудник) -->
        <div v-if="payrollRow" class="mb-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Зарплата (текущий расчёт)</h3>
                <!-- Фильтр месяца: корректировки и долг считаются за него,
                     цифры сходятся со стр. Зарплата. -->
                <label class="flex items-center gap-1.5 text-xs text-slate-400">месяц
                    <input v-model="monthSel" @change="setMonth" type="month"
                        class="rounded-lg border-slate-200 py-1 text-xs shadow-sm" />
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><p class="text-lg font-bold text-slate-900">{{ fmt(payrollRow.salary) }}</p><p class="text-xs text-slate-500">Оклад</p></div>
                <div><p class="text-lg font-bold text-emerald-600">{{ fmt(payrollRow.bonus) }}</p><p class="text-xs text-slate-500">Бонус от маржи</p></div>
                <div><p class="text-lg font-bold text-indigo-600">{{ fmt(payrollRow.payout) }}</p><p class="text-xs text-slate-500">К выплате (без корректировок)</p></div>
                <div><p class="text-lg font-bold text-slate-900">{{ payrollRow.closed }}</p><p class="text-xs text-slate-500">Закрытых сделок</p></div>
            </div>
            <!-- Долг перед компанией: тот же расчёт и та же таблица, что в
                 ведомости ЗП — гасится фиксированной суммой в месяц и только
                 из бонуса, оклад не трогается. -->
            <div v-if="debts.length" class="mt-4">
                <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                    Долг перед компанией
                    <span v-if="debtPlan?.before > 0" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold normal-case tracking-normal text-amber-800">
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
                            <tr v-for="d in debts" :key="d.id" :class="d.closed ? 'opacity-60' : ''">
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-semibold tabular-nums text-slate-800">{{ fmt(d.amount) }}</span>
                                        <span v-if="d.closed" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">закрыт</span>
                                    </div>
                                    <div class="mt-0.5 text-[11px] text-slate-400">
                                        {{ fmtDate(d.date) }}<template v-if="d.note"> · {{ d.note }}</template>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ fmt(d.monthly_amount) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums" :class="d.paid_this_month > 0 ? 'font-semibold text-red-500' : 'text-slate-300'">
                                    {{ d.paid_this_month > 0 ? '− ' + fmt(d.paid_this_month) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ fmt(d.paid) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums" :class="d.remaining > 0 ? 'text-amber-700' : 'text-slate-300'">{{ fmt(d.remaining) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 border-amber-200 bg-amber-50/80">
                            <tr v-if="debtPlan?.charge > 0">
                                <td colspan="2" class="px-3 py-2 font-semibold text-amber-800">
                                    {{ debtPlan.planned > 0 ? 'Удержим' : 'Удержано' }} из бонуса за {{ monthLabel }}
                                </td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-red-500">− {{ fmt(debtPlan.charge) }}</td>
                                <td class="px-3 py-2 text-right text-[11px] uppercase tracking-wide text-slate-400">останется</td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-amber-800">{{ fmt(debtPlan.after) }}</td>
                            </tr>
                            <tr v-else>
                                <td colspan="5" class="px-3 py-2 text-slate-500">
                                    За {{ monthLabel }} бонуса нет — удержания не будет,
                                    долг <b class="tabular-nums text-amber-800">{{ fmt(debtPlan?.before ?? 0) }}</b> переходит на следующий месяц.
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <p v-if="!debts.length" class="mt-4 rounded-lg border border-dashed border-amber-200 bg-amber-50/30 px-3 py-2 text-xs text-slate-400">
                Долгов перед компанией нет
            </p>

            <div class="mt-4 overflow-x-auto">
                <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Корректировки · {{ monthLabel }}</div>
                <p v-if="!adjustments.length" class="text-xs text-slate-400">За этот месяц корректировок нет</p>
                <table v-if="adjustments.length" class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400">
                        <tr><th class="py-1 pr-4">Корректировка</th><th class="py-1 pr-4">Дата</th><th class="py-1 pr-4 text-right">Сумма</th><th class="py-1">Заметка</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="a in adjustments" :key="a.id">
                            <td class="py-1.5 pr-4">
                                <span :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-red-500'">{{ adjLabels[a.type] ?? a.type }}</span>
                                <span v-if="a.days" class="text-slate-400"> ({{ a.days }} дн.)</span>
                            </td>
                            <td class="py-1.5 pr-4 text-slate-500">{{ fmtDate(a.date) }}</td>
                            <td class="py-1.5 pr-4 text-right font-medium" :class="a.type === 'bonus' ? 'text-emerald-600' : 'text-red-500'">
                                {{ a.type === 'bonus' ? '+' : '−' }}{{ fmt(a.amount) }}
                            </td>
                            <td class="py-1.5 text-slate-500">{{ a.note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Сделки -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Сделки ({{ deals.length }})</h3>
                <div class="divide-y divide-slate-100">
                    <Link v-for="d in deals" :key="d.id" :href="route('deals.show', d.id)"
                        class="flex items-center justify-between gap-3 py-2 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ d.number }} · {{ d.company_name }}</p>
                            <p class="text-xs text-slate-500">
                                <span :class="d.is_won ? 'text-emerald-600 font-semibold' : ''">{{ d.stage ?? '—' }}</span>
                                <template v-if="d.deadline"> · срок {{ fmtDate(d.deadline) }}</template>
                            </p>
                        </div>
                        <span v-if="d.budget !== null" class="shrink-0 text-sm font-semibold text-slate-700">{{ fmt(d.budget) }}</span>
                    </Link>
                    <p v-if="!deals.length" class="py-6 text-center text-sm text-slate-400">Нет сделок</p>
                </div>
            </div>

            <!-- Заказы цеха -->
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Заказы цеха ({{ projects.length }})</h3>
                <div class="divide-y divide-slate-100">
                    <Link v-for="p in projects" :key="p.id" :href="route('projects.show', p.id)"
                        class="block py-2 hover:bg-slate-50">
                        <p class="truncate text-sm font-medium text-slate-900">{{ p.number }} · {{ p.name }}</p>
                        <p class="text-xs text-slate-500">
                            <template v-if="p.workshop">{{ p.workshop }} · </template>{{ p.stage ?? '—' }}
                            <span v-if="p.status === 'completed'" class="font-semibold text-emerald-600"> · готов</span>
                            <span v-else-if="p.status === 'cancelled'" class="text-slate-400"> · отменён</span>
                            <template v-if="p.deadline"> · срок {{ fmtDate(p.deadline) }}</template>
                        </p>
                    </Link>
                    <p v-if="!projects.length" class="py-6 text-center text-sm text-slate-400">Нет заказов</p>
                </div>
            </div>
        </div>

        <!-- Задачи -->
        <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">Задачи ({{ tasks.length }})</h3>
            <div class="divide-y divide-slate-100">
                <div v-for="t in tasks" :key="t.id" class="flex items-center justify-between gap-3 py-2">
                    <p class="min-w-0 truncate text-sm" :class="t.status === 'done' ? 'text-slate-400 line-through' : 'text-slate-900'">{{ t.title }}</p>
                    <div class="flex shrink-0 items-center gap-3 text-xs">
                        <span v-if="t.due_date" :class="t.overdue ? 'font-semibold text-red-500' : 'text-slate-400'">{{ t.overdue ? '⚠ ' : '' }}{{ fmtDate(t.due_date) }}</span>
                        <span class="rounded-full px-2 py-0.5 font-semibold"
                            :class="t.status === 'done' ? 'bg-emerald-50 text-emerald-600' : t.overdue ? 'bg-red-50 text-red-500' : 'bg-slate-100 text-slate-500'">
                            {{ taskStatusLabels[t.status] ?? t.status }}
                        </span>
                    </div>
                </div>
                <p v-if="!tasks.length" class="py-6 text-center text-sm text-slate-400">Нет задач</p>
            </div>
        </div>
    </AppLayout>
</template>
