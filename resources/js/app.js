import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, reactive } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// ---- Лекарство от «белого экрана» (правило от 31.08.2026) ----
// После каждого деплоя старые JS-файлы удаляются. Открытая до деплоя вкладка
// при переходе просит чанк по старому имени → 404 → белый экран, который
// люди «лечили» чисткой кеша. Vite кидает событие vite:preloadError — ловим
// его и молча перезагружаем страницу один раз: браузер получает свежий
// список файлов, пользователь ничего не замечает. Защита от цикла: не чаще
// одного раза в 30 секунд.
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    let last = 0;
    try { last = Number(sessionStorage.getItem('chunk_reload_at') || 0); } catch (e) { /* приватный режим */ }
    if (Date.now() - last < 30000) return; // уже перезагружались — не зацикливаемся
    try { sessionStorage.setItem('chunk_reload_at', String(Date.now())); } catch (e) { /* ignore */ }
    window.location.reload();
});

// Global reactive UI translations. Updated on every Inertia visit so the whole
// app re-renders in the new language when the locale switches.
const i18n = reactive({ map: {} });
router.on('success', (event) => {
    i18n.map = event.detail.page.props.translations || {};
});

// ---- Ошибки браузера → журнал Аудит → Ошибки (правило от 17.09.2026) ----
// Серверный обработчик видит только PHP-исключения; падения Vue/JS случаются
// уже в браузере (белый экран План/Факт) и до сих пор нигде не фиксировались.
// Шлём кратко и без повторов: один и тот же текст — один раз за вкладку,
// не больше 5 сообщений, чтобы не завалить журнал в цикле перерисовки.
const reportedErrors = new Set();
const reportClientError = (name, message, source, stack) => {
    if (!message || reportedErrors.size >= 5) return;
    const key = `${name}|${message}`.slice(0, 300);
    if (reportedErrors.has(key)) return;
    reportedErrors.add(key);
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        // route() приходит из Ziggy; если он ещё не готов — прямой адрес.
        let url = '/client-errors';
        try { url = route('clientErrors.store'); } catch (e) { /* используем адрес по умолчанию */ }
        fetch(url, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
            body: JSON.stringify({
                name: String(name ?? 'Error').slice(0, 100),
                message: String(message).slice(0, 2000),
                source: String(source ?? '').slice(0, 500),
                url: window.location.href.slice(0, 500),
                stack: String(stack ?? '').slice(0, 5000),
            }),
        }).catch(() => {});
    } catch (e) { /* отчёт об ошибке не должен порождать ошибку */ }
};

window.addEventListener('error', (e) => reportClientError(
    e.error?.name, e.message, `${e.filename ?? ''}:${e.lineno ?? ''}`, e.error?.stack));
window.addEventListener('unhandledrejection', (e) => reportClientError(
    'UnhandledRejection', e.reason?.message ?? String(e.reason ?? ''), '', e.reason?.stack));

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        i18n.map = props.initialPage.props.translations || {};
        const app = createApp({ render: () => h(App, props) });
        // Global t() available in every template as $t('key', 'fallback') — no imports needed.
        app.config.globalProperties.$t = (key, fallback = null) => i18n.map[key] ?? fallback ?? key;
        // Падение компонента Vue (как белый экран План/Факт) — в тот же журнал.
        app.config.errorHandler = (err, instance, info) => {
            reportClientError(err?.name ?? 'VueError', `${err?.message ?? err} (${info})`, '', err?.stack);
            console.error(err);
        };
        return app
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
