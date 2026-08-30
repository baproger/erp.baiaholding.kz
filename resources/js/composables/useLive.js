import { router } from '@inertiajs/vue3';

// ---- Единый опрос «что у меня изменилось» (правило от 29.08.2026) ----
// Вместо отдельных таймеров чата и уведомлений — один GET /live/version с
// If-None-Match: сервер читает штамп из кеша (0 SQL) и при совпадении
// отвечает 304 без тела. Изменился ключ штампа → подписчики этого ключа
// перезагружают только своё (чат — /chat/state, уведомления — Inertia only).
// Интервал: 30 с; каждый 304 × 1.5 до 120 с; скрытая вкладка — 300 с;
// клик/клавиша/возврат на вкладку — снова 30 с; ошибка сети — 120 с.

const BASE = 30000, MAX_IDLE = 120000, HIDDEN = 300000, ERROR = 120000;
let timer = null, delay = BASE, etag = null, prev = null, started = false;
const subs = { chat: [], notifications: [], tasks: [] };

export const onLive = (key, fn) => {
    if (!subs[key]) return () => {};
    subs[key].push(fn);
    return () => { subs[key] = subs[key].filter((f) => f !== fn); };
};

const schedule = (ms) => { clearTimeout(timer); timer = setTimeout(tick, ms); };
const wake = () => { if (!started) return; if (delay !== BASE) { delay = BASE; schedule(1000); } };

const tick = async () => {
    if (!started) return;
    if (document.hidden) { schedule(HIDDEN); return; }
    try {
        const headers = { Accept: 'application/json' };
        if (etag) headers['If-None-Match'] = etag;
        const res = await fetch(route('live.version'), { headers, credentials: 'same-origin' });
        if (res.status === 304) {
            delay = Math.min(Math.round(delay * 1.5), MAX_IDLE);
        } else if (res.ok) {
            etag = res.headers.get('ETag') || etag;
            const stamp = await res.json();
            if (prev) {
                for (const key of Object.keys(subs)) {
                    if ((stamp[key] ?? 0) !== (prev[key] ?? 0)) subs[key].forEach((fn) => { try { fn(); } catch (e) { /* ignore */ } });
                }
            }
            prev = stamp;
            delay = BASE;
        } else if ([401, 419].includes(res.status)) {
            stopLive(); return; // разлогинен — до следующей загрузки страницы
        } else {
            delay = ERROR;
        }
    } catch (e) {
        delay = ERROR;
    }
    schedule(delay);
};

export const startLive = () => {
    if (started) return;
    started = true;
    // Уведомления: перезагружаем только их проп — без перерисовки страницы.
    onLive('notifications', () => router.reload({ only: ['notifications'], preserveScroll: true, preserveState: true }));
    window.addEventListener('pointerdown', wake);
    window.addEventListener('keydown', wake);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) wake(); });
    schedule(1500);
};

export const stopLive = () => {
    started = false;
    clearTimeout(timer);
    window.removeEventListener('pointerdown', wake);
    window.removeEventListener('keydown', wake);
};
