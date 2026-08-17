<script setup>
// Общий каркас страницы (DESIGN.md §1) — как FinanceLayout, но без вкладок:
// контейнер, заголовок + пояснение + слот действий справа, затем содержимое.
// Используется ВНУТРИ AppLayout. Вкладки страницы (если есть) — слот #tabs.
defineProps({
    title: String,
    subtitle: String,
    wide: { type: Boolean, default: true },
    // Полная ширина — для датаёмких страниц (широкие таблицы, канбан):
    // контент занимает весь экран, отступы даёт AppLayout.
    full: { type: Boolean, default: false },
});
</script>

<template>
    <div class="mx-auto" :class="full ? 'max-w-none' : wide ? 'max-w-7xl' : 'max-w-5xl'">
        <slot name="tabs" />

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
