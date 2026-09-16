<script setup>
// Единая кнопка «Сбросить фильтры» (правило от 13.09.2026): красное
// полупрозрачное «стекло» с иконкой ✕ — одинаковая на всех страницах.
// Обработчик клика и v-if вешаются снаружи (атрибуты наследуются корнем).
</script>

<template>
    <button type="button"
        class="inline-flex items-center gap-1.5 rounded-full border border-rose-200/70 bg-gradient-to-r from-rose-50/90 via-rose-100/70 to-rose-200/50 px-3 py-1.5 text-xs font-semibold text-rose-600 shadow-sm backdrop-blur transition-all duration-150 hover:border-rose-300 hover:from-rose-100 hover:to-rose-200/80 hover:text-rose-700 hover:shadow active:scale-95">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        Сбросить
    </button>
</template>
