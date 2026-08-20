<script setup>
// Презентационная обёртка денежных страниц (DESIGN.md §1).
// Используется ВНУТРИ AppLayout: контейнер, вкладки раздела «Финансы»,
// заголовок + пояснение + слот действий, затем содержимое.
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    title: String,
    subtitle: String,
    active: String, // имя роута активной вкладки
    wide: { type: Boolean, default: true },
});

const page = usePage();
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const roles = computed(() => page.props.auth.user?.roles ?? []);
const isLeadership = computed(() => roles.value.some((r) => ['admin', 'director', 'financist'].includes(r)));

// Пункты и проверки прав — ТОЧНО как в группе «Финансы» AppLayout.
const allTabs = [
    { name: 'Обзор', route: 'finance.index', perm: 'invoice.viewAny', leadershipOnly: true },
    { name: 'Касса', route: 'cashBook.index', roles: ['admin', 'director', 'financist'] },
    { name: 'Расходы', route: 'expenses.board', roles: ['admin', 'director', 'financist'] },
    { name: 'Мои расходы', route: 'myExpenses.index', perm: 'expense.create', hideForRoles: ['admin', 'financist'] },
    { name: 'Зарплата', route: 'payroll.index', perm: 'payroll.view' },
    { name: 'Бонусы', route: 'payroll.bonuses', perm: 'payroll.view' },
];
const visible = (i) => (!i.perm || perms.value.includes(i.perm))
    && (!i.leadershipOnly || isLeadership.value)
    && (!i.roles || i.roles.some((r) => roles.value.includes(r)))
    && (!i.hideForRoles || !i.hideForRoles.some((r) => roles.value.includes(r)));
const tabs = computed(() => allTabs.filter(visible));
</script>

<template>
    <div class="mx-auto" :class="wide ? 'max-w-7xl' : 'max-w-5xl'">
        <!-- Вкладки раздела -->
        <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
            <Link v-for="tab in tabs" :key="tab.route" :href="route(tab.route)"
                class="whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-150"
                :class="active === tab.route
                    ? 'border-indigo-600 text-indigo-700'
                    : 'border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'">
                {{ tab.name }}
            </Link>
        </nav>

        <!-- Заголовок и действия -->
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-slate-400">{{ subtitle }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <slot name="actions" />
            </div>
        </div>

        <slot />
    </div>
</template>
