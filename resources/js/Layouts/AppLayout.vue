<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import SkeletonScreen from '@/Components/SkeletonScreen.vue';
import { useT } from '@/composables/useTranslations';

const t = useT();

const page = usePage();
const user = computed(() => page.props.auth.user);
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const roles = computed(() => page.props.auth.user?.roles ?? []);
const isLeadership = computed(() => roles.value.some((r) => ['admin', 'director', 'financist'].includes(r)));
const flash = computed(() => page.props.flash || {});
const notifications = computed(() => page.props.notifications || { unread: 0, items: [] });
const locale = computed(() => page.props.locale || 'ru');

const collapsed = ref(false);   // desktop collapse
const mobileOpen = ref(false);  // mobile slide-over

// Global navigation loading bar + delayed skeleton (only on slow loads).
const loading = ref(false);
const showSkeleton = ref(false);
let skeletonTimer = null;
router.on('start', () => {
    loading.value = true;
    skeletonTimer = setTimeout(() => (showSkeleton.value = true), 300);
});
router.on('finish', () => {
    loading.value = false;
    clearTimeout(skeletonTimer);
    showSkeleton.value = false;
});

const allNav = [
    { key: 'nav.dashboard', name: 'Дашборд', route: 'dashboard', icon: '▧', leadershipOnly: true },
    { key: 'nav.analytics', name: 'Аналитика', route: 'analytics.index', icon: '◊', perm: 'report.viewAny' },
    { key: 'nav.deals', name: 'Сделки', route: 'deals.index', icon: '◈', perm: 'deal.viewAny' },
    { key: 'nav.overdue', name: 'Просроченные', route: 'deals.overdue', icon: '⏰', perm: 'deal.viewAny' },
    { key: 'nav.workshop', name: 'Цех', route: 'projects.index', icon: '◇', perm: 'project.viewAny' },
    { key: 'nav.chat', name: 'Чат', route: 'chat.index', icon: '✉' },
    { key: 'nav.profile', name: 'Профиль', route: 'profile.edit', icon: '🪪' },
    { key: 'nav.finance', name: 'Финансы', route: 'finance.index', icon: '₸', perm: 'invoice.viewAny', leadershipOnly: true },
    { key: 'nav.payroll', name: 'Зарплата', route: 'payroll.index', icon: '💵', perm: 'payroll.view' },
    { key: 'nav.audit', name: 'Аудит', route: 'audit.index', icon: '❑', perm: 'setting.viewAny' },
    { key: 'nav.departments', name: 'Отделы', route: 'departments.index', icon: '⌂', perm: 'department.viewAny' },
    { key: 'nav.users', name: 'Сотрудники', route: 'users.index', icon: '☻', perm: 'user.viewAny' },
    { key: 'nav.settings', name: 'Настройки', route: 'settings.index', icon: '⚙', perm: 'setting.update' },
    { key: 'nav.translations', name: 'Переводы', route: 'translations.index', icon: '🌐', perm: 'setting.update' },
];
const nav = computed(() => allNav.filter((i) => (!i.perm || perms.value.includes(i.perm)) && (!i.leadershipOnly || isLeadership.value)));

const isActive = (name) => {
    const base = name.split('.')[0];
    return route().current(base + '.*') || route().current(base);
};
const go = () => { mobileOpen.value = false; };

const markRead = (id) => router.patch(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true });
const markAllRead = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const setLocale = (l) => router.patch(route('locale.update'), { locale: l }, { preserveScroll: true });
const fmt = (t) => new Date(t).toLocaleString('ru-RU');

const roleLabels = { admin: 'Администратор', director: 'Директор', financist: 'Финансист', manager: 'Менеджер', employee: 'Сотрудник' };
const roleLabel = computed(() => roleLabels[roles.value[0]] ?? roles.value[0] ?? '');

