<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash || {});
const notifications = computed(() => page.props.notifications || { unread: 0, items: [] });
const locale = computed(() => page.props.locale || 'ru');
const setLocale = (l) => router.patch(route('locale.update'), { locale: l }, { preserveScroll: true });

const sidebarOpen = ref(true);

const nav = [
    { name: 'Дашборд', route: 'dashboard', icon: '▧' },
    { name: 'Сделки', route: 'deals.index', icon: '◈' },
    { name: 'Проекты', route: 'projects.index', icon: '◇' },
    { name: 'Задачи', route: 'tasks.index', icon: '✓' },
    { name: 'Финансы', route: 'finance.index', icon: '₸' },
    { name: 'Аудит', route: 'audit.index', icon: '❑' },
    { name: 'Контрагенты', route: 'clients.index', icon: '☺' },
    { name: 'Номенклатура', route: 'products.index', icon: '⛃' },
    { name: 'Отделы', route: 'departments.index', icon: '⌂' },
    { name: 'Настройки', route: 'settings.index', icon: '⚙' },
];

const isActive = (name) => {
    const base = name.split('.')[0];
    return route().current(base + '.*') || route().current(base);
};

const markRead = (id) => router.patch(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true });
const markAllRead = () => router.patch(route('notifications.readAll'), {}, { preserveScroll: true });
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <div class="flex">
            <aside :class="sidebarOpen ? 'w-60' : 'w-16'"
                class="fixed inset-y-0 left-0 z-20 flex flex-col bg-gray-900 text-gray-300 transition-all duration-200">
                <div class="flex h-16 items-center gap-2 px-4 border-b border-gray-800">
                    <span class="text-xl font-bold text-white">BAIA</span>
                    <span v-if="sidebarOpen" class="text-xs text-gray-400">ERP</span>
                </div>
                <nav class="flex-1 space-y-1 px-2 py-4 overflow-y-auto">
                    <Link v-for="item in nav" :key="item.route" :href="route(item.route)"
                        :class="isActive(item.route) ? 'bg-indigo-600 text-white' : 'hover:bg-gray-800 hover:text-white'"
                        class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition">
                        <span class="text-lg leading-none">{{ item.icon }}</span>
                        <span v-if="sidebarOpen">{{ item.name }}</span>
                    </Link>
                </nav>
                <button class="border-t border-gray-800 px-4 py-3 text-left text-xs text-gray-500 hover:text-white"
                    @click="sidebarOpen = !sidebarOpen">
                    {{ sidebarOpen ? '‹ Свернуть' : '›' }}
                </button>
            </aside>

            <div :class="sidebarOpen ? 'ml-60' : 'ml-16'" class="flex-1 transition-all duration-200">
                <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b bg-white px-6 shadow-sm">
                    <h1 class="text-lg font-semibold text-gray-800"><slot name="header">Панель управления</slot></h1>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center rounded-md bg-gray-100 p-0.5 text-xs">
                            <button v-for="l in ['ru','kk']" :key="l" @click="setLocale(l)"
                                :class="locale === l ? 'bg-white shadow text-indigo-600' : 'text-gray-500'"
                                class="rounded px-2 py-1 font-medium uppercase">{{ l }}</button>
                        </div>
                        <!-- Notifications bell -->
                        <Dropdown align="right" width="60">
                            <template #trigger>
                                <button class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100">
                                    <span class="text-lg">🔔</span>
                                    <span v-if="notifications.unread > 0"
                                        class="absolute -right-0 -top-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                                        {{ notifications.unread }}
                                    </span>
                                </button>
                            </template>
                            <template #content>
                                <div class="flex items-center justify-between border-b px-4 py-2">
                                    <span class="text-sm font-semibold text-gray-700">Уведомления</span>
                                    <button v-if="notifications.unread > 0" class="text-xs text-indigo-600 hover:underline" @click="markAllRead">Прочитать все</button>
                                </div>
                                <div class="max-h-80 overflow-y-auto">
                                    <div v-for="n in notifications.items" :key="n.id"
                                        :class="n.read_at ? 'opacity-60' : 'bg-indigo-50/50'"
                                        class="cursor-pointer border-b px-4 py-2 text-sm hover:bg-gray-50" @click="markRead(n.id)">
                                        <div class="font-medium text-gray-800">{{ n.data.title }}</div>
                                        <div class="text-xs text-gray-500">{{ n.data.message }}</div>
                                        <div class="text-[10px] text-gray-400">{{ fmt(n.created_at) }}</div>
                                    </div>
                                    <div v-if="!notifications.items.length" class="px-4 py-6 text-center text-sm text-gray-400">Нет уведомлений</div>
                                </div>
                            </template>
                        </Dropdown>

                        <!-- User menu -->
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button class="flex items-center gap-2 rounded-full bg-gray-50 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
                                        {{ user?.name?.charAt(0) ?? '?' }}
                                    </span>
                                    <span class="hidden sm:block">{{ user?.name }}</span>
                                </button>
                            </template>
                            <template #content>
                                <div class="px-4 py-2 text-xs text-gray-400 border-b">{{ user?.roles?.join(', ') }}</div>
                                <DropdownLink :href="route('profile.edit')">Профиль</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">Выйти</DropdownLink>
                            </template>
                        </Dropdown>
                    </div>
                </header>

                <div v-if="flash.success" class="mx-6 mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-200">{{ flash.success }}</div>
                <div v-if="flash.error" class="mx-6 mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">{{ flash.error }}</div>

                <main class="p-6"><slot /></main>
            </div>
        </div>
    </div>
</template>
