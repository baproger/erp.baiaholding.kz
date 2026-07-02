<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const perms = computed(() => page.props.auth.user?.permissions ?? []);
const flash = computed(() => page.props.flash || {});
const notifications = computed(() => page.props.notifications || { unread: 0, items: [] });
const locale = computed(() => page.props.locale || 'ru');

const collapsed = ref(false);   // desktop collapse
const mobileOpen = ref(false);  // mobile slide-over

const allNav = [
    { name: 'Дашборд', route: 'dashboard', icon: '▧' },
    { name: 'Аналитика', route: 'analytics.index', icon: '◊', perm: 'report.viewAny' },
    { name: 'Сделки', route: 'deals.index', icon: '◈', perm: 'deal.viewAny' },
    { name: 'Цех', route: 'projects.index', icon: '◇', perm: 'project.viewAny' },
    { name: 'Задачи', route: 'tasks.index', icon: '✓', perm: 'task.viewAny' },
    { name: 'Чат', route: 'chat.index', icon: '✉' },
    { name: 'Финансы', route: 'finance.index', icon: '₸', perm: 'invoice.viewAny' },
    { name: 'Аудит', route: 'audit.index', icon: '❑', perm: 'setting.viewAny' },
    { name: 'Отделы', route: 'departments.index', icon: '⌂', perm: 'department.viewAny' },
    { name: 'Сотрудники', route: 'users.index', icon: '☻', perm: 'user.viewAny' },
    { name: 'Настройки', route: 'settings.index', icon: '⚙', perm: 'setting.update' },
];
const nav = computed(() => allNav.filter((i) => !i.perm || perms.value.includes(i.perm)));

const isActive = (name) => {
    const base = name.split('.')[0];
    return route().current(base + '.*') || route().current(base);
};
const go = () => { mobileOpen.value = false; };

const markRead = (id) => router.patch(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true });
const markAllRead = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const setLocale = (l) => router.patch(route('locale.update'), { locale: l }, { preserveScroll: true });
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <div class="min-h-screen bg-gray-50">
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
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-gray-900 text-gray-300 transition-all duration-300 ease-in-out">
            <div class="flex h-16 items-center gap-2 border-b border-gray-800 px-4">
                <span class="text-xl font-bold text-white">BAIA</span>
                <span v-if="!collapsed" class="text-xs text-gray-400">ERP</span>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-4">
                <Link v-for="item in nav" :key="item.route" :href="route(item.route)" @click="go"
                    :class="isActive(item.route) ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' : 'hover:bg-gray-800 hover:text-white'"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150">
                    <span class="text-lg leading-none">{{ item.icon }}</span>
                    <span v-if="!collapsed || mobileOpen" class="truncate">{{ item.name }}</span>
                </Link>
            </nav>
            <button class="hidden border-t border-gray-800 px-4 py-3 text-left text-xs text-gray-500 hover:text-white lg:block" @click="collapsed = !collapsed">
                {{ collapsed ? '›' : '‹ Свернуть' }}
            </button>
        </aside>

        <!-- Main -->
        <div :class="collapsed ? 'lg:ml-16' : 'lg:ml-60'" class="flex-1 transition-all duration-300">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-white/80 px-4 shadow-sm backdrop-blur-md sm:px-6">
                <div class="flex items-center gap-3">
                    <button class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden" @click="mobileOpen = true">☰</button>
                    <h1 class="text-base font-semibold text-gray-800 sm:text-lg"><slot name="header">Панель управления</slot></h1>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="hidden items-center rounded-lg bg-gray-100 p-0.5 text-xs sm:flex">
                        <button v-for="l in ['ru','kk']" :key="l" @click="setLocale(l)"
                            :class="locale === l ? 'bg-white text-indigo-600 shadow' : 'text-gray-500'"
                            class="rounded px-2 py-1 font-medium uppercase transition-all">{{ l }}</button>
                    </div>

                    <Dropdown align="right" width="60">
                        <template #trigger>
                            <button class="relative rounded-full p-2 text-gray-500 transition-colors hover:bg-gray-100">
                                <span class="text-lg">🔔</span>
                                <span v-if="notifications.unread > 0" class="absolute right-0 top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ notifications.unread }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="flex items-center justify-between border-b px-4 py-2">
                                <span class="text-sm font-semibold text-gray-700">Уведомления</span>
                                <button v-if="notifications.unread > 0" class="text-xs text-indigo-600 hover:underline" @click="markAllRead">Прочитать все</button>
                            </div>
                            <div class="max-h-80 overflow-y-auto">
                                <div v-for="n in notifications.items" :key="n.id" :class="n.read_at ? 'opacity-60' : 'bg-indigo-50/50'"
                                    class="cursor-pointer border-b px-4 py-2 text-sm transition-colors hover:bg-gray-50" @click="markRead(n.id)">
                                    <div class="font-medium text-gray-800">{{ n.data.title }}</div>
                                    <div class="text-xs text-gray-500">{{ n.data.message }}</div>
                                    <div class="text-[10px] text-gray-400">{{ fmt(n.created_at) }}</div>
                                </div>
                                <div v-if="!notifications.items.length" class="px-4 py-6 text-center text-sm text-gray-400">Нет уведомлений</div>
                            </div>
                        </template>
                    </Dropdown>

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button class="flex items-center gap-2 rounded-full bg-gray-50 px-2 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-100 sm:px-3">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">{{ user?.name?.charAt(0) ?? '?' }}</span>
                                <span class="hidden sm:block">{{ user?.name }}</span>
                            </button>
                        </template>
                        <template #content>
                            <div class="border-b px-4 py-2 text-xs text-gray-400">{{ user?.roles?.join(', ') }}</div>
                            <DropdownLink :href="route('profile.edit')">Профиль</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Выйти</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <transition enter-active-class="transition duration-300" enter-from-class="opacity-0 -translate-y-1" enter-to-class="opacity-100">
                <div v-if="flash.success" class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200 sm:mx-6">{{ flash.success }}</div>
            </transition>
            <div v-if="flash.error" class="mx-4 mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200 sm:mx-6">{{ flash.error }}</div>

            <main class="p-4 sm:p-6"><slot /></main>
        </div>
    </div>
</template>