// Live clock next to the language switcher.
const now = ref(new Date());
let clockTimer = null;
onMounted(() => { clockTimer = setInterval(() => (now.value = new Date()), 1000); });
onUnmounted(() => clearInterval(clockTimer));
const clockTime = computed(() => now.value.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const clockDate = computed(() => now.value.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' }));
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- Mobile backdrop -->
        <transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-200" leave-to-class="opacity-0">
            <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="mobileOpen = false"></div>
        </transition>

        <!-- Sidebar -->
        <aside
            :class="[
                collapsed ? 'lg:w-16' : 'lg:w-60',
                mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-slate-900 text-slate-300 transition-all duration-300 ease-in-out">
            <div class="flex h-16 items-center gap-2.5 px-4">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl shadow-lg shadow-black/30">
                    <img src="/logobaiagolding.jpg" alt="BAIA Holding" class="h-full w-full object-cover" />
                </span>
                <div v-if="!collapsed || mobileOpen" class="leading-tight">
                    <div class="text-sm font-semibold tracking-tight text-white">BAIA Holding</div>
                    <div class="text-[10px] font-medium uppercase tracking-widest text-slate-500">ERP · CRM</div>
                </div>
            </div>
            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-3">
                <Link v-for="item in nav" :key="item.route" :href="route(item.route)" @click="go"
                    :title="collapsed ? t(item.key, item.name) : ''"
                    :class="isActive(item.route) ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'"
                    class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors">
                    <span v-if="isActive(item.route)" class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-indigo-500"></span>
                    <span class="text-lg leading-none" :class="isActive(item.route) ? 'text-indigo-400' : 'text-slate-500 group-hover:text-slate-300'">{{ item.icon }}</span>
                    <span v-if="!collapsed || mobileOpen" class="truncate">{{ t(item.key, item.name) }}</span>
                </Link>
            </nav>
            <!-- User block -->
            <Link :href="route('profile.edit')" @click="go"
                class="flex items-center gap-2.5 border-t border-white/5 px-3 py-3 transition-colors hover:bg-white/5">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-xs font-bold text-white">
                    <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" alt="" />
                    <template v-else>{{ user?.name?.charAt(0) ?? '?' }}</template>
                </span>
                <div v-if="!collapsed || mobileOpen" class="min-w-0 leading-tight">
                    <div class="truncate text-xs font-semibold text-white">{{ user?.name }}</div>
                    <div class="truncate text-[10px] text-slate-500">{{ roleLabel }}</div>
                </div>
            </Link>
            <button class="hidden items-center gap-2 border-t border-white/5 px-4 py-2.5 text-left text-xs font-medium text-slate-500 transition-colors hover:text-white lg:flex" @click="collapsed = !collapsed">
                <span>{{ collapsed ? '»' : '«' }}</span><span v-if="!collapsed">{{ t('header.collapse', 'Свернуть') }}</span>
            </button>
        </aside>

        <!-- Main -->
        <div :class="collapsed ? 'lg:ml-16' : 'lg:ml-60'" class="flex-1 transition-all duration-300">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-white/80 px-4 shadow-sm backdrop-blur-md sm:px-6">
                <div class="flex items-center gap-3">
                    <button class="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="mobileOpen = true">☰</button>
                    <h1 class="text-base font-semibold text-slate-800 sm:text-lg"><slot name="header">{{ t('header.title', 'Панель управления') }}</slot></h1>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <!-- Live date & time -->
                    <div class="hidden items-center gap-2 rounded-lg bg-slate-100 px-3 py-1.5 text-xs md:flex">
                        <svg viewBox="0 0 24 24" class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span class="font-semibold tabular-nums text-slate-700">{{ clockTime }}</span>
                        <span class="text-slate-300">·</span>
                        <span class="tabular-nums text-slate-500">{{ clockDate }}</span>
                    </div>

                    <div class="hidden items-center rounded-lg bg-slate-100 p-0.5 text-xs sm:flex">
                        <button v-for="l in ['ru','kk']" :key="l" @click="setLocale(l)"
                            :class="locale === l ? 'bg-white text-indigo-600 shadow' : 'text-slate-500'"
                            class="rounded px-2 py-1 font-medium uppercase transition-all">{{ l }}</button>
                    </div>

                    <Dropdown align="right" width="60">
                        <template #trigger>
                            <button class="relative rounded-full p-2 text-slate-500 transition-colors hover:bg-slate-100">
                                <span class="text-lg">🔔</span>
                                <span v-if="notifications.unread > 0" class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ notifications.unread }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="flex items-center justify-between border-b px-4 py-2">
                                <span class="text-sm font-semibold text-slate-700">{{ t('header.notifications', 'Уведомления') }}</span>
                                <button v-if="notifications.unread > 0" class="text-xs text-indigo-600 hover:underline" @click="markAllRead">{{ t('header.read_all', 'Прочитать все') }}</button>
                            </div>
                            <div class="max-h-80 overflow-y-auto">
                                <div v-for="n in notifications.items" :key="n.id" :class="n.read_at ? 'opacity-60' : 'bg-indigo-50/50'"
                                    class="cursor-pointer border-b px-4 py-2 text-sm transition-colors hover:bg-slate-50" @click="markRead(n.id)">
                                    <div class="font-medium text-slate-800">{{ n.data.title }}</div>
                                    <div class="text-xs text-slate-500">{{ n.data.message }}</div>
                                    <div class="text-[10px] text-slate-400">{{ fmt(n.created_at) }}</div>
                                </div>
                                <div v-if="!notifications.items.length" class="px-4 py-6 text-center text-sm text-slate-400">{{ t('header.no_notifications', 'Нет уведомлений') }}</div>
                            </div>
                        </template>
                    </Dropdown>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-full bg-slate-50 px-2 py-1.5 text-sm text-slate-700 transition-colors hover:bg-slate-100 sm:px-3">
                                <span class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full bg-indigo-600 text-xs font-bold text-white">
                                    <img v-if="user?.avatar" :src="user.avatar" class="h-full w-full object-cover" alt="" />
                                    <template v-else>{{ user?.name?.charAt(0) ?? '?' }}</template>
                                </span>
                                <span class="hidden sm:block">{{ user?.name }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b px-4 py-2 text-xs text-slate-400">{{ user?.roles?.join(', ') }}</div>
                            <DropdownLink :href="route('profile.edit')">{{ t('header.profile', 'Профиль') }}</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">{{ t('header.logout', 'Выйти') }}</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <transition enter-active-class="transition duration-300" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100">
                <div v-if="flash.success" class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200 sm:mx-6">{{ flash.success }}</div>
            </transition>
            <div v-if="flash.error" class="mx-4 mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200 sm:mx-6">{{ flash.error }}</div>

            <div v-show="loading" class="pointer-events-none fixed inset-x-0 top-0 z-[60] h-0.5 overflow-hidden bg-indigo-100">
                <div class="loadbar h-full w-2/5 bg-indigo-600"></div>
            </div>
            <main class="page-enter p-4 sm:p-6">
                <SkeletonScreen v-if="showSkeleton" />
                <slot v-else />
            </main>
        </div>

        <ConfirmModal />
    </div>
</template>

<style scoped>
.page-enter {
    animation: pageIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes pageIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}
.loadbar {
    animation: loadbar 1s ease-in-out infinite;
}
@keyframes loadbar {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(350%); }
}
</style>
