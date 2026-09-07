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
        return app
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
