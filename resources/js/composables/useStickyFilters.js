import { onMounted, watch } from 'vue';

/**
 * Запоминает фильтры страницы: ушёл и вернулся — фильтр на месте.
 *
 * Хранится в localStorage браузера, у каждой страницы свой ключ. Значения —
 * только примитивы и массивы (Set/Map не сериализуются — их не передавайте).
 *
 *   // клиентские фильтры (фильтрует сама страница)
 *   useStickyFilters('users', { search, deptFilter, companyFilter, showInactive });
 *
 *   // серверные фильтры (нужен запрос) — третьим аргументом функция запроса
 *   useStickyFilters('reports.deals', { search, from, to, manager, stageF }, apply);
 */
const NS = 'baia:filters:';

const read = (key) => {
    try {
        const raw = localStorage.getItem(NS + key);
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null; // приватный режим / битое значение — просто не восстанавливаем
    }
};

const write = (key, value) => {
    try {
        localStorage.setItem(NS + key, JSON.stringify(value));
    } catch {
        /* переполнено или запрещено — фильтр просто не запомнится */
    }
};

const snapshot = (refs) => Object.fromEntries(Object.entries(refs).map(([name, r]) => [name, r.value]));

export function useStickyFilters(key, refs, apply = null) {
    const saved = read(key);
    let restored = false;

    if (saved && typeof saved === 'object') {
        for (const [name, r] of Object.entries(refs)) {
            if (!(name in saved) || saved[name] === undefined) continue;
            if (saved[name] === r.value) continue;
            r.value = saved[name];
            restored = true;
        }
    }

    // Серверный фильтр восстановлен — его нужно ещё и запросить. Только если
    // в адресе нет своих параметров: явная ссылка (из письма, из уведомления)
    // важнее запомненного фильтра.
    if (restored && apply && typeof window !== 'undefined' && !window.location.search) {
        onMounted(() => apply());
    }

    watch(() => snapshot(refs), (value) => write(key, value), { deep: true });
}

/** Забыть сохранённый фильтр страницы (кнопка «Сбросить»). */
export function clearStickyFilters(key) {
    try {
        localStorage.removeItem(NS + key);
    } catch {
        /* нечего чистить */
    }
}
